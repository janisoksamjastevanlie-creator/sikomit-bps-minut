<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityTarget extends Model
{
    protected $fillable = ['activity_id', 'description', 'target_volume', 'unit', 'territory', 'starts_on', 'ends_on', 'sort_order'];

    protected function casts(): array
    {
        return [
            'target_volume' => 'decimal:2',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
