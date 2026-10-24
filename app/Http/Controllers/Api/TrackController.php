<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TrackController extends Controller
{
    //Upload a song for the auntenticated artist

    public function index(Request $request) 
    {
        $user = $request->user();

        if ($user->role !== 'artist') {
            return response()->json([
                'message' => 'Only artist accounts can view their uploaded songs.',
            ], 403);
        }

        $artist = $user->artistProfile;

        if (!$artist) {
            return response()->json([
                'message' => 'Create your artist profile before viewing your songs.',
            ], 422);
        }

        $tracks = $artist->tracks()
        ->latest()
        ->get();

        return response()->json([
            'message' => 'Your uploaded songs were retrieved successfully.',
            'tracks' => $tracks,
        ]);
    }

    
   public function store(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'artist') {
            return response()->json([
                'message' => 'Only artist accounts can upload songs.',
            ], 403);
        }

        $artist = $user->artistProfile;

        if (!$artist) {
            return response()->json([
                'message' => 'Create your artist profile before uploading songs.',
            ], 422);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'genre' => ['required', 'string', 'max:100'],

            'audio' => [
                'required',
                'file',
                'max:51200',
                'mimes:mp3,wav,m4a',
            ],

            'cover_art' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $audio = $validated['audio'];

        $audioPath = $audio->store(
            'tracks/' . $artist->id,
            'local'
        );

        $coverPath = null;

        try {
            if ($request->hasFile('cover_art')) {
                $coverPath = $request->file('cover_art')->store(
                    'track-covers/' . $artist->id,
                    'public'
                );
            }

            $extension = strtolower($audio->getClientOriginalExtension());

            $track = $artist->tracks()->create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'genre' => $validated['genre'],
                'audio_path' => $audioPath,
                'audio_format' => $extension,
                'cover_art_path' => $coverPath,
                'status' => 'draft',
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($audioPath);

            if ($coverPath) {
                Storage::disk('public')->delete($coverPath);
            }

            throw $exception;
        }

        return response()->json([
            'message' => 'Song uploaded successfully as a draft.',
            'track' => $track,
        ], 201);
    }

    public function stream(Request $request, Track $track)
    {
        $user = $request->user();
        
        // Only artist account can listen through this endpoint
       // Artists can stream their own tracks and published tracks.
// Approved labels can stream published tracks only.
if (!in_array($user->role, ['artist', 'label'], true)) {
    return response()->json([
        'message' => 'Only artists and approved labels can stream tracks.',
    ], 403);
}

if ($user->role === 'label' && $user->label_status !== 'approved') {
    return response()->json([
        'message' => 'Your label must be approved to stream tracks.',
    ], 403);
}

        // Check where this track belongs to the logged-in artist
        $isOwner = $track->artistProfile()
        ->where('user_id', $user->id)
        ->exists();

        // Other artists can listen to only published tracks.
       if (!$isOwner && $track->status !== 'published') {
    return response()->json([
        'message' => 'Track not found.',
    ], 404);
}

       // Confirm the private audio file exists

       $disk = Storage::disk('local');

       if (!$disk->exists($track->audio_path)) {
        return response()->json([
            'message' => ' The audio file could not be found.'
        ], 404);
       }

       //Determine the correct audio MIME type.
       $contentTypes = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
       ];

       $format = strtolower($track->audio_format);
       $contentType = $contentTypes[$format] ?? null;
       
       if ($contentType === null) {
        return response()->json([
            'message' => 'Unsupported audio format.',
        ], 415);
       }

       //Serve the private file inline so audio players can play it.
       return response()->file(
        $disk->path($track->audio_path),
        [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            
        ]
       );
    }

    public function publish(Request $request, Track $track)
    {
        $user = $request->user();

        // Only artist accounts can publish songs.
        if($user->role !== 'artist') {
            return response()->json([
                'message' => 'Only artist accounts can publish songs.'
            ], 403);
        }

        //Ensure the artist owns this song.
        $artist = $user->artistProfile;

        if (!$artist || $track->artist_profile_id !== $artist->id) {
            return response()->json([
                'message' => 'Track not found.',
            ], 404);
        }

        // Publish the song and record its publication date.
        $track->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return response()->json([
            'message' => 'Song published successfully.',
            'track' => $track->fresh(),
        ]);
    }
}

