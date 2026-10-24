<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\PointTransaction;

class ArtistProfile extends Model
{
    protected $fillable = [
        'user_id',
        'stage_name',
        'bio',
        'state',
        'profile_photo',
        'creative_roles',
        'social_handles',
        'social_visibility_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'creative_roles' => 'array',
            'social_handles' => 'array',
            'social_visibility_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(LabelApplication::class);
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }
}
