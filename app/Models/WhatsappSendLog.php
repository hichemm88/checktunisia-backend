<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Une ligne = un message WhatsApp à envoyer (fiche d'un voyageur). Sert à la
 * fois de file d'attente (status=pending) et de journal permanent des envois.
 */
class WhatsappSendLog extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_send_log';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'hotel_id',
        'check_in_id',
        'guest_id',
        'scan_id',
        'recipient',
        'caption',
        'status',
        'attempts',
        'last_error',
        'is_test',
        'next_attempt_at',
        'claimed_at',
        'message_id_whatsapp',
        'queued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(DocumentScan::class, 'scan_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
