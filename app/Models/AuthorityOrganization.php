<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuthorityOrganization extends Model {
    protected $fillable = ['name','type','code','description','is_active','metadata'];
    protected function casts(): array { return ['is_active'=>'boolean','metadata'=>'array']; }
    public function userProfiles(): HasMany { return $this->hasMany(AuthorityUserProfile::class, 'organization_id'); }
    public function searchLogs(): HasMany   { return $this->hasMany(AuthoritySearchLog::class, 'organization_id'); }
}
