<?php
namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Store et update partagent les mêmes règles — en update les champs deviennent optionnels via sometimes. */
class MenuItemRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->hasRole('platform_admin') ?? false; }

    public function rules(): array {
        $isUpdate = $this->isMethod('PATCH');
        $req = $isUpdate ? 'sometimes' : 'required';

        return [
            'location'     => [$req, 'in:navbar,footer'],
            'label'        => [$req, 'array'],
            'label.fr'     => [$isUpdate ? 'required_with:label' : 'required', 'string', 'max:80'],
            'label.en'     => ['nullable', 'string', 'max:80'],
            'label.ar'     => ['nullable', 'string', 'max:80'],
            'page_id'      => ['sometimes', 'nullable', 'uuid', 'exists:pages,id'],
            // URL complète, chemin interne (/…) ou ancre (#…, /#…)
            'external_url' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:~^(https?://|/|#)~'],
            'sort_order'   => ['sometimes', 'integer'],
            'is_active'    => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void {
        $validator->after(function ($v) {
            $pageId   = $this->input('page_id');
            $external = $this->input('external_url');
            if (!$this->isMethod('PATCH') && !$pageId && !$external) {
                $v->errors()->add('page_id', 'Une entrée de menu doit pointer vers une page ou une URL externe.');
            }
            if ($pageId && $external) {
                $v->errors()->add('page_id', 'Choisissez une page interne OU une URL externe, pas les deux.');
            }
        });
    }
}
