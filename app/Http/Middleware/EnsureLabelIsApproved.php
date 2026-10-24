<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLabelIsApproved
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'label') {
            return response()->json([
                'message' => 'This feature is only available to labels.',
            ], 403);
        }
 
        if ($user->label_status !== 'approved') {
            return response()->json([
                'message' => 'Your label account is waiting approval.',
                'label_status' => $user->label_status
            ], 403);
        }
        return $next($request);
    }
}
