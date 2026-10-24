<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LabelProfile;
use Illuminate\Http\Request;

class LabelDiscoveryController extends Controller
{
    public function index(Request $request) 
    {
        //Only artist accounts can discover labels.
        if ($request->user()->role !== 'artist') {
            return response()->json([
                'message' => 'Only artist accounts can discover labels.'
            ], 403);
        }

        //Return profiles belonging to approved labels only.
        $labels = LabelProfile::query()
       ->whereHas('user', function ($query) {
        $query->where('label_status', 'approved');
       })  
       ->with('user:id,label_status')
       ->latest()
       ->get()
       ->map(function (LabelProfile $label) {
        $isCertified = $label->user?->label_status == 'approved'
        && $label->certification_status === 'active'
        && $label->certification_expires_at !== null
        && $label->certification_expires_at->isFuture();

      return [
        'id' => $label->id,
        'label_name' => $label->label_name,
        'bio' => $label->bio,
        'logo' => $label->logo,
        'preferred_genres' => $label->preferred_genres,
        'artist_requirements' => $label->artist_requirements,
        'is_certified' => $isCertified,
      ];
       })->values();


       return response()->json([
        'message' => 'Approved labels retrieved successfully',
        'labels' => $labels,
       ]);

}
}
