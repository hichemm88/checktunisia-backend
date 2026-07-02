<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Room extends Model {
    use HasFactory, HasUuids, SoftDeletes;
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['hotel_id','number','floor','type','capacity','status','metadata'];
    protected function casts(): array { return ['metadata'=>'array','capacity'=>'integer','floor'=>'integer']; }
    public function hotel(): BelongsTo { return $this->belongsTo(Hotel::class); }
    public function checkIns(): HasMany { return $this->hasMany(CheckIn::class); }
    public function isAvailable(): bool { return $this->status === 'available'; }
}
