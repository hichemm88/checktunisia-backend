<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AuthorityUserProfile extends Model {
    protected $fillable = ['user_id','organization_id','badge_number','rank','whatsapp_number','receives_whatsapp_fiches','authorized_by','authorized_at','expires_at','metadata'];
    protected function casts(): array { return ['authorized_at'=>'datetime','expires_at'=>'datetime','metadata'=>'array','receives_whatsapp_fiches'=>'boolean']; }
    public function user(): BelongsTo         { return $this->belongsTo(User::class); }
    public function organization(): BelongsTo { return $this->belongsTo(AuthorityOrganization::class, 'organization_id'); }
    public function authorizer(): BelongsTo   { return $this->belongsTo(User::class, 'authorized_by'); }

    /**
     * Établissements dont cet agent reçoit les fiches (réciproque de
     * Hotel::whatsappRecipientProfiles). Vide = l'admin ne l'a encore rattaché
     * à aucun établissement, donc aucune fiche ne part vers son numéro.
     */
    public function hotels(): BelongsToMany {
        return $this->belongsToMany(
            Hotel::class,
            'hotel_whatsapp_recipients',
            'authority_user_profile_id',
            'hotel_id',
        )->withTimestamps();
    }

    public function isExpired(): bool { return $this->expires_at && $this->expires_at->isPast(); }
}
