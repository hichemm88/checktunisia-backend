<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Page CMS : contenu Puck JSON par langue ({fr,en,ar}) + méta SEO par langue. */
class Page extends Model
{
    use HasUuids;

    /** Slugs interdits — routes applicatives réservées côté frontend. */
    public const RESERVED_SLUGS = [
        'admin', 'hotel', 'authority', 'login', 'register', 'set-password',
        'profile', 'payment', 'api', 'assets', 'fr', 'en', 'ar',
    ];

    protected $fillable = ['slug', 'status', 'content', 'meta'];

    protected function casts(): array
    {
        return ['content' => 'array', 'meta' => 'array'];
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }
}
