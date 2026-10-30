<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkReportItem extends Model
{
    protected $fillable = ['work_report_id', 'assignment_work_item_id', 'description', 'actual_volume', 'unit', 'note', 'revision'];

    protected function casts(): array
    {
        return ['actual_volume' => 'decimal:2'];
    }

    public function report(): BelongsTo { return $this->belongsTo(WorkReport::class, 'work_report_id'); }
    public function assignmentWorkItem(): BelongsTo { return $this->belongsTo(AssignmentWorkItem::class); }
}
