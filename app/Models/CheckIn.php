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
     * upsert-based counter (INSERT … ON CONFLICT DO UPDATE).
     *
     * The counter is self-healing: if the check_in_sequences table is ever
     * cleared/reset while check_ins retains its rows, the CTE seeds the
     * sequence from MAX(existing reference) so duplicates can never occur.
     *
     * GREATEST() in the ON CONFLICT branch ensures the sequence always
     * advances past whatever is already in check_ins, even mid-day.
     */
    public static function generateReference(): string
    {
        $today   = now()->toDateString();
        $dateStr = now()->format('Ymd');

        // Match only well-formed references for today (e.g. CT-20260703-0001)
        $like = "CT-{$dateStr}-%";

        $sequence = DB::selectOne("
            WITH current_max AS (
                SELECT COALESCE(
                    MAX(CAST(SPLIT_PART(reference, '-', 3) AS INTEGER)), 0
                ) AS n
                FROM check_ins
                WHERE reference LIKE ?
                  AND reference ~ '^CT-[0-9]{8}-[0-9]{4}$'
            )
            INSERT INTO check_in_sequences (date, last_number, created_at, updated_at)
            SELECT ?, (SELECT n FROM current_max) + 1, now(), now()
            ON CONFLICT (date) DO UPDATE
                SET last_number = GREATEST(
                        check_in_sequences.last_number + 1,
                        (SELECT n FROM current_max) + 1
                    ),
                    updated_at = now()
            RETURNING last_number
        ", [$like, $today]);

        return sprintf('CT-%s-%04d', $dateStr, $sequence->last_number);
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
