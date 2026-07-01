<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckInGuest extends Model {
    public $timestamps = false;
    protected $fillable = ['check_in_id','guest_id','is_primary','added_by','added_at'];
    protected function casts(): array { return ['is_primary'=>'boolean','added_at'=>'datetime']; }
    public function checkIn(): BelongsTo { return $this->belongsTo(CheckIn::class); }
    public function guest(): BelongsTo   { return $this->belongsTo(Guest::class); }
    public function addedBy(): BelongsTo { return $this->belongsTo(User::class, 'added_by'); }
}
