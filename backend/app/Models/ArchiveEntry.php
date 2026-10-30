<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchiveEntry extends Model
{
    protected $fillable = ['document_id', 'document_version_id', 'archived_by', 'archived_at', 'retention_until', 'sha256'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime', 'retention_until' => 'datetime'];
    }

    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function version(): BelongsTo { return $this->belongsTo(DocumentVersion::class, 'document_version_id'); }
}
