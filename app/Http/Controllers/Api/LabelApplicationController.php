<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelApplication;
use App\Models\LabelProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\ArtistProfile;
use App\Models\PointTransaction;

class LabelApplicationController extends Controller
{
   
public function store(Request $request, LabelProfile $labelProfile)
{
    $user = $request->user();

    // Only artists can apply.
    if (!$user || $user->role !== 'artist') {
        return response()->json([
            'message' => 'Only artist accounts can apply to labels.',
        ], 403);
    }

    $artistProfile = $user->artistProfile;

    if (!$artistProfile) {
        return response()->json([
            'message' => 'Create your artist profile before applying.',
        ], 422);
    }

    // Only approved labels can receive applications.
    $labelProfile->loadMissing('user');

    if ($labelProfile->user?->label_status !== 'approved') {
        return response()->json([
            'message' => 'You can only apply to approved labels.',
        ], 422);
    }

    $validated = $request->validate([
        'message' => ['nullable', 'string', 'max:3000'],
    ]);

    // Keep the application and points changes atomic.
    $result = DB::transaction(function () use (
        $artistProfile,
        $labelProfile,
        $validated
    ) {
        // Lock the artist profile while checking and spending points.
        $artist = ArtistProfile::whereKey($artistProfile->id)
            ->lockForUpdate()
            ->firstOrFail();

        $applications = LabelApplication::query()
            ->where('artist_profile_id', $artist->id)
            ->where('label_profile_id', $labelProfile->id)
            ->latest()
            ->get();

        // Prevent duplicate active applications or invitations.
        $hasActiveApplication = $applications->contains(
            fn (LabelApplication $application) =>
                in_array($application->status, ['pending', 'invited'], true)
        );

        if ($hasActiveApplication) {
            return [
                'error' => response()->json([
                    'message' => 'You already have an active application or invitation with this label.',
                ], 409),
            ];
        }

        // Enforce the 30-day cooldown after rejection.
        $latestRejection = $applications
            ->where('status', 'rejected')
            ->first();

        if ($latestRejection && $latestRejection->rejected_at) {
            $reapplyAt = $latestRejection->rejected_at
                ->copy()
                ->addDays(30);

            if (now()->lt($reapplyAt)) {
                return [
                    'error' => response()->json([
                        'message' => 'You can reapply to this label after the 30-day waiting period.',
                        'reapply_at' => $reapplyAt->toIso8601String(),
                    ], 422),
                ];
            }
        }

        
/* Count applications successfully submitted this calendar month. */
$monthStart = now()->startOfMonth();
$nextMonth = $monthStart->copy()->addMonth();


$monthlyApplications = LabelApplication::query()
    ->where('artist_profile_id', $artist->id)
    ->where('source', 'artist_application')
    ->where('created_at', '>=', $monthStart)
    ->where('created_at', '<', $nextMonth)
    ->count();


// The first five submissions each month are free.
$requiresPoints = $monthlyApplications >= 5;
$applicationFee = 10;

if ($requiresPoints && $artist->points_balance < $applicationFee) {
    return [
        'error' => response()->json([
            'message' => 'You have used your five free applications this month. You need 10 points to apply again.',
            'monthly_free_limit' => 5,
            'monthly_applications_used' => $monthlyApplications,
            'points_required' => $applicationFee,
            'points_balance' => $artist->points_balance,
        ], 422),
    ];
}


        // Create the application only after all checks pass.
        $application = LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $labelProfile->id,
            'message' => $validated['message'] ?? null,
            'status' => 'pending',
            'source' => 'artist_application',
            'used_points' => $requiresPoints,
        ]);

        if ($requiresPoints) {
            $artist->decrement('points_balance', $applicationFee);
            $artist->refresh();

            // Record the points deduction for the audit trail.
            PointTransaction::create([
                'artist_profile_id' => $artist->id,
                'type' => 'application_fee',
                'points' => $applicationFee,
                'balance_after' => $artist->points_balance,
                'label_application_id' => $application->id,
                'description' => '10 points used to apply to a label',
            ]);
        }

        return [
            'application' => $application,
            'points_balance' => $artist->points_balance,
            'points_charged' => $requiresPoints ? $applicationFee : 0,
            'monthly_applications_used' => $monthlyApplications + 1,
        ];
    });

    if (isset($result['error'])) {
        return $result['error'];
    }

    return response()->json([
        'message' => 'Application successfully submitted.',
        'application' => $result['application'],
        'points_charged' => $result['points_charged'],
        'points_balance' => $result['points_balance'],
        'monthly_applications_used' => $result['monthly_applications_used'],
        'monthly_free_limit' => 5,
    ], 201);
}

}
