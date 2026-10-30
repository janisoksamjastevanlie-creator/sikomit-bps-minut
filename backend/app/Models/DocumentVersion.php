<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    protected $fillable = [
        'document_id', 'version', 'template_version_id', 'content_snapshot', 'docx_path',
        'pdf_path', 'sha256', 'created_by',
    ];

    protected function casts(): array
    {
        return ['content_snapshot' => 'array', 'version' => 'integer'];
    }

    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function templateVersion(): BelongsTo { return $this->belongsTo(DocumentTemplateVersion::class); }
}
