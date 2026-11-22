<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FicheSession extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    public const MODE_CREATE = 'create';
    public const MODE_AMEND = 'amend';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'hotel_id',
        'partner_id',
        'api_key_id',
        'booking_reference',
        'mode',
        'status',
        'check_in_id',
        'prefill_guests',
        'arrival_date',
        'departure_date',
        'room_label',
        'metadata',
        'is_test',
        'jwt_jti',
        'jwt_consumed_at',
        'widget_session_token_hash',
        'widget_session_expires_at',
        'expires_at',
        'submitted_at',
        'operator_user_id',
        'operator_name',
        'requested_operator_user_id',
        'requested_operator_name',
        'locale',
    ];

    protected function casts(): array
    {
        return [
            'prefill_guests' => 'array',
            'metadata' => 'array',
            'is_test' => 'boolean',
            'arrival_date' => 'date',
            'departure_date' => 'date',
            'jwt_consumed_at' => 'datetime',
            'widget_session_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    public function hotel(): BelongsTo
    {
        return $this->belongsTo(Hotel::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(ApiPartner::class, 'partner_id');
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    /** Voyageurs attendus = ceux passés par le partenaire à la création (au moins 1). */
    public function expectedGuestCount(): int
    {
        return max(1, count($this->prefill_guests ?? []));
    }

    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(CheckIn::class);
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isPast();
    }

    public function isPendingAndUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && ! $this->expires_at->isPast();
    }

    /**
     * Une personne a le widget ouvert sur cette fiche et s'en est servie
     * récemment : token de session vivant et dernière action (chaque appel
     * prolonge le TTL) datant de moins de `widget_session.lock_minutes`.
     */
    public function hasActiveHolder(): bool
    {
        // Pas de test sur jwt_consumed_at : une nouvelle demande de widget_url
        // le remet à null (nouveau JWT) sans retirer la main à celui qui saisit.
        if ($this->widget_session_token_hash === null || $this->widget_session_expires_at === null || $this->widget_session_expires_at->isPast()) {
            return false;
        }

        $ttl = (int) config('partner_api.widget_session.ttl_minutes', 20);
        $lock = (int) config('partner_api.widget_session.lock_minutes', 10);
        $lastActivity = $this->widget_session_expires_at->copy()->subMinutes($ttl);

        return $lastActivity->isAfter(now()->subMinutes($lock));
    }

    /**
     * La saisie en cours est tenue par quelqu'un d'autre que ce réceptionniste.
     * Même personne = même compte Qayed, ou à défaut même nom transmis par le
     * partenaire. Sans aucune identité connue de part et d'autre, on ne peut
     * pas distinguer : pas de verrou (comportement v1.1, reprise de la main).
     */
    public function isHeldByAnother(?string $userId, ?string $name): bool
    {
        if (! $this->hasActiveHolder()) {
            return false;
        }

        if ($this->operator_user_id !== null || $userId !== null) {
            return $this->operator_user_id !== $userId;
        }

        $norm = fn (?string $n) => mb_strtolower(trim((string) $n));
        if ($norm($this->operator_name) === '') {
            return false;
        }

        return $norm($this->operator_name) !== $norm($name);
    }

    /** Nom affichable de la personne qui tient la saisie, null si inconnu. */
    public function holderName(): ?string
    {
        $user = $this->operator_user_id ? $this->operator()->first() : null;
        $full = $user ? trim($user->first_name.' '.$user->last_name) : '';

        return $full !== '' ? $full : ($this->operator_name ?: null);
    }

    /** Statut public tel qu'exposé dans GET /v1/fiche-sessions/{id} (reflète l'expiration réelle). */
    public function publicStatus(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->expires_at->isPast()) {
            return self::STATUS_EXPIRED;
        }

        return $this->status;
    }
}
