<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
      $validated = $request->validate([
        'name' => ['required', 'string', 'max:100'],
        'email' => [
            'required',
            'string',
            'email:rfc',
            'max:255',
            'unique:users,email',
        ],
        'password' => [
            'required',
            'confirmed',
            Password::min(12)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised(),
        ],
        'role' => [
            'required',
            Rule::in(['artist', 'label']),
        ],
      ]);

      $user = User::create([
        'name' => $validated['name'],
        'email' => mb_strtolower($validated['email']),
        'password' => Hash::make($validated['password']),
        'role' => $validated['role'],
        'label_status' => $validated['role'] === 'label'
        ? 'pending'
        : null,
      ]);

      $user->sendEmailVerificationNotification();

      return response()->json([
        'message' => $user->role === 'label'
        ? 'Registration successful. Verify your email and wait label approval.'
        : 'Registration successful. Please verify your email.',
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'label_status' => $user->label_status,
            'email_verified_at' => $user->email_verified_at,
        ],
      ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower($validated['email']);

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check(
            $validated['password'],
            $user->password
        )) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.'
            ], 401);
        }

        //Remove this login session's previous token only if needed.
        //Multiple devices can otherwise remain logged in

        $token = $user->createToken('labelbridge-api')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userData($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    //Return the authemticated user.

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userData($request->user()),
        ]);
    }

    //Limit which user fields are returned by the Api

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'label_status' => $user->label_status,
            'emaile_verified_at' => $user->email_verified_at,
        ];
    }
}
