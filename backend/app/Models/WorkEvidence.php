<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkEvidence extends Model
{
    protected $fillable = ['work_report_id', 'original_filename', 'storage_path', 'mime_type', 'size_bytes', 'sha256', 'scan_status', 'uploaded_by'];

    public function report(): BelongsTo { return $this->belongsTo(WorkReport::class, 'work_report_id'); }
}
