<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtistProfile;
use App\Models\LabelApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LabelInvitationController extends Controller
{
    public function invite(Request $request)
    {
        $user = $request->user();

        //Only label account can send invitations.

        if(!$user || $user->role !== 'label') {
            return response()->json([
                'message' => 'Only label accounts can invite artists.',
            ], 403);
        }

        // Only approved labels can invite artists.
        if ($user->label_status !== 'approved') {
            return response()->json([
                'message' => 'Your label must be approved before inviting artists.',
            ], 403);
        }

        $validated = $request->validate([
            'artist_profile_id' => [
                'required',
                'integer',
                'exists:artist_profiles,id',
            ],
            'message' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $labelProfile = $user->labelProfile;

        if (!$labelProfile) {
            return response()->json([
                'message' => 'Create your label profile before inviting artists.',
            ], 422);
        }

        $artist = ArtistProfile::findOrFail(
            $validated['artist_profile_id']
        );

        $application = DB::transaction(function () use (
            $artist,
            $labelProfile,
            $validated
        ) {
            //Prevent multiple active applications or invitations between the same artist and label.
            $hasActivatedRecord = LabelApplication::query()
            ->where('artist_profile_id', $artist->id)
            ->where('label_profile_id', $labelProfile->id)
            ->whereIn('status', ['pending', 'invited'])
            ->lockForUpdate()
            ->exists();

            if ($hasActivatedRecord) {
                return null;
            }
         
            return LabelApplication::create([
                'artist_profile_id' => $artist->id,
                'label_profile_id' => $labelProfile->id,
                'message' => $validated['message'] ?? null,
                'status' => 'invited',
                'source' => 'label_invitation',
                'invited_at' => now(),
                'invitation_expires_at' => now()->addDays(7),
                'invitation_responded_at' => null,
                'used_points' => false,
            ]);
        });

        if (!$application) {
            return response()->json([
                'message' => 'An active application or invitation already exists for this artist and label.'
            ], 409);
        }

        return response()->json([
            'message' => 'Artist invitation created successfully.',
            'invitation' => $application,
        ], 201);

    }


/**
 * Accept an invitation sent to the authenticated artist.
 */
public function accept(Request $request, LabelApplication $invitation)
{
    $user = $request->user();

    if (!$user || $user->role !== 'artist') {
        return response()->json([
            'message' => 'Only artist accounts can accept invitations.',
        ], 403);
    }

    $artistProfile = $user->artistProfile;

    if (
        !$artistProfile ||
        (int) $invitation->artist_profile_id !== (int) $artistProfile->id
    ) {
        return response()->json([
            'message' => 'This invitation does not belong to you.',
        ], 403);
    }

    return DB::transaction(function () use ($invitation) {
        $lockedInvitation = LabelApplication::query()
            ->whereKey($invitation->id)
            ->lockForUpdate()
            ->firstOrFail();

        // Only genuine label invitations can be accepted.
        if ($lockedInvitation->source !== 'label_invitation') {
            return response()->json([
                'message' => 'This record is not a label invitation.',
            ], 422);
        }

        if ($lockedInvitation->status !== 'invited') {
            return response()->json([
                'message' => 'This invitation has already been responded to or is no longer active.',
            ], 422);
        }

        if (
            !$lockedInvitation->invitation_expires_at ||
            now()->greaterThanOrEqualTo(
                $lockedInvitation->invitation_expires_at
            )
        ) {
            $lockedInvitation->update([
                'status' => 'expired',
            ]);

            return response()->json([
                'message' => 'This invitation has expired.',
            ], 422);
        }

        $lockedInvitation->update([
            'status' => 'accepted',
            'invitation_responded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Invitation accepted successfully.',
            'invitation' => $lockedInvitation->fresh(),
        ]);
    });
}


/**
 * Decline an invitation sent to the authenticated artist.
 */
public function decline(Request $request, LabelApplication $invitation)
{
    $user = $request->user();

    if (!$user || $user->role !== 'artist') {
        return response()->json([
            'message' => 'Only artist accounts can decline invitations.',
        ], 403);
    }

    $artistProfile = $user->artistProfile;

    if (
        !$artistProfile ||
        (int) $invitation->artist_profile_id !== (int) $artistProfile->id
    ) {
        return response()->json([
            'message' => 'This invitation does not belong to you.',
        ], 403);
    }

    return DB::transaction(function () use ($invitation) {
        $lockedInvitation = LabelApplication::query()
            ->whereKey($invitation->id)
            ->lockForUpdate()
            ->firstOrFail();

        // Only genuine label invitations can be declined.
        if ($lockedInvitation->source !== 'label_invitation') {
            return response()->json([
                'message' => 'This record is not a label invitation.',
            ], 422);
        }

        if ($lockedInvitation->status !== 'invited') {
            return response()->json([
                'message' => 'This invitation has already been responded to or is no longer active.',
            ], 422);
        }

        if (
            !$lockedInvitation->invitation_expires_at ||
            now()->greaterThanOrEqualTo(
                $lockedInvitation->invitation_expires_at
            )
        ) {
            $lockedInvitation->update([
                'status' => 'expired',
            ]);

            return response()->json([
                'message' => 'This invitation has expired.',
            ], 422);
        }

        $lockedInvitation->update([
            'status' => 'declined',
            'invitation_responded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Invitation declined successfully.',
            'invitation' => $lockedInvitation->fresh(),
        ]);
    });
}


}
