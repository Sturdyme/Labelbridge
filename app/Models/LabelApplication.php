<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelApplication extends Model
{
    protected $fillable = [
        'artist_profile_id',
        'label_profile_id',
        'message',
        'status',
        'source',
        'rejected_at',
        'invited_at',
        'invitation_expires_at',
        'invitation_responded_at',
        'used_points'
    ];

    protected function casts(): array
    {
        return [
            'rejected_at' => 'datetime',
            'invited_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'invitation_responded_at' => 'datetime',
            'used_points' => 'boolean',
        ];
    }

    public function artistProfile(): BelongsTo
    {
        return $this->belongsTo(ArtistProfile::class);
    }

    public function labelProfile(): BelongsTo
    {
        return $this->belongsTo(LabelProfile::class);
    }
}
