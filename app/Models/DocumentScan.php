<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentScan extends Model
{
    use HasUuids;

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'check_in_id', 'travel_document_id', 'guest_id',
        'file_path', 'file_hash', 'file_size_bytes', 'mime_type',
        'ocr_status', 'ocr_raw_result', 'ocr_confidence',
        'ocr_processed_at', 'ocr_error', 'uploaded_by',
        // Copie compressée en base (base64) — le disque Railway est éphémère,
        // les fichiers de scans disparaissent à chaque redéploiement.
        'image_data',
    ];

    protected $hidden = ['file_path', 'file_hash', 'image_data'];

    /** Octets JPEG de la copie compressée stockée en base (base64 → binaire). */
    public function imageBytes(): ?string
    {
        if (! $this->image_data) {
            return null;
        }

        $bytes = base64_decode((string) $this->image_data, true);

        return $bytes === false ? null : $bytes;
    }

    protected function casts(): array
    {
        return [
            'ocr_raw_result' => 'array',
            'ocr_confidence' => 'float',
            'ocr_processed_at' => 'datetime',
        ];
    }

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    public function travelDocument(): BelongsTo
    {
        return $this->belongsTo(TravelDocument::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPending(): bool
    {
        return $this->ocr_status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->ocr_status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->ocr_status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->ocr_status === 'failed';
    }
}
