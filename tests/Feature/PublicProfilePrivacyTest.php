<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\LabelProfile;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicProfilePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function createArtist(string $suffix = ''): array
    {
        $user = User::factory()->create([
            'role' => 'artist',
            'email_verified_at' => now(),
        ]);

        $profile = ArtistProfile::create([
            'user_id' => $user->id,
            'stage_name' => 'Test Artist ' . $user->id . $suffix,
            'bio' => 'Test artist bio',
            'state' => 'Lagos',
            'profile_photo' => null,
            'creative_roles' => ['Singer'],
            'social_handles' => [
                'instagram' => '@testartist',
            ],
            'social_visibility_expires_at' => null,
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
            'label_name' => 'Test Label ' . $user->id,
            'bio' => 'Test label bio',
            'logo' => null,
            'preferred_genres' => ['Afrobeats'],
            'artist_requirements' => 'Original music',
            'office_address' => 'Private Test Office Address',
            'office_city' => 'Lagos',
            'office_state' => 'Lagos',
            'office_country' => 'Nigeria',
            'certification_status' => 'active',
            'certification_expires_at' => now()->addYear(),
        ]);

        return [$user, $profile];
    }

    public function test_artist_profile_hides_social_handles_without_active_subscription(): void
    {
        [$viewer] = $this->createArtist(' Viewer');
        [, $artistProfile] = $this->createArtist(' Target');

        Sanctum::actingAs($viewer);

        $response = $this->getJson(
            "/api/public/artists/{$artistProfile->id}"
        );

        $response->assertOk()
            ->assertJsonMissingPath('profile.social_handles')
            ->assertJsonMissingPath('profile.social_handle');
    }

    public function test_artist_profile_reveals_social_handles_to_another_artist_during_active_subscription(): void
    {
        [$viewer] = $this->createArtist(' Viewer');
        [, $artistProfile] = $this->createArtist(' Target');

        $artistProfile->update([
            'social_visibility_expires_at' => now()->addDays(10),
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson(
            "/api/public/artists/{$artistProfile->id}"
        );

        $response->assertOk()
            ->assertJsonPath(
                'profile.social_handle.instagram',
                '@testartist'
            );
    }

    public function test_artist_profile_hides_social_handles_after_subscription_expires(): void
    {
        [$viewer] = $this->createArtist(' Viewer');
        [, $artistProfile] = $this->createArtist(' Target');

        $artistProfile->update([
            'social_visibility_expires_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson(
            "/api/public/artists/{$artistProfile->id}"
        );

        $response->assertOk()
            ->assertJsonMissingPath('profile.social_handles')
            ->assertJsonMissingPath('profile.social_handle');
    }

    public function test_unapproved_label_cannot_view_artist_profile(): void
    {
        [$label] = $this->createLabel('pending');
        [, $artistProfile] = $this->createArtist(' Target');

        Sanctum::actingAs($label);

        $this->getJson("/api/public/artists/{$artistProfile->id}")
            ->assertForbidden();
    }

    public function test_public_label_profile_never_exposes_private_office_address(): void
    {
        [$artist] = $this->createArtist();
        [, $labelProfile] = $this->createLabel();

        Sanctum::actingAs($artist);

        $response = $this->getJson(
            "/api/public/labels/{$labelProfile->id}"
        );

        $response->assertOk()
            ->assertJsonMissingPath('profile.office_address')
            ->assertJsonMissingPath('profile.office_city')
            ->assertJsonMissingPath('profile.office_state')
            ->assertJsonMissingPath('profile.office_country')
            ->assertDontSee('Private Test Office Address');
    }

    public function test_expired_label_certification_is_not_publicly_certified(): void
    {
        [$artist] = $this->createArtist();
        [, $labelProfile] = $this->createLabel();

        $labelProfile->update([
            'certification_status' => 'active',
            'certification_expires_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($artist);

        $this->getJson("/api/public/labels/{$labelProfile->id}")
            ->assertOk()
            ->assertJsonPath('profile.is_certified', false);
    }

    public function test_revoked_label_certification_is_not_publicly_certified(): void
    {
        [$artist] = $this->createArtist();
        [, $labelProfile] = $this->createLabel();

        $labelProfile->update([
            'certification_status' => 'revoked',
            'certification_expires_at' => now()->addYear(),
        ]);

        Sanctum::actingAs($artist);

        $this->getJson("/api/public/labels/{$labelProfile->id}")
            ->assertOk()
            ->assertJsonPath('profile.is_certified', false);
    }

    public function test_approved_label_cannot_view_artist_social_handles(): void
{
    [$label] = $this->createLabel('approved');
    [, $artistProfile] = $this->createArtist(' Target');

    $artistProfile->update([
        'social_visibility_expires_at' => now()->addDays(10),
    ]);

    Sanctum::actingAs($label);

    $this->getJson("/api/public/artists/{$artistProfile->id}")
        ->assertOk()
        ->assertJsonMissingPath('profile.social_handle')
        ->assertJsonMissingPath('profile.social_handles');
}
}
