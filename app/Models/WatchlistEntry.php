<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WatchlistEntry extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = [
        'organization_id', 'added_by',
        'document_number', 'document_type',
        'first_name', 'last_name', 'date_of_birth', 'nationality_code',
        'severity', 'reason', 'reason_code',
        'status', 'expires_at',
        'source', 'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'expires_at'    => 'datetime',
        ];
    }

    // ─── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(fn ($q) =>
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now())
            );
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function organization(): BelongsTo
    {
        return $this->belongsTo(AuthorityOrganization::class, 'organization_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function hits(): HasMany
    {
        return $this->hasMany(WatchlistHit::class);
    }
}
