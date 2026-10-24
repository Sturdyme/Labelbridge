<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ArtistProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'artist') {
            return response()->json([
                'message' => 'Only artist accounts can access this profile.'
            ], 403);
        }

        $profile = $user->artistProfile;

        if (!$profile) {
            return response()->json([
                'message' => 'Artist profile not found.',
            ], 404);
        }

        return response()->json([
            'profile' => $profile
        ]);
    }

    public function save(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'artist' ) {
            return response()->json([
                'message' => 'Only artist accounts can create or update artist profiles.'
            ], 403);
        }

        $validated = $request->validate([
            'stage_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('artist_profiles', 'stage_name')->ignore($user->artistProfile?->id),
            ],
            'bio' => ['nullable', 'string', 'max:5000'],
            'state' => ['nullable', 'string', 'max:100'],
            'profile_photo' => ['nullable', 'string', 'max:2048'],
            'creative_roles' => ['nullable', 'array', 'max:10'],
            'creative_roles.*' => ['string', 'max:100'],
            'social_handles' => ['nullable', 'array'],
            'social_handles.*' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = $user->artistProfile()->updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        return response()->json([
            'message' => 'Artist profile saved successfully.',
            'profile' => $profile,
        ], 200);
    }
}
