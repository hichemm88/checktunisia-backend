<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorityUserProfile extends Model {
    protected $fillable = ['user_id','organization_id','badge_number','rank','authorized_by','authorized_at','expires_at','metadata'];
    protected function casts(): array { return ['authorized_at'=>'datetime','expires_at'=>'datetime','metadata'=>'array']; }
    public function user(): BelongsTo         { return $this->belongsTo(User::class); }
    public function organization(): BelongsTo { return $this->belongsTo(AuthorityOrganization::class, 'organization_id'); }
    public function authorizer(): BelongsTo   { return $this->belongsTo(User::class, 'authorized_by'); }
    public function isExpired(): bool { return $this->expires_at && $this->expires_at->isPast(); }
}
