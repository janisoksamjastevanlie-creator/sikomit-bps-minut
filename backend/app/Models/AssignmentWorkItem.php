<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentWorkItem extends Model
{
    protected $fillable = [
        'assignment_member_id', 'activity_target_id', 'description', 'target_volume',
        'unit', 'territory', 'starts_on', 'ends_on', 'work_category',
    ];

    protected function casts(): array
    {
        return [
            'target_volume' => 'decimal:2',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(AssignmentMember::class, 'assignment_member_id');
    }

    public function activityTarget(): BelongsTo
    {
        return $this->belongsTo(ActivityTarget::class);
    }
}
