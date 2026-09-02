<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un message sortant susceptible d'être facturé par Meta, identifié par son
 * `wamid`.
 *
 * La ligne naît à l'ENVOI (Meta a accepté, rien n'est encore dû) et devient
 * facturable au `delivered` remonté par le webhook. `counted_at` est le verrou
 * d'idempotence : voir la migration pour le raisonnement complet.
 */
class WhatsappBillableMessage extends Model
{
    protected $table = 'whatsapp_billable_messages';

    protected $primaryKey = 'wamid';

    public $incrementing = false;

    protected $keyType = 'string';

    /** Catégories de facturation Meta. */
    public const CATEGORY_UTILITY = 'utility';

    public const CATEGORY_AUTHENTICATION = 'authentication';

    public const CATEGORY_MARKETING = 'marketing';

    /**
     * Conversations de service. Gratuites jusqu'au 30/09/2026, facturées au
     * tarif utility ensuite : la catégorie existe dès aujourd'hui pour que la
     * bascule soit un changement de tarif, pas un changement de schéma.
     */
    public const CATEGORY_SERVICE = 'service';

    public const CATEGORIES = [
        self::CATEGORY_UTILITY,
        self::CATEGORY_AUTHENTICATION,
        self::CATEGORY_MARKETING,
        self::CATEGORY_SERVICE,
    ];

    protected $fillable = [
        'wamid',
        'category',
        'hotel_id',
        'send_log_id',
        'template_name',
        'sent_at',
        'delivered_at',
        'counted_at',
        'unit_price_usd',
        'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'counted_at' => 'datetime',
            'unit_price_usd' => 'decimal:6',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function sendLog(): BelongsTo
    {
        return $this->belongsTo(WhatsappSendLog::class, 'send_log_id');
    }

    /** Déjà porté à l'agrégat : tout rejeu du webhook doit passer son chemin. */
    public function isCounted(): bool
    {
        return $this->counted_at !== null;
    }
}
