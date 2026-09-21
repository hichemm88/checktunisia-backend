<?php

namespace App\Models\Prospection;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use HasUuids;

    protected $connection = 'prospection';

    protected $table = 'message_templates';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'body',
        'segment',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Remplace {prenom} et {etablissement} par les valeurs fournies. Toute
     * variable non reconnue est laissée telle quelle plutôt que supprimée :
     * une faute de frappe dans le template ({prenomm}) doit rester visible
     * à la relecture, pas disparaître silencieusement.
     *
     * @param  array<string, string>  $variables
     */
    public function render(array $variables): string
    {
        $body = $this->body;

        foreach ($variables as $key => $value) {
            $body = str_replace('{'.$key.'}', $value, $body);
        }

        return $body;
    }
}
