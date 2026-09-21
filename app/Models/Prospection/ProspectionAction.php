<?php

namespace App\Models\Prospection;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une entrée du journal de prospection. Append-only : ni UPDATE ni DELETE
 * applicatif (voir migration create_prospection_actions_table).
 */
class ProspectionAction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $connection = 'prospection';

    protected $table = 'actions';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'establishment_id',
        'type',
        'channel',
        'content',
        'objections',
        'occurred_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'objections' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class, 'establishment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProspectionUser::class, 'created_by');
    }
}
