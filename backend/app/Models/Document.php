<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'document_type', 'worker_category', 'document_mode', 'document_number', 'activity_id',
        'assignment_id', 'assignment_member_id', 'parent_document_id', 'template_version_id',
        'status', 'current_version', 'content_snapshot', 'is_demo', 'created_by', 'updated_by',
        'finalized_at', 'locked_at',
    ];

    protected function casts(): array
    {
        return [
            'content_snapshot' => 'array',
            'is_demo' => 'boolean',
            'current_version' => 'integer',
            'finalized_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function activity(): BelongsTo { return $this->belongsTo(Activity::class); }
    public function assignment(): BelongsTo { return $this->belongsTo(Assignment::class); }
    public function assignmentMember(): BelongsTo { return $this->belongsTo(AssignmentMember::class); }
    public function parent(): BelongsTo { return $this->belongsTo(self::class, 'parent_document_id'); }
    public function templateVersion(): BelongsTo { return $this->belongsTo(DocumentTemplateVersion::class, 'template_version_id'); }
    public function versions(): HasMany { return $this->hasMany(DocumentVersion::class); }
    public function actions(): HasMany { return $this->hasMany(DocumentAction::class); }
    public function signatures(): HasMany { return $this->hasMany(DocumentSignature::class); }
    public function assignments(): BelongsToMany { return $this->belongsToMany(AssignmentMember::class, 'document_assignments'); }
    public function archives(): HasMany { return $this->hasMany(ArchiveEntry::class); }
}
