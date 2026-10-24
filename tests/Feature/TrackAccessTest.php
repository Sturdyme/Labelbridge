<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\LabelProfile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackAccessTest extends TestCase
{
    use RefreshDatabase;

    
private function createArtist(): array
{
    $user = User::factory()->create([
        'role' => 'artist',
        'email_verified_at' => now(),
    ]);

    $profile = ArtistProfile::create([
        'user_id' => $user->id,
        'stage_name' => 'Test Artist ' . $user->id,
        'bio' => 'Test artist biography',
        'state' => 'Lagos',
        'creative_roles' => ['singer'],
        'social_handles' => ['instagram' => '@testartist'],
    ]);

    return [$user, $profile];
}


    private function createLabel(string $status = 'approved'): array
    {
        $user = User::factory()->create([
            'role' => 'label',
            'label_status' => $status,
            'email_verified_at' => now(),
        ]);

        $profile = LabelProfile::create([
            'user_id' => $user->id,
            'label_name' => 'Test Label',
            'bio' => 'Test label biography',
            'preferred_genres' => ['Afrobeats'],
            'office_address' => 'Private test address',
            'office_city' => 'Lagos',
            'office_state' => 'Lagos',
            'office_country' => 'Nigeria',
        ]);

        return [$user, $profile];
    }

    private function createTrack(
        ArtistProfile $artist,
        string $status = 'published'
    ): Track {
        $path = "tracks/{$artist->id}/test-track.mp3";

        Storage::disk('local')->put($path, 'fake audio content');

        return Track::create([
            'artist_profile_id' => $artist->id,
            'title' => 'Test Track',
            'description' => 'A test song',
            'genre' => 'Afrobeats',
            'audio_path' => $path,
            'audio_format' => 'mp3',
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_approved_label_can_discover_published_tracks(): void
    {
        [$artist, $artistProfile] = $this->createArtist();
        [$label] = $this->createLabel();

        $track = $this->createTrack($artistProfile);

        Sanctum::actingAs($label);

        $response = $this->getJson('/api/discovery/tracks');

        $response->assertOk()
            ->assertJsonPath('tracks.data.0.id', $track->id)
            ->assertJsonPath('tracks.data.0.title', 'Test Track')
            ->assertJsonMissingPath('tracks.data.0.audio_path');

        $this->assertArrayNotHasKey(
            'social_handles',
            $response->json('tracks.data.0.artist')
        );
    }

    public function test_pending_label_cannot_discover_music(): void
    {
        [$artist, $artistProfile] = $this->createArtist();
        [$label] = $this->createLabel('pending');

        $this->createTrack($artistProfile);

        Sanctum::actingAs($label);

        $this->getJson('/api/discovery/tracks')
            ->assertForbidden();
    }

    public function test_approved_label_can_stream_published_track(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$label] = $this->createLabel();

        $track = $this->createTrack($artistProfile);

        Sanctum::actingAs($label);

        $this->get("/api/tracks/{$track->id}/stream")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_pending_label_cannot_stream_published_track(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$label] = $this->createLabel('pending');

        $track = $this->createTrack($artistProfile);

        Sanctum::actingAs($label);

        $this->getJson("/api/tracks/{$track->id}/stream")
            ->assertForbidden();
    }

    public function test_label_cannot_stream_another_artists_draft(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$label] = $this->createLabel();

        $track = $this->createTrack($artistProfile, 'draft');

        Sanctum::actingAs($label);

        $this->getJson("/api/tracks/{$track->id}/stream")
            ->assertNotFound();
    }

    public function test_artist_can_stream_own_draft(): void
    {
        [$artist, $artistProfile] = $this->createArtist();

        $track = $this->createTrack($artistProfile, 'draft');

        Sanctum::actingAs($artist);

        $this->get("/api/tracks/{$track->id}/stream")
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_artist_cannot_stream_another_artists_draft(): void
    {
        [, $ownerProfile] = $this->createArtist();
        [$otherArtist] = $this->createArtist();

        $track = $this->createTrack($ownerProfile, 'draft');

        Sanctum::actingAs($otherArtist);

        $this->getJson("/api/tracks/{$track->id}/stream")
            ->assertNotFound();
    }
}