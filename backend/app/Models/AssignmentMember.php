<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssignmentMember extends Model
{
    protected $fillable = ['assignment_id', 'partner_id', 'status', 'contract_category', 'assigned_at', 'created_by'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(AssignmentWorkItem::class);
    }
}
