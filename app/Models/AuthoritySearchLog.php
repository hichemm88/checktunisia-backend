<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthoritySearchLog extends Model {
    public $timestamps = false;
    protected $fillable = ['audit_log_id','user_id','organization_id','search_params','result_count','execution_time_ms','created_at'];
    protected function casts(): array { return ['search_params'=>'array','created_at'=>'datetime']; }
    public function auditLog(): BelongsTo    { return $this->belongsTo(AuditLog::class); }
    public function user(): BelongsTo        { return $this->belongsTo(User::class); }
    public function organization(): BelongsTo { return $this->belongsTo(AuthorityOrganization::class, 'organization_id'); }
}
