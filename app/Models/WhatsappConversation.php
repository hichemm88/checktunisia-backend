<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un fil d'échanges avec UN NUMÉRO WhatsApp.
 *
 * Le fil est identifié par le numéro, jamais par l'agent : c'est le numéro que
 * Meta nous donne, et lui seul est toujours présent. Le rattachement à un
 * profil autorité est une résolution locale, faite au mieux — elle échoue
 * légitimement pour le numéro global, pour un agent supprimé, ou pour un tiers
 * qui écrit sur notre numéro.
 */
class WhatsappConversation extends Model
{
    use HasUuids;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    /**
     * Fenêtre de service Meta : hors des 24 h qui suivent le DERNIER message
     * entrant, seul un modèle approuvé passe. Ce n'est pas un réglage Qayed —
     * c'est une règle de la plateforme, et la coder ailleurs qu'ici ferait
     * afficher un champ de saisie sur un envoi que Meta refusera.
     */
    public const SERVICE_WINDOW_HOURS = 24;

    protected $fillable = [
        'phone',
        'authority_user_profile_id',
        'contact_name',
        'last_inbound_at',
        'last_outbound_at',
        'last_message_at',
        'last_message_direction',
        'last_message_preview',
        'unread_count',
    ];

    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
            // Même raison que le corps des messages : l'aperçu EST du contenu.
            // Le chiffrer dans un cas et pas dans l'autre laisserait fuir la
            // dernière phrase de chaque fil — souvent la plus parlante.
            'last_message_preview' => 'encrypted',
        ];
    }

    /**
     * Numéro normalisé : chiffres seuls, international, sans « + ».
     *
     * Règle unique, partagée avec `WhatsAppCloudChannel::formatRecipient()` et
     * `WhatsappOtpService::normalize()`. Elle doit rester identique aux trois
     * endroits : un écart, et le même policier a deux fils, un compteur de
     * non-lus faux, et des fiches rattachées au mauvais.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return strlen((string) $digits) >= 8 ? $digits : null;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappConversationMessage::class, 'conversation_id');
    }

    public function sendLogs(): HasMany
    {
        return $this->hasMany(WhatsappSendLog::class, 'conversation_id');
    }

    public function authorityProfile(): BelongsTo
    {
        return $this->belongsTo(AuthorityUserProfile::class, 'authority_user_profile_id');
    }

    /** Instant de fermeture de la fenêtre de service, ou null si jamais ouverte. */
    public function serviceWindowClosesAt(): ?Carbon
    {
        return $this->last_inbound_at?->copy()->addHours(self::SERVICE_WINDOW_HOURS);
    }

    /** Peut-on encore envoyer un texte libre à ce numéro ? */
    public function serviceWindowIsOpen(): bool
    {
        return $this->serviceWindowClosesAt()?->isFuture() ?? false;
    }
}
