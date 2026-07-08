<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrée de menu public (navbar/footer) : libellé trilingue, lien interne (page) ou externe. */
class MenuItem extends Model
{
    use HasUuids;

    protected $fillable = ['location', 'label', 'page_id', 'external_url', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['label' => 'array', 'is_active' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
