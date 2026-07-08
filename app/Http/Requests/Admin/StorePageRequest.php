<?php
namespace App\Http\Requests\Admin;

use App\Models\Page;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePageRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->hasRole('platform_admin') ?? false; }

    public function rules(): array {
        return array_merge([
            'slug' => [
                'required', 'string', 'max:150', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::notIn(Page::RESERVED_SLUGS),
                'unique:pages,slug',
            ],
        ], self::contentRules());
    }

    /** Règles partagées contenu/méta/statut — contenu Puck libre par langue, méta SEO par langue. */
    public static function contentRules(): array {
        $rules = [
            'status'  => ['sometimes', 'in:draft,published'],
            'content' => ['sometimes', 'nullable', 'array'],
            'meta'    => ['sometimes', 'nullable', 'array'],
        ];
        foreach (['fr', 'en', 'ar'] as $lang) {
            $rules["content.{$lang}"]           = ['sometimes', 'nullable', 'array'];
            $rules["meta.{$lang}"]              = ['sometimes', 'nullable', 'array'];
            $rules["meta.{$lang}.title"]        = ['sometimes', 'nullable', 'string', 'max:150'];
            $rules["meta.{$lang}.description"]  = ['sometimes', 'nullable', 'string', 'max:300'];
        }
        return $rules;
    }

    public function messages(): array {
        return [
            'slug.regex'   => 'Le slug ne peut contenir que des minuscules, chiffres et tirets.',
            'slug.not_in'  => 'Ce slug est réservé par l\'application.',
        ];
    }
}
