<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelCertificationPurchase;
use App\Models\LabelProfile;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class LabelCertificationController extends Controller
{
    public function initialize(Request $request, PaystackService $paystack): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->role !== 'label') {
            return response()->json([
                'message' => 'Only label accounts can purchase certification.',
            ], 403);
        }


        if ($user->label_status !== 'approved') {
            return response()->json([
                'message' => 'Your label account must be approved before purchasing certification.'
            ], 403);
        }

     $labelProfile = LabelProfile::where('user_id', $user->id)->first();

        if (! $labelProfile) {
            return response()->json([
                'message' => 'Complete your label profile before purchasing certification.',
            ], 422);
        }

        $amountKobo = (int) config('label_certification.amount_kobo', 5000000);

        if ($amountKobo <= 0) {
            return response()->json([
                'message' => 'Certification pricing is not configured correctly.'
            ], 500);
        }

        $purchase = LabelCertificationPurchase::create([
            'label_profile_id' => $labelProfile->id,
            'reference' => 'LBC-' . Str::upper(Str::random(24)),
            'amount_kobo' => $amountKobo,
            'status' => 'pending',
        ]);

        try {
            $transaction = $paystack->initializeTransaction(
                $user->email,
                $purchase->amount_kobo,
                $purchase->reference,
                [
                    'purpose' => 'label_certification',
                    'purchase_id' => $purchase->id,
                    'label_profile_id' => $labelProfile->id,
                ]
            );

            return response()->json([
                'message' => 'Certificaion payment initiated successfully',
                'reference' => $purchase->reference,
                'amount_kobo' => $purchase->amount_kobo,
                'amount_naira' => $purchase->amount_kobo / 100,
                'authorization_url' => $transaction['authorization_url'],
                'access_code' => $transaction['access_code'] ?? null,
            ]);

        } catch (Throwable $exception) {
            report($exception);

            $purchase->update(['status' => 'failed']);

            return response()->json([
                'message' => 'Unable to initialize certification payment. Please try again.',
            ], 502);
        }

    }

// Verify payment and activate certification for 12 months.


        public function verify(Request $request, PaystackService $paystack): JsonResponse
        {
         $validated = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
         ]);

         $user = $request->user();

         if (! $user || $user->role !== 'label') {
            return response()->json([
                'message' => 'Only label accounts can verify certification payments.',
            ], 403);
         }

         if ($user->label_status !== 'approved') {
    return response()->json([
        'message' => 'Only approved labels can activate certification.',
    ], 403);
}

         $labelProfile = LabelProfile::where('user_id', $user->id)->first();

         if (! $labelProfile) {
            return response()->json([
                'message' => 'Label profile not found.',
            ], 422);
         }

         $purchase = LabelCertificationPurchase::where(
            'reference',
            $validated['reference']
         )->where(
            'label_profile_id',
            $labelProfile->id
         )->first();

         if (! $purchase) {
            return response()->json([
                'message' => 'Certification purchase not found.',
            ], 404);
         }

         if ($purchase->status === 'paid') {
            return response()->json([
                'message' => 'This certification payment has already been verified.',
                'certification_status' => $labelProfile->certification_status,
                'certification_expires_at' => $labelProfile->certification_expires_at,
            ]);
         }

         if ($purchase->status !== 'pending') {
            return response()->json([
                'message' => 'This purchase is not awaiting payment.',
            ], 422);
         }

         try {
            $transaction = $paystack->verifyTransaction($purchase->reference);
         } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to verify payment right now. Please try again.'
            ], 502);
         }

         if (
            ($transaction['status'] ?? null) !== 'success' || (int) ($transaction['amount'] ?? 0) !== $purchase->amount_kobo || ($transaction['currency'] ?? null) !== 'NGN' || ($transaction['reference'] ?? null) !== $purchase->reference || ! isset($transaction['id']) || ! is_scalar($transaction['id'])
         ) {
            return response()->json([
                'message' => 'Payment has not been confirmed for this purchase.',
            ], 422);
         }

         $result = DB::transaction(function () use ($purchase, $labelProfile, $transaction) {
            $lockedPurchase = LabelCertificationPurchase::whereKey($purchase->id)
            ->lockForUpdate()
            ->firstOrFail();

            $lockedProfile = LabelProfile::whereKey($labelProfile->id)
            ->lockForUpdate()
            ->firstOrFail();

            if ($lockedPurchase->status === 'paid') {
                return [
                    'already_paid' => true,
                    'expires_at' => $lockedProfile->certification_expires_at,
                ];
            }

            if ($lockedPurchase->status !== 'pending') {
                return [
                    'already_paid' => false,
                    'invalid_status' => true,
                ];
            }

            $lockedPurchase->update([
                'status' => 'paid',
                'paid_at' => now(),
                'provider_transaction_id' => (string) $transaction['id'],
            ]);

            $now = now();

      $currentExpiry = $lockedProfile->certification_expires_at;

     $isCurrentlyCertified =
    $lockedProfile->certification_status === 'active'
    && $currentExpiry !== null
    && $currentExpiry->isFuture();

    $renewalStartsAt = $isCurrentlyCertified
    ? $currentExpiry->copy()
    : $now->copy();

   $lockedProfile->update([
    'certification_status' => 'active',
    'certified_at' => $isCurrentlyCertified
        ? $lockedProfile->certified_at
        : $now,
    'certification_expires_at' => $renewalStartsAt->addMonths(
        (int) config('label_certification.duration_months', 12)
    ),
]);
            return [
                'already_paid' => false,
                'expires_at' => $lockedProfile->certification_expires_at,
            ];
         });

         if ($result['already_paid']) {
            return response()->json([
                'message' => 'This certification payment has already been verified.',
                'certification_status' => $labelProfile->fresh()->certification_status,
                'certification_expires_at' => $result['expires_at'],
            ]);
         }

         if ($result['invalid_status'] ?? false) {
            return response()->json([
                'message' => 'This purchase is no longer awaiting payment.'
            ], 422);
         }

         return response()->json([
            'message' => 'Label activated successfully.',
            'certification_status' => 'active',
            'certification_expires_at' => $result['expires_at'],
         ]);
        }
}
