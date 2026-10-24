<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\LabelApplication;

class LabelProfile extends Model
{
    protected $fillable = [
        'user_id',
        'label_name',
        'bio',
        'logo',
        'preferred_genres',
        'artist_requirements',
        'office_address',
        'office_city',
        'office_state',
        'office_country',
        'certification_status',
        'certified_at',
        'certification_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'preferred_genres' => 'array',
            'address_verified_at' => 'datetime',
            'certified_at' => 'datetime',
            'certification_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

   public function applications(): HasMany
{
    return $this->hasMany(LabelApplication::class);
}

  public function certificationPurchases(): HasMany
{
    return $this->hasMany(LabelCertificationPurchase::class);
}
}
