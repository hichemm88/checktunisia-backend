<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Média CMS stocké en base (bytea) — voir la migration create_cms_tables
 * pour la justification (disque Railway éphémère, pas de S3 configuré).
 * `data` n'est jamais sérialisé dans les listes ($hidden).
 */
class Media extends Model
{
    use HasUuids;

    protected $table = 'media';
    protected $fillable = ['filename', 'mime', 'size', 'data', 'created_by'];
    protected $hidden = ['data'];

    public function publicUrl(): string
    {
        return url("/api/v1/public/media/{$this->id}");
    }
}
