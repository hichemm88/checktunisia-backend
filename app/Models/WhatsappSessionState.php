<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * MODULE PROVISOIRE — à retirer après homologation MI.
 * Voir PROMPT-CLAUDE-CODE-QAYED-AUTORITE.md
 *
 * Ligne unique (key='default') reflétant l'état de la session WhatsApp émise
 * par le service Node. Source de vérité pour /health/whatsapp et pour savoir
 * si la file peut avancer.
 */
class WhatsappSessionState extends Model
{
    protected $table = 'whatsapp_session_state';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const KEY = 'default';

    public const STATUS_INITIALIZING = 'initializing';

    public const STATUS_READY = 'ready';

    public const STATUS_DISCONNECTED = 'disconnected';

    /**
     * WhatsApp a révoqué l'appareil lié (événement « LOGOUT »). Contrairement à
     * `disconnected`, aucune reconnexion automatique n'est possible : seul un
     * ré-appairage par QR rétablit le service. Cet état est DURABLE — il
     * survit aux redémarrages du worker tant qu'une session n'est pas reprise.
     */
    public const STATUS_LOGGED_OUT = 'logged_out';

    public const STATUS_AUTH_FAILURE = 'auth_failure';

    protected $fillable = [
        'key', 'status', 'reason', 'paused', 'last_ready_at', 'heartbeat_at', 'revoked_at',
        'resume_requested_at', 'phone_number', 'paired_at',
    ];

    protected function casts(): array
    {
        return [
            'paused' => 'boolean',
            'last_ready_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'revoked_at' => 'datetime',
            'resume_requested_at' => 'datetime',
            'paired_at' => 'datetime',
        ];
    }

    /**
     * Le numéro émetteur est-il encore en montée en charge ?
     *
     * Un numéro fraîchement appairé n'a aucune réputation auprès de Meta :
     * cadence et plafond restent bas tant que la fenêtre court. `paired_at` nul
     * = numéro antérieur à cette mécanique, donc déjà rodé — on ne bride pas
     * rétroactivement une installation qui tourne.
     */
    public function inWarmup(): bool
    {
        if (!$this->paired_at) {
            return false;
        }

        return $this->paired_at->gt(now()->subHours((int) config('whatsapp.warmup_hours', 24)));
    }

    /** Un ré-appairage humain est-il réellement nécessaire ? */
    public function needsPairing(): bool
    {
        return $this->status === self::STATUS_LOGGED_OUT;
    }

    /** Récupère (ou crée) la ligne d'état unique. */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['key' => self::KEY],
            ['status' => self::STATUS_INITIALIZING, 'paused' => false],
        );
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** La file peut-elle avancer ? Session prête et non mise en pause. */
    public function canDispatch(): bool
    {
        return $this->isReady() && !$this->paused;
    }
}
