<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Country extends Model {
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;
    protected $fillable = ['code','alpha3','name_en','name_fr','name_ar','flag_emoji','is_active','sort_order'];
    protected function casts(): array { return ['is_active'=>'boolean']; }
}
