<?php
namespace App\Http\Requests\Admin;

use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePageRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->hasRole('platform_admin') ?? false; }

    public function rules(): array {
        return array_merge([
            'slug' => [
                'sometimes', 'string', 'max:150', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::notIn(Page::RESERVED_SLUGS),
                Rule::unique('pages', 'slug')->ignore($this->route('id')),
            ],
        ], StorePageRequest::contentRules());
    }

    public function messages(): array {
        return [
            'slug.regex'  => 'Le slug ne peut contenir que des minuscules, chiffres et tirets.',
            'slug.not_in' => 'Ce slug est réservé par l\'application.',
        ];
    }
}
