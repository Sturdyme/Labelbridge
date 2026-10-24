<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        //Ensure the link matches this user's email address.

        if (! hash_equals($hash,sha1($user->getEmailForVerification())
            )) {
        return response()->json([
            'message' => 'Invalid email verification link.'
        ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email has already neem verified'
            ]);
        }

        if ($user->markEmailAsverified()) {
            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => [
                'id' => $user->id,
                'email'=> $user->email,
                'email_verified_at' => $user->email_verified_at,
            ],
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email is already verified.'
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'A new verification email has been sent.'
        ]);
    }
}
