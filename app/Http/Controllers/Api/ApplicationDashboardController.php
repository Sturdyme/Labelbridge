<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApplicationDashboardController extends Controller
{
    
public function labelDashboard(Request $request)
{
    $user = $request->user();

    if (!$user || $user->role !== 'label') {
        return response()->json([
            'message' => 'Only label accounts can access this dashboard.',
        ], 403);
    }

    $labelProfile = $user->labelProfile;

    if (!$labelProfile) {
        return response()->json([
            'message' => 'Create your label profile first.',
        ], 422);
    }

    // Get applications submitted by artists to this label.
    $applications = LabelApplication::query()
        ->where('label_profile_id', $labelProfile->id)
        ->where('source', 'artist_application')
        ->with([
            'artistProfile:id,user_id,stage_name,bio,state,profile_photo,creative_roles',
        ])
        ->latest()
        ->paginate(10);

    // Count applications by status.
    $counts = LabelApplication::query()
        ->where('label_profile_id', $labelProfile->id)
        ->where('source', 'artist_application')
        ->selectRaw('status, COUNT(*) as total')
        ->groupBy('status')
        ->pluck('total', 'status');

    return response()->json([
        'label' => [
            'id' => $labelProfile->id,
            'label_name' => $labelProfile->label_name,
            'logo' => $labelProfile->logo,
        ],

        'application_counts' => [
            'pending' => (int) ($counts['pending'] ?? 0),
            'accepted' => (int) ($counts['accepted'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
        ],

        'applications' => $applications,
    ]);
}


    //Dashboard for an artist.

    
public function artistDashboard(Request $request)
{
    $user = $request->user();

    if (!$user || $user->role !== 'artist') {
        return response()->json([
            'message' => 'Only artist accounts can access this dashboard.',
        ], 403);
    }

    $artistProfile = $user->artistProfile;

    if (!$artistProfile) {
        return response()->json([
            'message' => 'Create your artist profile first.',
        ], 422);
    }

    // Applications submitted by this artist
    $applications = LabelApplication::query()
        ->where('artist_profile_id', $artistProfile->id)
        ->where('source', 'artist_application')
        ->with([
            'labelProfile:id,user_id,label_name,bio,logo,preferred_genres',
        ])
        ->latest()
        ->paginate(10, ['*'], 'applications_page');

    // Invitations sent to this artist by labels
    $invitations = LabelApplication::query()
        ->where('artist_profile_id', $artistProfile->id)
        ->where('source', 'label_invitation')
        ->with([
            'labelProfile:id,user_id,label_name,bio,logo,preferred_genres',
        ])
        ->latest()
        ->paginate(10, ['*'], 'invitations_page');

    // Count only applications submitted by the artist, not invitations
    $applicationCounts = LabelApplication::query()
        ->where('artist_profile_id', $artistProfile->id)
        ->where('source', 'artist_application')
        ->selectRaw('status, COUNT(*) as total')
        ->groupBy('status')
        ->pluck('total', 'status');

    return response()->json([
        'artist' => [
            'id' => $artistProfile->id,
            'stage_name' => $artistProfile->stage_name,
            'points_balance' => $artistProfile->points_balance,
        ],

        'application_counts' => [
            'pending' => (int) ($applicationCounts['pending'] ?? 0),
            'accepted' => (int) ($applicationCounts['accepted'] ?? 0),
            'rejected' => (int) ($applicationCounts['rejected'] ?? 0),
        ],

        'applications' => $applications,
        'invitations' => $invitations,
    ]);
}


    public function reviewApplication(Request $request, LabelApplication $application)
    {
        $user = $request->user();

        if (!$user || $user->role !== 'label') {
            return response()->json([
                'message' => 'Only label accounts can review applications.'
            ], 402);
        }

        if ($user->label_status !== 'approved' ) {
            return response()->json([
                'message' => 'Your label must be approved before reviewing applications.',
            ], 403);
        }

        $labelProfile = $user->labelProfile;

        if (!$labelProfile) {
            return response()->json([
                'message' => 'Create your label profile first.'
            ], 422);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
        ]);

        $result = DB::transaction(function () use (
            $application,
            $labelProfile,
            $validated
        ) {
            $lockedApplication = LabelApplication::query()
            ->whereKey($application->id)
            ->lockForUpdate()
            ->firstOrFail();

            if ( 
                (int) $lockedApplication->label_profile_id !== (int) $labelProfile->id
            ) {
                return [
                    'error' => response()->json([
                        'message' => 'This application does not belong to your label.'
                    ], 403),
                ];
            }

            if ($lockedApplication->source !== 'artist_application') {
                return [
                    'error' => response()->json([
                        'message' => 'Only artist-submitted application can be reviewed here.'
                    ], 422),
                ];
            }

            if ($lockedApplication->status !== 'pending') {
                return [
                    'error' => response()->json([
                        'message' => 'Only pending applications can be reviewed.'
                    ], 422),
                ];
            }

            $newStatus = $validated['status'];

            $lockedApplication->update([
                'status' => $newStatus,
                'rejected_at' => $newStatus === 'rejected' ? now() : null,
            ]);

            return [
                'application' => $lockedApplication->fresh()->load([
                    'artistProfile:id,user_id,stage_name,bio,state,profile_photo,creative_roles',
                ]),
            ];
        });

        if (isset($result['error'])) {
            return $result['error'];
        }

        return response()->json([
            'message' => 'Application ' . $validated['status'] . 'successfully.',
            'application' => $result['application'],
        ]);

    }
}
