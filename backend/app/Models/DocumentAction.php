<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_id', 'document_version_id', 'actor_id', 'action', 'outcome', 'note',
        'signature_method', 'signature_provider', 'signature_reference', 'signed_at',
        'validation_metadata', 'occurred_at', 'signer_capacity', 'signed_artifact_path',
        'signature_validation_status', 'validated_by', 'validated_at',
    ];

    protected function casts(): array
    {
        return [
            'validation_metadata' => 'array',
            'signed_at' => 'datetime',
            'occurred_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function version(): BelongsTo { return $this->belongsTo(DocumentVersion::class, 'document_version_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
