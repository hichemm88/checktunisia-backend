<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agrégat quotidien des coûts Meta : un jour × une catégorie × un
 * établissement × une source.
 *
 * C'est la table que lisent la page « Coûts Meta » et la carte du dashboard.
 * Voir la migration pour la raison d'être des deux sources.
 */
class WhatsappMessageCost extends Model
{
    use HasUuids;

    protected $table = 'whatsapp_message_costs';

    public $incrementing = false;

    protected $keyType = 'string';

    /** Calcul local (webhook + grille tarifaire). Toujours disponible. */
    public const SOURCE_ESTIMATE = 'estimate';

    /** Montants réels lus chez Meta. Autoritaires quand ils existent. */
    public const SOURCE_META = 'meta';

    protected $fillable = [
        'date',
        'category',
        'hotel_id',
        'source',
        'messages',
        'cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'messages' => 'integer',
            'cost_usd' => 'decimal:6',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }
}
