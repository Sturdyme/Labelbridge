<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LabelProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'label') {
            return response()->json([
                'message' => 'Only label accounts can access this profile.'
            ], 403);
        }

        $profile = $user->labelProfile;

        if (!$profile) {
            return response()->json([
                'message' => 'Label profile not found.'
            ], 404);
        }

        return response()->json([
            'profile' => $profile,
        ]);
        
    }

    public function save(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'label') {
            return response()->json([
                'message' => 'Only label accounts can create or update label profile.'
            ], 403);
        }

        $validated = $request->validate([
            'label_name' => [
                'required', 'string', 'max:50',
                Rule::unique('label_profiles', 'label_name')->ignore($user->labelProfile?->id),
            ],
            'bio' => ['nullable', 'string', 'max:5000'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'preferred_genres' => ['nullable', 'array', 'max:20'],
            'preferred_genres.*'=> ['string', 'max:100'],
            'artist_requirements' => ['nullable', 'string', 'max:5000'],
            "office_address" => ['required', 'string', 'max:225'],
            'office_city' => ['required', 'string', 'max:100'],
            'office_state' => ['required', 'string', 'max:100'],
            'office_country' => ['required', 'string', 'max:100'],
        ]);

        $profile = $user->labelProfile()->updateOrCreate(
            ['user_id' => $user->id],
            $validated
        );

        return response()->json([
            'message' => 'Label profile saved successfully.',
            'profile' => $profile,
        ]);
    }
}
