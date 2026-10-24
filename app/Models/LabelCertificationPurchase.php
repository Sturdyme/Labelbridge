<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabelCertificationPurchase extends Model
{
    protected $fillable = [
        'label_profile_id',
        'reference',
        'amount_kobo',
        'status',
        'paid_at',
        'provider_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function labelProfile(): BelongsTo
    {
        return $this->belongsTo(LabelProfile::class);
    }
}
