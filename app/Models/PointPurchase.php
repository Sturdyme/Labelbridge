<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointPurchase extends Model
{
    protected $fillable = [
        'artist_profile_id',
        'reference',
        'package_code',
        'amount_kobo',
        'points',
        'status',
        'paid_at',
        'provider_transaction_id',
    ];

    public function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'points' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function artistProfile(): BelongsTo
    {
        return $this->belongsTo(ArtistProfile::class);
    }
}
