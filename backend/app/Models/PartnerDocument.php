<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerDocument extends Model
{
    protected $fillable = [
        'partner_id', 'document_type', 'original_name', 'storage_path', 'mime_type',
        'size_bytes', 'sha256', 'status', 'uploaded_by',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
