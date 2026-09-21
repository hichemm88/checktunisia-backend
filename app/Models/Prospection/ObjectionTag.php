<?php

namespace App\Models\Prospection;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ObjectionTag extends Model
{
    use HasUuids;

    protected $connection = 'prospection';
    protected $table = 'objection_tags';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'label',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
