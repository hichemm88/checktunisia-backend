<?php

namespace App\Models\Prospection;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un prospect. Voir la migration create_prospection_establishments_table
 * pour le détail des colonnes et App\Services\Prospection\PipelineStatus
 * pour les statuts et transitions valides.
 */
class Establishment extends Model
{
    use HasUuids;

    protected $connection = 'prospection';
    protected $table = 'establishments';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'zone',
        'locality',
        'address',
        'size',
        'segment',
        'priority',
        'status',
        'decision_maker_name',
        'decision_maker_role',
        'whatsapp_phone',
        'origin_channel',
        'qualification_notes',
        'target_plan',
        'next_action_at',
        'out_of_scope',
        'archived',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'next_action_at' => 'datetime',
            'out_of_scope' => 'boolean',
            'archived' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProspectionUser::class, 'created_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(ProspectionAction::class, 'establishment_id');
    }
}
