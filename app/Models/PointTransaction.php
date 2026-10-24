<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointTransaction extends Model
{
    protected $fillable = [
        'artist_profile_id',
        'type',
        'points',
        'balance_after',
        'payment_reference',
        'label_application_id',
        'description',
    ];

    public function artistProfile(): BelongsTo
    {
        return $this->belongsTo(ArtistProfile::class);
    
    }

    public function labelApplication(): BelongsTo
    {
        return $this->belongsTo(LabelApplication::class);
    }
}
