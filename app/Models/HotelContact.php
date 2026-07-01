<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelContact extends Model {
    protected $fillable = ['hotel_id','type','value','label','is_primary'];
    protected function casts(): array { return ['is_primary'=>'boolean']; }
    public function hotel(): BelongsTo { return $this->belongsTo(Hotel::class); }
}
