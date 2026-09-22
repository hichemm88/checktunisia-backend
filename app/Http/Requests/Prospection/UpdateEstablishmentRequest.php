<?php

namespace App\Http\Requests\Prospection;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEstablishmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200', Rule::unique('prospection.establishments', 'name')->ignore($this->route('id'))],
            'zone' => ['sometimes', Rule::in(['grand_tunis', 'banlieue_nord', 'cap_bon', 'sud', 'autre'])],
            'locality' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string'],
            'size' => ['sometimes', 'nullable', Rule::in(['petite', 'moyenne', 'grande'])],
            'segment' => ['sometimes', 'nullable', Rule::in(['maison_hotes', 'guesthouse', 'boutique_hotel', 'hotel', 'location_entiere', 'autre'])],
            'priority' => ['sometimes', Rule::in(['P1', 'P2', 'P3'])],
            'decision_maker_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'decision_maker_role' => ['sometimes', 'nullable', 'string', 'max:150'],
            'whatsapp_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'origin_channel' => ['sometimes', 'nullable', 'string', 'max:150'],
            'qualification_notes' => ['sometimes', 'nullable', 'string'],
            'target_plan' => ['sometimes', Rule::in(['essentiel', 'pro', 'hotel', 'inconnu'])],
            'next_action_at' => ['sometimes', 'nullable', 'date'],
            'out_of_scope' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            // Le statut se change ici aussi (édition libre des champs), mais
            // c'est EstablishmentStatusUpdater qui applique les effets de
            // bord — voir EstablishmentController::update().
        ];
    }
}
