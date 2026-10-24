<?php

namespace Tests\Feature;

use App\Models\ArtistProfile;
use App\Models\LabelApplication;
use App\Models\LabelProfile;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class LabelApplicationPointsTest extends TestCase
{
    use RefreshDatabase;

    private function createArtist(int $points = 50): array
    {
        $user = User::create([
            'name' => 'Test Artist',
            'email' => Str::uuid() . '@example.com',
            'password' => 'test-password',
            'role' => 'artist',
            
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        $profile = ArtistProfile::create([
            'user_id' => $user->id,
            'stage_name' => 'Artist ' . Str::uuid(),
        ]);

        // points_balance is not mass assignable on ArtistProfile.
        $profile->forceFill([
            'points_balance' => $points,
        ])->save();

        return [$user, $profile];
    }

    private function createApprovedLabel(): LabelProfile
    {
        $user = User::create([
            'name' => 'Test Label',
            'email' => Str::uuid() . '@example.com',
            'password' => 'test-password',
            'role' => 'label',
            'label_status' => 'approved',
    
        ]);

        $user->forceFill([
            'email_verified_at' => now(),
        ])->save();

        return LabelProfile::create([
            'user_id' => $user->id,
            'label_name' => 'Label ' . Str::uuid(),
            'office_address' => '1 Test Street',
            'office_city' => 'Lagos',
            'office_state' => 'Lagos',
            'office_country' => 'Nigeria',
        ]);
    }

    private function seedPreviousApplications(
        ArtistProfile $artist,
        int $count
    ): void {
        for ($i = 0; $i < $count; $i++) {
            $label = $this->createApprovedLabel();

            LabelApplication::create([
                'artist_profile_id' => $artist->id,
                'label_profile_id' => $label->id,
                'status' => 'accepted',
                'used_points' => false,
            ]);
        }
    }

    private function apply(User $user, LabelProfile $label)
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson("/api/labels/{$label->id}/applications", [
                'message' => 'I would like to apply to your label.',
            ]);
    }

    public function test_first_application_is_free(): void
    {
        [$user, $artist] = $this->createArtist(50);
        $label = $this->createApprovedLabel();

        $this->apply($user, $label)
            ->assertCreated()
            ->assertJsonPath('points_charged', 0)
            ->assertJsonPath('points_balance', 50)
            ->assertJsonPath('monthly_applications_used', 1);

        $this->assertSame(
            50,
            (int) $artist->fresh()->points_balance
        );

        $this->assertDatabaseHas('label_applications', [
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'used_points' => false,
        ]);

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_fifth_application_is_free(): void
    {
        [$user, $artist] = $this->createArtist(50);

        $this->seedPreviousApplications($artist, 4);
        $label = $this->createApprovedLabel();

        $this->apply($user, $label)
            ->assertCreated()
            ->assertJsonPath('points_charged', 0)
            ->assertJsonPath('points_balance', 50)
            ->assertJsonPath('monthly_applications_used', 5);

        $this->assertSame(
            50,
            (int) $artist->fresh()->points_balance
        );

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_sixth_application_deducts_ten_points(): void
    {
        [$user, $artist] = $this->createArtist(50);

        $this->seedPreviousApplications($artist, 5);
        $label = $this->createApprovedLabel();

        $response = $this->apply($user, $label)
            ->assertCreated()
            ->assertJsonPath('points_charged', 10)
            ->assertJsonPath('points_balance', 40)
            ->assertJsonPath('monthly_applications_used', 6);

        $applicationId = $response->json('application.id');

        $this->assertSame(
            40,
            (int) $artist->fresh()->points_balance
        );

        $this->assertDatabaseHas('label_applications', [
            'id' => $applicationId,
            'used_points' => true,
        ]);

        $this->assertDatabaseHas('point_transactions', [
            'artist_profile_id' => $artist->id,
            'type' => 'application_fee',
            'points' => 10,
            'balance_after' => 40,
            'label_application_id' => $applicationId,
        ]);

        $this->assertSame(
            1,
            PointTransaction::where(
                'label_application_id',
                $applicationId
            )->count()
        );
    }

    public function test_application_is_rejected_when_points_are_insufficient(): void
    {
        [$user, $artist] = $this->createArtist(5);

        $this->seedPreviousApplications($artist, 5);
        $label = $this->createApprovedLabel();

        $this->apply($user, $label)
            ->assertUnprocessable()
            ->assertJsonPath('points_required', 10)
            ->assertJsonPath('points_balance', 5);

        $this->assertSame(
            5,
            (int) $artist->fresh()->points_balance
        );

        $this->assertDatabaseMissing('label_applications', [
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
        ]);

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_duplicate_active_application_is_rejected_without_charging_points(): void
    {
        [$user, $artist] = $this->createArtist(50);
        $label = $this->createApprovedLabel();

        LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'status' => 'pending',
            'used_points' => false,
        ]);

        $this->apply($user, $label)
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'You already have an active application or invitation with this label.'
            );

        $this->assertSame(
            50,
            (int) $artist->fresh()->points_balance
        );

        $this->assertSame(
            1,
            LabelApplication::where('artist_profile_id', $artist->id)
                ->where('label_profile_id', $label->id)
                ->count()
        );

        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_free_application_allowance_resets_each_calender_month(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');

        try {
            [$user, $artist] = $this->createArtist(50);

            //Create five application dated in the previous month.
            $this->seedPreviousApplications($artist, 5);

            LabelApplication::where('artist_profile_id', $artist->id)->update([
                'created_at' => Carbon::parse('2026-10-15 12:00:00'),
            ]);

            $label = $this->createApprovedLabel();

            // November should start a fresh allowance.
            $this->apply($user, $label)
            ->assertCreated()
            ->assertJsonPath('points_charged', 0)
            ->assertJsonPath('points_balance', 50)
            ->assertJsonPath('monthly_applications_used', 1);

            $this->assertSame(
                50,
                (int) $artist->fresh()->points_balance
            );

            $this->assertDatabaseCount('point_transactions', 0);
        } finally {
            Carbon::setTestNow();
        }
    }


public function test_reapplication_is_blocked_before_30_days(): void
{
    Carbon::setTestNow('2026-11-10 12:00:00');

    try {
        [$user, $artist] = $this->createArtist(50);
        $label = $this->createApprovedLabel();

        LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'status' => 'rejected',
            'rejected_at' => now()->subDays(29),
            'used_points' => false,
        ]);

        $this->apply($user, $label)
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'You can reapply to this label after the 30-day waiting period.'
            )
            ->assertJsonStructure(['reapply_at']);

        $this->assertSame(50, (int) $artist->fresh()->points_balance);

        $this->assertSame(
            1,
            LabelApplication::where('artist_profile_id', $artist->id)
                ->where('label_profile_id', $label->id)
                ->count()
        );

        $this->assertDatabaseCount('point_transactions', 0);
    } finally {
        Carbon::setTestNow();
    }
}

public function test_reapplication_is_allowed_after_30_days(): void
{
    Carbon::setTestNow('2026-11-10 12:00:00');

    try {
        [$user, $artist] = $this->createArtist(50);
        $label = $this->createApprovedLabel();

        LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'status' => 'rejected',
            'rejected_at' => now()->subDays(31),
            'used_points' => false,
        ]);

        $this->apply($user, $label)
            ->assertCreated()
            ->assertJsonPath('points_charged', 0)
            ->assertJsonPath('points_balance', 50);

        $this->assertSame(50, (int) $artist->fresh()->points_balance);

        $this->assertSame(
            2,
            LabelApplication::where('artist_profile_id', $artist->id)
                ->where('label_profile_id', $label->id)
                ->count()
        );

        $this->assertDatabaseCount('point_transactions', 0);
    } finally {
        Carbon::setTestNow();
    }
}


public function test_reapplication_is_allowed_at_exactly_30_day_boundary(): void
{
    Carbon::setTestNow('2026-11-10 12:00:00');

    try {
        [$user, $artist] = $this->createArtist(50);
        $label = $this->createApprovedLabel();

        LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'status' => 'rejected',
            'rejected_at' => now()->subDays(30),
            'used_points' => false,
        ]);

        $this->apply($user, $label)
            ->assertCreated()
            ->assertJsonPath('points_charged', 0)
            ->assertJsonPath('points_balance', 50);

        $this->assertSame(
            2,
            LabelApplication::where('artist_profile_id', $artist->id)
                ->where('label_profile_id', $label->id)
                ->count()
        );
    } finally {
        Carbon::setTestNow();
    }
}


public function test_approved_label_can_invite_an_artist_without_charging_points(): void
{
    [$artistUser, $artist] = $this->createArtist(50);
    [$labelUser, $label] = $this->createLabelForInvitationTest();

    $response = $this->actingAs($labelUser, 'sanctum')
        ->postJson('/api/label/invitations', [
            'artist_profile_id' => $artist->id,
            'message' => 'We would like to discuss working together.',
        ]);

    $response->assertCreated()
        ->assertJsonPath('invitation.artist_profile_id', $artist->id)
        ->assertJsonPath('invitation.label_profile_id', $label->id)
        ->assertJsonPath('invitation.status', 'invited')
        ->assertJsonPath('invitation.used_points', false);

    $invitationId = $response->json('invitation.id');

    $this->assertDatabaseHas('label_applications', [
        'id' => $invitationId,
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $label->id,
        'status' => 'invited',
        'used_points' => false,
    ]);

    $this->assertSame(50, (int) $artist->fresh()->points_balance);

    $this->assertDatabaseCount('point_transactions', 0);
}



private function createLabelForInvitationTest(): array
{
    $user = User::create([
        'name' => 'Test Invitation Label',
        'email' => Str::uuid() . '@example.com',
        'password' => 'test-password',
        'role' => 'label',
        'label_status' => 'approved',
    ]);

    $user->forceFill([
        'email_verified_at' => now(),
    ])->save();

    $profile = LabelProfile::create([
        'user_id' => $user->id,
        'label_name' => 'Invitation Label ' . Str::uuid(),
        'office_address' => '1 Test Street',
        'office_city' => 'Lagos',
        'office_state' => 'Lagos',
        'office_country' => 'Nigeria',
    ]);

    return [$user, $profile];
}



public function test_invited_artist_can_accept_without_creating_duplicate_or_spending_points(): void
{
    [$artistUser, $artist] = $this->createArtist(50);
    [, $label] = $this->createLabelForInvitationTest();

    $invitation = LabelApplication::create([
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $label->id,
        'message' => 'We would like to work together.',
        'status' => 'invited',
        'source' =>'label_invitation',
        'invited_at' => now(),
        'invitation_expires_at' => now()->addDays(7),
        'used_points' => false,
    ]);

    $this->actingAs($artistUser, 'sanctum')
        ->postJson("/api/artist/invitations/{$invitation->id}/accept")
        ->assertOk()
        ->assertJsonPath('invitation.status', 'accepted');

    $this->assertSame(
        1,
        LabelApplication::where('artist_profile_id', $artist->id)
            ->where('label_profile_id', $label->id)
            ->count()
    );

    $this->assertNotNull($invitation->fresh()->invitation_responded_at);
    $this->assertSame(50, (int) $artist->fresh()->points_balance);
    $this->assertDatabaseCount('point_transactions', 0);
}


public function test_another_artist_cannot_accept_someone_elses_invitation(): void
{
    [, $intendedArtist] = $this->createArtist(50);
    [$otherArtistUser] = $this->createArtist(50);
    [, $label] = $this->createLabelForInvitationTest();

    $invitation = LabelApplication::create([
        'artist_profile_id' => $intendedArtist->id,
        'label_profile_id' => $label->id,
        'status' => 'invited',
        'source' => 'label_invitation',
        'invited_at' => now(),
        'invitation_expires_at' => now()->addDays(7),
        'used_points' => false,
    ]);

    $this->actingAs($otherArtistUser, 'sanctum')
        ->postJson("/api/artist/invitations/{$invitation->id}/accept")
        ->assertForbidden()
        ->assertJsonPath(
            'message',
            'This invitation does not belong to you.'
        );

    $this->assertSame('invited', $invitation->fresh()->status);
    $this->assertNull($invitation->fresh()->invitation_responded_at);
    $this->assertDatabaseCount('point_transactions', 0);
}


public function test_expired_invitation_cannot_be_accepted(): void
{
    Carbon::setTestNow('2026-11-10 12:00:00');

    try {
        [$artistUser, $artist] = $this->createArtist(50);
        [, $label] = $this->createLabelForInvitationTest();

        $invitation = LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'status' => 'invited',
            'source' => 'label_invitation',
            'invited_at' => now()->subDays(8),
            'invitation_expires_at' => now()->subDay(),
            'used_points' => false,
        ]);

        $this->actingAs($artistUser, 'sanctum')
            ->postJson("/api/artist/invitations/{$invitation->id}/accept")
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'This invitation has expired.'
            );

        $this->assertSame('expired', $invitation->fresh()->status);
        $this->assertNull(
            $invitation->fresh()->invitation_responded_at
        );
        $this->assertSame(50, (int) $artist->fresh()->points_balance);
        $this->assertDatabaseCount('point_transactions', 0);
    } finally {
        Carbon::setTestNow();
    }
}


public function test_invitation_cannot_be_accepted_twice(): void
{
    [$artistUser, $artist] = $this->createArtist(50);
    [, $label] = $this->createLabelForInvitationTest();

    $invitation = LabelApplication::create([
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $label->id,
        'status' => 'invited',
        'source' => 'label_invitation',
        'invited_at' => now(),
        'invitation_expires_at' => now()->addDays(7),
        'used_points' => false,
    ]);

    $this->actingAs($artistUser, 'sanctum')
        ->postJson("/api/artist/invitations/{$invitation->id}/accept")
        ->assertOk()
        ->assertJsonPath('invitation.status', 'accepted');

    $responseTime = $invitation->fresh()->invitation_responded_at;

    $this->actingAs($artistUser, 'sanctum')
        ->postJson("/api/artist/invitations/{$invitation->id}/accept")
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'This invitation has already been responded to or is no longer active.'
        );

    $this->assertSame('accepted', $invitation->fresh()->status);
    $this->assertEquals(
        $responseTime,
        $invitation->fresh()->invitation_responded_at
    );
    $this->assertSame(50, (int) $artist->fresh()->points_balance);
    $this->assertDatabaseCount('point_transactions', 0);
}


public function test_invited_artist_can_decline_without_spending_points(): void
{
    [$artistUser, $artist] = $this->createArtist(50);
    [, $label] = $this->createLabelForInvitationTest();

    $invitation = LabelApplication::create([
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $label->id,
        'status' => 'invited',
        'source' => 'label_invitation',
        'invited_at' => now(),
        'invitation_expires_at' => now()->addDays(7),
        'used_points' => false,
    ]);

    $this->actingAs($artistUser, 'sanctum')
        ->postJson("/api/artist/invitations/{$invitation->id}/decline")
        ->assertOk()
        ->assertJsonPath('invitation.status', 'declined');

    $this->assertSame('declined', $invitation->fresh()->status);
    $this->assertNotNull($invitation->fresh()->invitation_responded_at);

    $this->assertSame(
        1,
        LabelApplication::where('artist_profile_id', $artist->id)
            ->where('label_profile_id', $label->id)
            ->count()
    );

    $this->assertSame(50, (int) $artist->fresh()->points_balance);
    $this->assertDatabaseCount('point_transactions', 0);
}


public function test_declined_invitation_cannot_later_be_accepted(): void
{
    [$artistUser, $artist] = $this->createArtist(50);
    [, $label] = $this->createLabelForInvitationTest();

    $invitation = LabelApplication::create([
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $label->id,
        'status' => 'invited',
        'source' => 'label_invitation',
        'invited_at' => now(),
        'invitation_expires_at' => now()->addDays(7),
        'used_points' => false,
    ]);

    $this->actingAs($artistUser, 'sanctum')
        ->postJson("/api/artist/invitations/{$invitation->id}/decline")
        ->assertOk()
        ->assertJsonPath('invitation.status', 'declined');

    $responseTime = $invitation->fresh()->invitation_responded_at;

    $this->actingAs($artistUser, 'sanctum')
        ->postJson("/api/artist/invitations/{$invitation->id}/accept")
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'This invitation has already been responded to or is no longer active.'
        );

    $this->assertSame('declined', $invitation->fresh()->status);
    $this->assertEquals(
        $responseTime,
        $invitation->fresh()->invitation_responded_at
    );
    $this->assertSame(50, (int) $artist->fresh()->points_balance);
    $this->assertDatabaseCount('point_transactions', 0);
}


public function test_label_can_invite_artist_during_reapplication_cooldown(): void
{
    [$artistUser, $artist] = $this->createArtist(50);
    [$labelUser, $label] = $this->createLabelForInvitationTest();

    // Simulate an application rejected recently.
    LabelApplication::create([
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $label->id,
        'status' => 'rejected',
        'rejected_at' => now()->subDays(5),
        'used_points' => false,
    ]);

    // The same artist cannot submit another normal application yet.
    $this->actingAs($artistUser, 'sanctum')
        ->postJson("/api/labels/{$label->id}/applications", [
            'message' => 'I would like to apply again.',
        ])
        ->assertUnprocessable();

    // But the label can invite the artist during the cooldown.
    $this->actingAs($labelUser, 'sanctum')
        ->postJson('/api/label/invitations', [
            'artist_profile_id' => $artist->id,
            'message' => 'We would like to invite you to our label.',
        ])
        ->assertCreated()
        ->assertJsonPath('invitation.status', 'invited');

    $this->assertDatabaseHas('label_applications', [
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $label->id,
        'status' => 'invited',
        'used_points' => false,
    ]);

    $this->assertSame(50, (int) $artist->fresh()->points_balance);
    $this->assertDatabaseCount('point_transactions', 0);
}


public function test_label_invitations_do_not_count_toward_monthly_free_applications(): void
{
    [$artistUser, $artist] = $this->createArtist(50);

    // Five invitations should not consume the artist's free allowance.
    for ($i = 0; $i < 5; $i++) {
        [, $label] = $this->createLabelForInvitationTest();

        LabelApplication::create([
            'artist_profile_id' => $artist->id,
            'label_profile_id' => $label->id,
            'status' => 'invited',
            'source' => 'label_invitation',
            'invited_at' => now(),
            'invitation_expires_at' => now()->addDays(7),
            'used_points' => false,
        ]);
    }




$this->assertSame(
    0,
    LabelApplication::query()
        ->where('artist_profile_id', $artist->id)
        ->where('source', 'artist_application')
        ->where('created_at', '>=', now()->startOfMonth())
        ->where('created_at', '<', now()->startOfMonth()->addMonth())
        ->count(),
    'The artist should have zero artist-submitted applications before this test.'
);


    // A normal application should still be free.
    $newLabel = $this->createApprovedLabel();

    $this->apply($artistUser, $newLabel)
        ->assertCreated()
        ->assertJsonPath('points_charged', 0)
        ->assertJsonPath('monthly_applications_used', 1);

    $this->assertSame(50, (int) $artist->fresh()->points_balance);

    $this->assertDatabaseHas('label_applications', [
        'artist_profile_id' => $artist->id,
        'label_profile_id' => $newLabel->id,
        'source' => 'artist_application',
        'status' => 'pending',
        'used_points' => false,
    ]);

    $this->assertSame(
        6,
        LabelApplication::where('artist_profile_id', $artist->id)->count()
    );
}


}
