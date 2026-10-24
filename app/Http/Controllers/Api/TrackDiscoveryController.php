<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrackDiscoveryController extends Controller
{
    public function index(Request $request)
    {
       $user = $request->user();

if ($user->role === 'label' && $user->label_status !== 'approved') {
    return response()->json([
        'message' => 'Your label must be approved to discover music.',
    ], 403);
}

if (!in_array($user->role, ['artist', 'label'], true)) {
    return response()->json([
        'message' => 'Only artists and approved labels can discover music.',
    ], 403);
}

        // Return published songs belonging to other artists only.
        $tracks = Track::query()->where('status', 'published')->whereHas('artistProfile', function ($query) use ($user) {
            $query->where('user_id', '!=', $user->id);
        })
        ->with('artistProfile')
        ->orderByDesc('published_at')
        ->paginate(15);
 

          


        //Explicitly select public fields so private storage paths 
         //and unrelated database fields are never exposed

        $tracks->getCollection()->transform(function ($track) use ($user) {
            $artist = $track->artistProfile;
            /** @var \Illuminate\Filesystem\FilesystemAdapter $publicStorage */
            $publicStorage = Storage::disk('public');

            $result = [
                'id' => $track->id,
                'title' =>$track->title,
                'description' => $track->description,
                'genre' => $track->genre,
                'audio_format' => $track->audio_format,
                'cover_art_path' => $track->cover_art_path ? $publicStorage->url($track->cover_art_path) : null,
                'published_at' => $track->published_at,
                'stream_endpoint' => "/api/tracks/{$track->id}/stream",
                'artist' => [
                    'id' => $artist->id,
                    'stage_name' => $artist->stage_name,
                    'bio' => $artist->bio,
                    'state' => $artist->state,
                    'profile_photo' => $artist->profile_photo,
                    'creative_roles' => $artist->creative_roles
                ],
            ];
          
            // Reveal social handles only to artist viewers when the
             // artist's paid visibolity subscription is active.

           $socialVisibilityActive =
    $user->role === 'artist'
    && $artist->user_id !== $user->id
    && $artist->social_visibility_expires_at !== null
    && $artist->social_visibility_expires_at->isFuture();

if ($socialVisibilityActive) {
    $result['artist']['social_handles'] = $artist->social_handles;
}

            return $result;
        });

        return response()->json([
            'message' => 'Music discovery loaded successfully.',
            'tracks' => $tracks,
        ]);
    }
}
