<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelSetting extends Model {
    protected $fillable = ['hotel_id','key','value'];
    public function hotel(): BelongsTo { return $this->belongsTo(Hotel::class); }
}
