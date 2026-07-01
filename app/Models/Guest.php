<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guest extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'first_name',
        'last_name',
        'date_of_birth',
        'sex',
        'nationality_code',
        'country_of_birth',
        'place_of_birth',
        'email',
        'phone',
        'address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'metadata'      => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function checkIns(): BelongsToMany
    {
        return $this->belongsToMany(CheckIn::class, 'check_in_guests')
            ->withPivot(['is_primary', 'added_by', 'added_at']);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TravelDocument::class);
    }

    public function primaryDocument(): HasOne
    {
        return $this->hasOne(TravelDocument::class)
            ->where('type', 'passport')
            ->latest('created_at');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(DocumentScan::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return strtoupper($this->last_name) . ' ' . $this->first_name;
    }

    public function getAgeAttribute(): int
    {
        return $this->date_of_birth->age;
    }
}
