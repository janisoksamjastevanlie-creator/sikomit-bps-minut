<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkReviewAction extends Model
{
    public $timestamps = false;

    protected $fillable = ['work_report_id', 'reviewer_id', 'outcome', 'note', 'revision', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function report(): BelongsTo { return $this->belongsTo(WorkReport::class, 'work_report_id'); }
}
