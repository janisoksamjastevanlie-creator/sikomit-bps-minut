<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkReport extends Model
{
    protected $fillable = [
        'assignment_member_id', 'spk_document_id', 'status', 'summary',
        'submitted_by', 'submitted_at', 'accepted_by', 'accepted_at', 'revision',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'accepted_at' => 'datetime', 'revision' => 'integer'];
    }

    public function member(): BelongsTo { return $this->belongsTo(AssignmentMember::class, 'assignment_member_id'); }
    public function spk(): BelongsTo { return $this->belongsTo(Document::class, 'spk_document_id'); }
    public function items(): HasMany { return $this->hasMany(WorkReportItem::class); }
    public function evidence(): HasMany { return $this->hasMany(WorkEvidence::class); }
    public function reviews(): HasMany { return $this->hasMany(WorkReviewAction::class); }
}
