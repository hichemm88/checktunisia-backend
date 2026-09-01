<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
        // Cloud API : modèle soumis, canal effectif, cycle de vie côté Meta.
        'template_name',
        'template_language',
        'template_params',
        'channel',
        'delivery_status',
        'delivered_at',
        'read_at',
        'error_code',
        'public_token',
    ];

    /*
    | Cycle de vie côté Meta, alimenté par le webhook. Distinct de `status`,
    | qui décrit NOTRE tentative d'envoi : un job `sent` dont la livraison est
    | `failed` a bien été accepté par Meta et n'a jamais atteint le
    | destinataire — sur un canal légal, la nuance n'est pas cosmétique.
    */
    public const DELIVERY_ACCEPTED = 'accepted';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_DELIVERED = 'delivered';

    public const DELIVERY_READ = 'read';

    public const DELIVERY_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'claimed_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'template_params' => 'array',
        ];
    }

    /**
     * Commodité, pas garantie : la colonne est NOT NULL en base.
     *
     * Ce crochet évite à chaque appelant d'avoir à penser au jeton. Il ne
     * porte PAS l'invariant — un invariant qui ne vit que dans le modèle
     * tient tant que toutes les écritures passent par Eloquent, c'est-à-dire
     * jusqu'à la première reprise de données en SQL. La garantie est dans la
     * migration ; ici, on rend seulement le cas normal indolore.
     */
    protected static function booted(): void
    {
        static::creating(function (self $job) {
            if (blank($job->public_token)) {
                $job->public_token = (string) Str::ulid();
            }
        });
    }

    /**
     * Jeton public de cet envoi.
     *
     * Le filet — générer et persister si le jeton manque — ne devrait plus
     * jamais servir depuis que la colonne est NOT NULL et les anciennes
     * lignes remplies. Il reste parce que son coût est nul et que son absence
     * se paierait en lien mort dans un message déjà reçu par un policier, un
     * échec que nous ne verrions pas.
     */
    public function publicToken(): string
    {
        if (blank($this->public_token)) {
            $this->forceFill(['public_token' => (string) Str::ulid()])->save();
        }

        return (string) $this->public_token;
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
