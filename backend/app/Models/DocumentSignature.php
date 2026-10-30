<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignature extends Model
{
    protected $fillable = [
        'document_id', 'document_version_id', 'signer_user_id', 'signer_capacity',
        'signature_method', 'provider_name', 'provider_reference', 'signed_artifact_path',
        'artifact_sha256', 'validation_status', 'validated_by', 'validated_at',
        'validation_note', 'signed_at',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime', 'validated_at' => 'datetime'];
    }

    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
    public function signer(): BelongsTo { return $this->belongsTo(User::class, 'signer_user_id'); }
}
