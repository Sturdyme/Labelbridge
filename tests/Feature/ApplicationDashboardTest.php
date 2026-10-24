<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\LabelApplication;
use App\Models\LabelProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApplicationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function createArtist(string $email = 'artist@example.com'): array
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => 'artist',
            'email_verified_at' => now(),
        ]);

        $profile = ArtistProfile::create([
            'user_id' => $user->id,
            'stage_name' => 'Artist ' . $user->id,
            'bio' => 'Test artist bio',
            'state' => 'Lagos',
            'creative_roles' => ['singer'],
        ]);

        return [$user, $profile];
    }

    private function createLabel(
        string $email = 'label@example.com',
        string $status = 'approved'
    ): array {
        $user = User::factory()->create([
            'email' => $email,
            'role' => 'label',
            'label_status' => $status,
            'email_verified_at' => now(),
        ]);

        $profile = LabelProfile::create([
            'user_id' => $user->id,
            'label_name' => 'Label ' . $user->id,
            'bio' => 'Test label bio',
            'preferred_genres' => ['Afrobeats'],
            'office_address' => 'Private office address',
            'office_city' => 'Lagos',
            'office_state' => 'Lagos',
            'office_country' => 'Nigeria',
        ]);

        return [$user, $profile];
    }

    private function createLabelApplication(
        ArtistProfile $artist,
        LabelProfile $label,
        string $status = 'pending',
        string $source = 'artist_application'
    ): LabelApplication {
        return LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'message' => 'Please consider my music.',
            'status' => $status,
            'source' => $source,
        ]);
    }

    public function test_artist_can_view_their_application_dashboard(): void
    {
        [$artistUser, $artistProfile] = $this->createArtist();
        [, $labelProfile] = $this->createLabel();

        $this->createLabelApplication($artistProfile, $labelProfile);

        Sanctum::actingAs($artistUser);

        $this->getJson('/api/artist/dashboard')
            ->assertOk()
            ->assertJsonPath('artist.stage_name', $artistProfile->stage_name)
            ->assertJsonPath('application_counts.pending', 1)
            ->assertJsonCount(1, 'applications.data')
            ->assertJsonCount(0, 'invitations.data');
    }

    public function test_label_can_view_applications_submitted_to_them(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$labelUser, $labelProfile] = $this->createLabel();

        $this->createLabelApplication($artistProfile, $labelProfile);

        Sanctum::actingAs($labelUser);

        $this->getJson('/api/label/dashboard')
            ->assertOk()
            ->assertJsonPath('label.label_name', $labelProfile->label_name)
            ->assertJsonPath('application_counts.pending', 1)
            ->assertJsonCount(1, 'applications.data');
    }

    public function test_artist_cannot_access_label_dashboard(): void
    {
        [$artistUser] = $this->createArtist();

        Sanctum::actingAs($artistUser);

        $this->getJson('/api/label/dashboard')
            ->assertForbidden();
    }

    public function test_label_cannot_access_artist_dashboard(): void
    {
        [$labelUser] = $this->createLabel();

        Sanctum::actingAs($labelUser);

        $this->getJson('/api/artist/dashboard')
            ->assertForbidden();
    }

    public function test_label_can_accept_its_own_pending_application(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$labelUser, $labelProfile] = $this->createLabel();

        $application = $this->createLabelApplication(
            $artistProfile,
            $labelProfile
        );

        Sanctum::actingAs($labelUser);

        $this->patchJson(
            "/api/label/applications/{$application->id}/status",
            ['status' => 'accepted']
        )
            ->assertOk()
            ->assertJsonPath('application.status', 'accepted');

        $this->assertDatabaseHas('label_applications', [
            'id' => $application->id,
            'status' => 'accepted',
        ]);
    }

    public function test_label_can_reject_its_own_pending_application(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$labelUser, $labelProfile] = $this->createLabel();

        $application = $this->createLabelApplication(
            $artistProfile,
            $labelProfile
        );

        Sanctum::actingAs($labelUser);

        $this->patchJson(
            "/api/label/applications/{$application->id}/status",
            ['status' => 'rejected']
        )
            ->assertOk()
            ->assertJsonPath('application.status', 'rejected');

        $this->assertDatabaseHas('label_applications', [
            'id' => $application->id,
            'status' => 'rejected',
        ]);

        $this->assertNotNull($application->fresh()->rejected_at);
    }

    public function test_label_cannot_review_another_labels_application(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$firstLabelUser, $firstLabel] = $this->createLabel();
        [, $secondLabel] = $this->createLabel('other-label@example.com');

        $application = $this->createLabelApplication(
            $artistProfile,
            $secondLabel
        );

        Sanctum::actingAs($firstLabelUser);

        $this->patchJson(
            "/api/label/applications/{$application->id}/status",
            ['status' => 'accepted']
        )->assertForbidden();

        $this->assertDatabaseHas('label_applications', [
            'id' => $application->id,
            'status' => 'pending',
        ]);
    }

    public function test_label_cannot_review_a_label_invitation_as_an_application(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$labelUser, $labelProfile] = $this->createLabel();

        $invitation = $this->createLabelApplication(
            $artistProfile,
            $labelProfile,
            'invited',
            'label_invitation'
        );

        Sanctum::actingAs($labelUser);

        $this->patchJson(
            "/api/label/applications/{$invitation->id}/status",
            ['status' => 'accepted']
        )->assertStatus(422);

        $this->assertDatabaseHas('label_applications', [
            'id' => $invitation->id,
            'status' => 'invited',
        ]);
    }

    public function test_review_requires_a_valid_status(): void
    {
        [, $artistProfile] = $this->createArtist();
        [$labelUser, $labelProfile] = $this->createLabel();

        $application = $this->createLabelApplication(
            $artistProfile,
            $labelProfile
        );

        Sanctum::actingAs($labelUser);

        $this->patchJson(
            "/api/label/applications/{$application->id}/status",
            ['status' => 'pending']
        )->assertUnprocessable();
    }

    public function test_label_dashboard_does_not_expose_private_office_address(): void
    {
        [$artistUser] = $this->createArtist();
        [, $labelProfile] = $this->createLabel();

        Sanctum::actingAs($artistUser);

        $response = $this->getJson('/api/artist/dashboard')
            ->assertOk();

        $response->assertJsonMissingPath('label.office_address');

        $this->assertDatabaseHas('label_profiles', [
            'id' => $labelProfile->id,
            'office_address' => 'Private office address',
        ]);
    }
}
