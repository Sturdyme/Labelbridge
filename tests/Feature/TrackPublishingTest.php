<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\LabelProfile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrackPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    private function createArtist(): array
    {
        $user = User::factory()->create([
            'role' => 'artist',
            'email_verified_at' => now(),
        ]);

        $profile = ArtistProfile::create([
            'user_id' => $user->id,
            'stage_name' => 'Artist ' . $user->id,
            'bio' => 'Test artist',
            'state' => 'Lagos',
            'creative_roles' => ['singer'],
            'social_handles' => [],
        ]);

        return [$user, $profile];
    }

    private function createLabel(): User
    {
        $user = User::factory()->create([
            'role' => 'label',
            'label_status' => 'approved',
            'email_verified_at' => now(),
        ]);

        LabelProfile::create([
            'user_id' => $user->id,
            'label_name' => 'Label ' . $user->id,
            'bio' => 'Test label',
            'preferred_genres' => ['Afrobeats'],
            'office_address' => 'Private address',
            'office_city' => 'Lagos',
            'office_state' => 'Lagos',
            'office_country' => 'Nigeria',
        ]);

        return $user;
    }

    private function createDraft(ArtistProfile $profile): Track
    {
        $path = "tracks/{$profile->id}/draft.mp3";

        Storage::disk('local')->put($path, 'test audio');

        return Track::create([
            'artist_profile_id' => $profile->id,
            'title' => 'Draft Track',
            'description' => 'Test draft',
            'genre' => 'Afrobeats',
            'audio_path' => $path,
            'audio_format' => 'mp3',
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    public function test_artist_can_publish_their_own_track(): void
    {
        [$artist, $profile] = $this->createArtist();
        $track = $this->createDraft($profile);

        Sanctum::actingAs($artist);

        $this->patchJson("/api/artist/tracks/{$track->id}/publish")
            ->assertOk()
            ->assertJsonPath('track.status', 'published')
            ->assertJsonPath('message', 'Song published successfully.');

        $this->assertNotNull($track->fresh()->published_at);
    }

    public function test_artist_cannot_publish_another_artists_track(): void
    {
        [, $ownerProfile] = $this->createArtist();
        [$otherArtist] = $this->createArtist();

        $track = $this->createDraft($ownerProfile);

        Sanctum::actingAs($otherArtist);

        $this->patchJson("/api/artist/tracks/{$track->id}/publish")
            ->assertNotFound();

        $this->assertSame('draft', $track->fresh()->status);
    }

    public function test_label_cannot_publish_a_track(): void
    {
        [, $profile] = $this->createArtist();
        $label = $this->createLabel();
        $track = $this->createDraft($profile);

        Sanctum::actingAs($label);

        $this->patchJson("/api/artist/tracks/{$track->id}/publish")
            ->assertForbidden();

        $this->assertSame('draft', $track->fresh()->status);
    }

    public function test_artist_can_upload_valid_mp3_as_a_private_draft(): void
    {
        [$artist] = $this->createArtist();

        Sanctum::actingAs($artist);

        $response = $this->postJson('/api/artist/tracks', [
            'title' => 'New Song',
            'description' => 'My test song',
            'genre' => 'Afrobeats',
            'audio' => UploadedFile::fake()->create(
                'song.mp3',
                100,
                'audio/mpeg'
            ),
        ]);

        $response->assertCreated()
            ->assertJsonPath('track.title', 'New Song')
            ->assertJsonPath('track.status', 'draft');

        $track = Track::findOrFail($response->json('track.id'));

        $this->assertSame('mp3', $track->audio_format);
        Storage::disk('local')->assertExists($track->audio_path);
    }

    public function test_label_cannot_upload_a_track(): void
    {
        $label = $this->createLabel();

        Sanctum::actingAs($label);

        $this->postJson('/api/artist/tracks', [
            'title' => 'Not Allowed',
            'genre' => 'Afrobeats',
        ])->assertForbidden();
    }

    public function test_audio_upload_is_required(): void
    {
        [$artist] = $this->createArtist();

        Sanctum::actingAs($artist);

        $this->postJson('/api/artist/tracks', [
            'title' => 'Missing Audio',
            'genre' => 'Afrobeats',
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['audio']);
    }

    public function test_unsupported_audio_extension_is_rejected(): void
    {
        [$artist] = $this->createArtist();

        Sanctum::actingAs($artist);

        $this->postJson('/api/artist/tracks', [
            'title' => 'Invalid Song',
            'genre' => 'Afrobeats',
            'audio' => UploadedFile::fake()->create(
                'song.txt',
                100,
                'text/plain'
            ),
        ])->assertUnprocessable()
          ->assertJsonValidationErrors(['audio']);
    }
}
