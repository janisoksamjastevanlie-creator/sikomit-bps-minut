<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_code', 'sobat_id', 'full_name', 'partner_type', 'phone', 'email',
        'address', 'district', 'village', 'province', 'verification_status',
        'verification_note', 'is_active', 'is_demo', 'created_by', 'updated_by',
        'verified_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_demo' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PartnerDocument::class);
    }

    public function verificationHistory(): HasMany
    {
        return $this->hasMany(PartnerVerificationHistory::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class, 'partner_id');
    }
}
