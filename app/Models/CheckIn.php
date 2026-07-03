<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class CheckIn extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'hotel_id',
        'room_id',
        'reference',
        'booking_reference',
        'booking_source',
        'check_in_date',
        'expected_check_out_date',
        'actual_check_out_date',
        'status',
        'adults_count',
        'children_count',
        'notes',
        'metadata',
        'created_by',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'check_in_date'           => 'date',
            'expected_check_out_date' => 'date',
            'actual_check_out_date'   => 'date',
            'completed_at'            => 'datetime',
            'metadata'                => 'array',
            'adults_count'            => 'integer',
            'children_count'          => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CheckIn $checkIn) {
            if (empty($checkIn->reference)) {
                $checkIn->reference = static::generateReference();
            }
        });
    }

    /**
     * Atomically hand out the next reference number for today via an
     * upsert-based counter (INSERT ... ON CONFLICT DO UPDATE). A previous
     * COUNT(*) + 1 approach could race under concurrent check-ins and
     * generate duplicate references (unique constraint violation).
     */
    public static function generateReference(): string
    {
        $today = now()->toDateString();

        $sequence = DB::selectOne(
            'insert into check_in_sequences (date, last_number, created_at, updated_at)
             values (?, 1, now(), now())
             on conflict (date) do update
                set last_number = check_in_sequences.last_number + 1,
                    updated_at  = now()
             returning last_number',
            [$today]
        );

        return sprintf('CT-%s-%04d', now()->format('Ymd'), $sequence->last_number);
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'check_in_guests')
            ->withPivot(['is_primary', 'added_by', 'added_at'])
            ->orderByPivot('is_primary', 'desc');
    }

    public function primaryGuest(): BelongsToMany
    {
        return $this->belongsToMany(Guest::class, 'check_in_guests')
            ->wherePivot('is_primary', true);
    }

    public function checkInGuests(): HasMany
    {
        return $this->hasMany(CheckInGuest::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(DocumentScan::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function canBeModified(): bool
    {
        return in_array($this->status, ['draft', 'active']);
    }
}
