<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuditLog extends Model {
    public $timestamps = false;
    const UPDATED_AT = null;
    protected $fillable = ['request_id','actor_id','actor_role','hotel_id','action','subject_type','subject_id','old_values','new_values','ip_address','user_agent','created_at'];
    protected function casts(): array { return ['old_values'=>'array','new_values'=>'array','created_at'=>'datetime']; }
    public function actor(): BelongsTo    { return $this->belongsTo(User::class, 'actor_id'); }
    public function hotel(): BelongsTo    { return $this->belongsTo(Hotel::class); }
    public function searchLog(): HasOne   { return $this->hasOne(AuthoritySearchLog::class, 'audit_log_id'); }
}
