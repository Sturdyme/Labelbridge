<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminLabelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => [
                'sometimes',
                'required',
                Rule::in(['pending', 'approved', 'rejected']),
            ],
        ]);

        $labels = User::query()
        ->where('role', 'label')
        ->when(
            isset($validated['status']),
            fn ($query) => $query->where(
                'label_status',
                $validated['status']
            )
        )
        ->orderBy('created_at')
        ->get([
            'id',
            'name',
            'email',
            'label_status',
            'email_verified_at',
            'created_at',
        ]);

        return response()->json([
            'labels' => $labels,
        ]);
    }

    public function updateStatus(Request $request, User $user):JsonResponse 
    {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in(['approved', 'rejected']),
            ],
        ]);

        if ($user->role !== 'label') {
            return response()->json([
                'message' => 'This account is not a label.',
            ], 422);
        }

        $user->label_status = $validated['status'];
        $user->save();

        return response()->json([
            'message' => $validated['status'] === 'approved'
            ? 'Label approved successfully.' : 'Label rejected successfully.', 
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'label_status' => $user->label_status,

            ],
            
        ]);
    }

    public function revokeCertification(User $user): JsonResponse
    {
        if ($user->role !== 'label') {
            return response()->json([
                'message' => 'This account is not a label.'
            ], 422);
        }

        $labelProfile = \App\Models\LabelProfile::where(
            'user_id',
            $user->id
        )->first();

        if (! $labelProfile) {
            return response()->json([
                'message' => 'Label profile not found.'
            ], 404);
        }

        $labelProfile->update([
            'certification_status' => 'revoked',
        ]);

        return response()->json([
            'message' => 'Label certification revoked successfully.',
            'certification_status' => 'revoked',
            'label_name' => $labelProfile->label_name,
        ]);
    }
}
