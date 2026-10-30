<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'year', 'period', 'responsible_user_id', 'responsible_name', 'activity_type',
        'description', 'territory', 'target_volume', 'unit', 'starts_on', 'ends_on',
        'administrative_requirements', 'partner_need', 'status', 'is_demo', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'target_volume' => 'decimal:2',
            'partner_need' => 'integer',
            'administrative_requirements' => 'array',
            'is_demo' => 'boolean',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ActivityTarget::class)->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
