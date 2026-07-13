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

    public const STATUS_AUTH_FAILURE = 'auth_failure';

    protected $fillable = [
        'key', 'status', 'reason', 'paused', 'last_ready_at', 'heartbeat_at',
    ];

    protected function casts(): array
    {
        return [
            'paused' => 'boolean',
            'last_ready_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
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
        return $this->isReady() && ! $this->paused;
    }
}
