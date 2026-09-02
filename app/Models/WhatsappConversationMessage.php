<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un message du fil qui n'est PAS une fiche : réponse d'un agent (entrant), ou
 * réponse d'un administrateur (sortant).
 *
 * Les fiches restent dans `whatsapp_send_log` — elles y ont leur file, leurs
 * tentatives, leur PDF et leurs garde-fous. Les recopier ici donnerait deux
 * vérités pour un même envoi, et la seconde finirait par diverger.
 */
class WhatsappConversationMessage extends Model
{
    use HasUuids;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    /** Cycle de vie côté Meta d'un message sortant. */
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    /** Longueur maximale d'un texte accepté par la Cloud API. */
    public const MAX_BODY_LENGTH = 4096;

    protected $fillable = [
        'conversation_id',
        'direction',
        'wamid',
        'type',
        'body',
        'media_id',
        'media_mime',
        'media_filename',
        'context_wamid',
        'sent_by_user_id',
        'status',
        'error_code',
        'error_message',
        'delivered_at',
        'read_at',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            /*
             * Chiffré au repos. Un fil avec un poste de police peut porter un
             * nom de voyageur, un numéro de document, une consigne d'enquête :
             * une copie de base volée doit rester illisible sans APP_KEY.
             *
             * Prix à payer, assumé : aucune recherche SQL dans le contenu. La
             * recherche de la boîte de réception porte sur l'interlocuteur.
             */
            'body' => 'encrypted',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === self::DIRECTION_INBOUND;
    }
}
