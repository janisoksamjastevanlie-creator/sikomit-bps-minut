<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplateVersion extends Model
{
    protected $fillable = [
        'document_template_id', 'version', 'original_filename', 'storage_path', 'sha256',
        'placeholder_fields', 'validation_findings', 'required_signatures', 'status', 'uploaded_by', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'version' => 'integer',
            'placeholder_fields' => 'array',
            'validation_findings' => 'array',
            'required_signatures' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
