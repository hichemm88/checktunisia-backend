<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorityOrganization extends Model {
    protected $fillable = ['name','type','code','governorate','description','is_active','metadata'];
    protected function casts(): array { return ['is_active'=>'boolean','metadata'=>'array']; }

    /** Is this a national-level organization (Ministère de l'Intérieur)? */
    public function isMinistry(): bool { return $this->type === 'ministry'; }

    /** Is this a local police station? */
    public function isPolice(): bool { return $this->type === 'police'; }

    public function userProfiles(): HasMany { return $this->hasMany(AuthorityUserProfile::class, 'organization_id'); }
    public function searchLogs(): HasMany   { return $this->hasMany(AuthoritySearchLog::class, 'organization_id'); }
}
