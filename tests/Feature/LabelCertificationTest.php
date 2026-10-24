<?php

namespace Tests\Feature;

use App\Models\LabelCertificationPurchase;
use App\Models\LabelProfile;
use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class LabelCertificationTest extends TestCase
{
    use RefreshDatabase;

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
            'certification_status' => 'inactive',
            'certified_at' => null,
            'certification_expires_at' => null,
        ]);

        return [$user, $profile];
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    public function test_only_approved_labels_can_initialize_certification_payment(): void
    {
        [$label] = $this->createLabel('pending');

        Sanctum::actingAs($label);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldNotReceive('initializeTransaction');
        $this->app->instance(PaystackService::class, $paystack);

        $this->postJson('/api/label/certification/purchase')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Your label account must be approved before purchasing certification.'
            );
    }

    public function test_approved_label_can_initialize_certification_payment(): void
    {
        Config::set('label_certification.amount_kobo', 5000000);

        [$label] = $this->createLabel();

        Sanctum::actingAs($label);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('initializeTransaction')
            ->once()
            ->withArgs(function (
                $email,
                $amount,
                $reference,
                $metadata
            ) use ($label) {
                return $email === $label->email
                    && $amount === 5000000
                    && str_starts_with($reference, 'LBC-')
                    && $metadata['purpose'] === 'label_certification';
            })
            ->andReturn([
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test-access-code',
            ]);

        $this->app->instance(PaystackService::class, $paystack);

        $response = $this->postJson('/api/label/certification/purchase');

        $response->assertOk()
            ->assertJsonPath('amount_kobo', 5000000)
            ->assertJsonPath('amount_naira', 50000)
            ->assertJsonPath(
                'authorization_url',
                'https://checkout.paystack.com/test'
            );

        $this->assertDatabaseHas('label_certification_purchases', [
            'label_profile_id' => LabelProfile::where(
                'user_id',
                $label->id
            )->value('id'),
            'amount_kobo' => 5000000,
            'status' => 'pending',
        ]);
    }

    public function test_successful_payment_activates_certification_for_twelve_months(): void
    {
        [$label, $profile] = $this->createLabel();

        $purchase = LabelCertificationPurchase::create([
            'label_profile_id' => $profile->id,
            'reference' => 'LBC-TEST-SUCCESS-001',
            'amount_kobo' => 5000000,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($label);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('verifyTransaction')
            ->once()
            ->with($purchase->reference)
            ->andReturn([
                'status' => 'success',
                'amount' => 5000000,
                'currency' => 'NGN',
                'reference' => $purchase->reference,
                'id' => 123456789,
            ]);

        $this->app->instance(PaystackService::class, $paystack);

        $this->travelTo(now());

        $this->postJson('/api/label/certification/verify', [
            'reference' => $purchase->reference,
        ])->assertOk()
            ->assertJsonPath('certification_status', 'active');

        $profile->refresh();
        $purchase->refresh();

        $this->assertSame('active', $profile->certification_status);
       $this->assertSame(
    now()->addMonths(12)->format('Y-m-d H:i:s'),
    $profile->certification_expires_at->format('Y-m-d H:i:s')
);
        $this->assertSame('paid', $purchase->status);
        $this->assertSame('123456789', $purchase->provider_transaction_id);
    }

    public function test_incorrect_payment_amount_does_not_activate_certification(): void
    {
        [$label, $profile] = $this->createLabel();

        $purchase = LabelCertificationPurchase::create([
            'label_profile_id' => $profile->id,
            'reference' => 'LBC-TEST-WRONG-AMOUNT',
            'amount_kobo' => 5000000,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($label);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('verifyTransaction')
            ->once()
            ->andReturn([
                'status' => 'success',
                'amount' => 10000,
                'currency' => 'NGN',
                'reference' => $purchase->reference,
                'id' => 123456790,
            ]);

        $this->app->instance(PaystackService::class, $paystack);

        $this->postJson('/api/label/certification/verify', [
            'reference' => $purchase->reference,
        ])->assertUnprocessable();

        $this->assertSame('inactive', $profile->fresh()->certification_status);
        $this->assertSame('pending', $purchase->fresh()->status);
    }

    public function test_failed_paystack_transaction_does_not_activate_certification(): void
    {
        [$label, $profile] = $this->createLabel();

        $purchase = LabelCertificationPurchase::create([
            'label_profile_id' => $profile->id,
            'reference' => 'LBC-TEST-FAILED',
            'amount_kobo' => 5000000,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($label);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('verifyTransaction')
            ->once()
            ->andReturn([
                'status' => 'failed',
                'amount' => 5000000,
                'currency' => 'NGN',
                'reference' => $purchase->reference,
                'id' => 123456791,
            ]);

        $this->app->instance(PaystackService::class, $paystack);

        $this->postJson('/api/label/certification/verify', [
            'reference' => $purchase->reference,
        ])->assertUnprocessable();

        $this->assertSame('inactive', $profile->fresh()->certification_status);
        $this->assertSame('pending', $purchase->fresh()->status);
    }

    public function test_reverifying_paid_purchase_does_not_extend_expiry(): void
    {
        [$label, $profile] = $this->createLabel();

        $expiry = now()->addMonths(8);

        $profile->update([
            'certification_status' => 'active',
            'certified_at' => now()->subMonths(4),
            'certification_expires_at' => $expiry,
        ]);

        $purchase = LabelCertificationPurchase::create([
            'label_profile_id' => $profile->id,
            'reference' => 'LBC-TEST-ALREADY-PAID',
            'amount_kobo' => 5000000,
            'status' => 'paid',
            'paid_at' => now(),
            'provider_transaction_id' => '123456792',
        ]);

        Sanctum::actingAs($label);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldNotReceive('verifyTransaction');
        $this->app->instance(PaystackService::class, $paystack);

        $this->postJson('/api/label/certification/verify', [
            'reference' => $purchase->reference,
        ])->assertOk();

        $this->assertSame(
    $expiry->format('Y-m-d H:i:s'),
    $profile->fresh()->certification_expires_at->format('Y-m-d H:i:s')
);
    }

    public function test_new_purchase_extends_an_existing_active_certification(): void
    {
        [$label, $profile] = $this->createLabel();

        $expiry = now()->addMonths(6);

        $profile->update([
            'certification_status' => 'active',
            'certified_at' => now()->subMonths(6),
            'certification_expires_at' => $expiry,
        ]);

        $purchase = LabelCertificationPurchase::create([
            'label_profile_id' => $profile->id,
            'reference' => 'LBC-TEST-RENEWAL',
            'amount_kobo' => 5000000,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($label);

        $paystack = Mockery::mock(PaystackService::class);
        $paystack->shouldReceive('verifyTransaction')
            ->once()
            ->andReturn([
                'status' => 'success',
                'amount' => 5000000,
                'currency' => 'NGN',
                'reference' => $purchase->reference,
                'id' => 123456793,
            ]);

        $this->app->instance(PaystackService::class, $paystack);

        $this->postJson('/api/label/certification/verify', [
            'reference' => $purchase->reference,
        ])->assertOk();

        $this->assertSame(
    $expiry->copy()->addMonths(12)->format('Y-m-d H:i:s'),
    $profile->fresh()->certification_expires_at->format('Y-m-d H:i:s')
);
    }

    public function test_admin_can_revoke_certification_without_rejecting_label(): void
    {
        [$label, $profile] = $this->createLabel();

        $profile->update([
            'certification_status' => 'active',
            'certified_at' => now(),
            'certification_expires_at' => now()->addYear(),
        ]);

        Sanctum::actingAs($this->createAdmin());

        $this->patchJson(
            "/api/admin/labels/{$label->id}/certification/revoke"
        )->assertOk()
            ->assertJsonPath('certification_status', 'revoked');

        $this->assertSame('revoked', $profile->fresh()->certification_status);
        $this->assertSame('approved', $label->fresh()->label_status);
    }
}
