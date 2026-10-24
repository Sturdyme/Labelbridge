<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtistProfile;
use App\Models\LabelProfile;
use Illuminate\Http\Request;

class PublicProfileController extends Controller
{
  public function showArtist(Request $request, ArtistProfile $artistProfile)
  {
   $user = $request->user();

if ($user->role === 'label' && $user->label_status !== 'approved') {
    return response()->json([
        'message' => 'Your label must be approved to browse artist profiles.',
    ], 403);
}

if (!in_array($user->role, ['artist', 'label'], true)) {
    return response()->json([
        'message' => 'Only artists and approved labels can browse artist profiles.',
    ], 403);
}

    $data = [
        'id' => $artistProfile->id,
        'stage_name' => $artistProfile->stage_name,
        'bio' => $artistProfile->bio,
        'state' => $artistProfile->state,
        'profile_photo' => $artistProfile->profile_photo,
        'creative_roles' => $artistProfile->creative_roles,
    ];

    $socialVisibilityActive = $artistProfile->social_visibility_expires_at !== null && $artistProfile->social_visibility_expires_at->isFuture();

    if ($socialVisibilityActive) {
        $data['social_handle'] = $artistProfile->social_handles;
    }
    return response()->json([
        'profile' => $data
    ]);
  }

  // View a label's public without private office details

  public function showLabel(Request $request, LabelProfile $labelProfile) {
    if ($request->user()->role !== 'artist') {
        return response()->json([
            'message' => 'Only artist can browse label profiles.'
        ], 403);
    }

    $isCertified = $labelProfile->user?->label_status === 'approved' && $labelProfile->certification_status === 'active' && $labelProfile->certification_expires_at !== null && $labelProfile->certification_expires_at->isFuture();

    return response()->json([
        'profile' => [

                'id' => $labelProfile->id,
                'label_name' => $labelProfile->label_name,
                'bio' => $labelProfile->bio,
                'logo' => $labelProfile->logo,
                'preferred_genres' => $labelProfile->preferred_genres,
                'artist_requirements' => $labelProfile->artist_requirements,
                'is_certified' => $isCertified,
            ],
        
    ]);
  }
}
