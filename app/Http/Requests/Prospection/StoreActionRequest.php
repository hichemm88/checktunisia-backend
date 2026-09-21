<?php

namespace App\Http\Requests\Prospection;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                'message_envoye', 'reponse_recue', 'appel', 'demo_planifiee',
                'demo_faite', 'essai_active', 'relance', 'note', 'changement_statut',
            ])],
            'channel' => ['sometimes', 'nullable', Rule::in(['WhatsApp', 'Messenger', 'téléphone', 'sur place'])],
            'content' => ['sometimes', 'nullable', 'string'],
            'objections' => ['sometimes', 'nullable', 'array'],
            'objections.*' => ['string'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
