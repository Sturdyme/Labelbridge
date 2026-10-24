<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArtistProfile;
use App\Models\PointPurchase;
use App\Models\PointTransaction;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class PointPurchaseController extends Controller
{
    public function initialize(Request $request, PaystackService $paystack): JsonResponse
    {
        $user = $request->user();

        if (! $user || $user->role !== 'artist') {
            return response()->json([
                'message' => 'Only artist accounts can purchase points.',
            ], 403);
        }

        $artistProfile = ArtistProfile::where('user_id', $user->id)->first();

        if (! $artistProfile) {
            return response()->json([
                'message' => 'Complete your artist profile before purchasing points.',
            ], 422);
        }

        $validated = $request->validate([
            'package_code' => [
                'required',
                'string',
                Rule::in(array_keys(config('point_packages', []))),
            ],
        ]);

        $package = config('point_packages.'.$validated['package_code']);
        $purchase = PointPurchase::create([
            'artist_profile_id' => $artistProfile->id,
            'reference' => 'LBP-'.Str::upper(Str::random(20)),
            'package_code' => $validated['package_code'],
            'amount_kobo' => $package['amount_kobo'],
            'points' => $package['points'],
            'status' => 'pending',
        ]);

        try {
            $transaction = $paystack->initializeTransaction(
                $user->email,
                $purchase->amount_kobo,
                $purchase->reference,
                [
                    'purchase_id' => $purchase->id,
                    'artist_profile_id' => $artistProfile->id,
                    'package_code' => $purchase->package_code,
                ]
            );

            return response()->json([
                'message' => 'Payment initialized successfully.',
                'reference' => $purchase->reference,
                'authorization_url' => $transaction['authorization_url'],
                'access_code' => $transaction['access_code'] ?? null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $purchase->update([
                'status' => 'failed',
            ]);

            return response()->json([
                'message' => 'Unable to initialize payment. Please try again.',
            ], 502);
        }
    }

    public function verify(Request $request, PaystackService $paystack): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
        ]);

        $user = $request->user();

        if (! $user || $user->role !== 'artist') {
            return response()->json([
                'message' => 'Only artist accounts can verify point purchases.',
            ], 403);
        }

        $artistProfile = ArtistProfile::where('user_id', $user->id)->first();

        if (! $artistProfile) {
            return response()->json([
                'message' => 'Artist profile not found.',
            ], 422);
        }

        $purchase = PointPurchase::where('reference', $validated['reference'])
            ->where('artist_profile_id', $artistProfile->id)
            ->first();

        if (! $purchase) {
            return response()->json([
                'message' => 'Point purchase not found.',
            ], 404);
        }

        if ($purchase->status === 'paid') {
            return response()->json([
                'message' => 'This purchase has already been credited.',
                'points_balance' => $artistProfile->fresh()->points_balance,
            ]);
        }

        try {
            $transaction = $paystack->verifyTransaction($purchase->reference);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to verify payment right now. Please try again.',
            ], 502);
        }

        if (
            ($transaction['status'] ?? null) !== 'success'
            || (int) ($transaction['amount'] ?? 0) !== $purchase->amount_kobo
            || ($transaction['currency'] ?? null) !== 'NGN'
            || ($transaction['reference'] ?? null) !== $purchase->reference
            || ! isset($transaction['id'])
            || ! is_scalar($transaction['id'])
        ) {
            return response()->json([
                'message' => 'Payment has not been confirmed for this purchase.',
            ], 422);
        }

        $pointsBalance = DB::transaction(function () use ($purchase, $artistProfile, $transaction) {
            $lockedPurchase = PointPurchase::whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedArtist = ArtistProfile::whereKey($artistProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPurchase->status !== 'paid') {
                $lockedArtist->increment('points_balance', $lockedPurchase->points);

                $lockedPurchase->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'provider_transaction_id' => (string) $transaction['id'],
                ]);

                PointTransaction::create([
                    'artist_profile_id' => $lockedArtist->id,
                    'type' => 'purchase',
                    'points' => $lockedPurchase->points,
                    'balance_after' => $lockedArtist->fresh()->points_balance,
                    'payment_reference' => $lockedPurchase->reference,
                    'description' => 'Purchase of '.$lockedPurchase->points.' points',
                ]);
            }

            return $lockedArtist->fresh()->points_balance;
        });

        return response()->json([
            'message' => 'Points credited successfully.',
            'points_added' => $purchase->points,
            'points_balance' => $pointsBalance,
        ]);
    }
}
