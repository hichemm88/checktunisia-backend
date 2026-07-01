<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelAddress extends Model {
    protected $fillable = ['hotel_id','line1','line2','city','governorate','postal_code','country_code','latitude','longitude','is_primary'];
    protected function casts(): array { return ['is_primary'=>'boolean','latitude'=>'decimal:8','longitude'=>'decimal:8']; }
    public function hotel(): BelongsTo { return $this->belongsTo(Hotel::class); }
}
