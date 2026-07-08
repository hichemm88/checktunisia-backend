<?php
namespace App\Http\Requests\Admin;
use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest {
    // Route group already enforces role:platform_admin — authorize mirrors it defensively.
    public function authorize(): bool { return $this->user()?->hasRole('platform_admin') ?? false; }

    public function rules(): array {
        return array_merge([
            'name'          => ['required', 'string', 'max:100'],
            'slug'          => ['required', 'string', 'max:100', 'unique:subscription_plans,slug'],
            'scope'         => ['sometimes', 'in:hotel,organization'],
            'min_rooms'     => ['required', 'integer', 'min:1'],
            'max_rooms'     => ['nullable', 'integer', 'min:1'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly'  => ['nullable', 'numeric', 'min:0'],
            'currency'      => ['sometimes', 'string', 'max:10'],
            'features'      => ['sometimes', 'array'],
            'is_active'     => ['sometimes', 'boolean'],
            'sort_order'    => ['sometimes', 'integer'],
        ], self::marketingRules());
    }

    /** Shared trilingual marketing validation — FR required when a field is present, EN/AR optional (fallback FR at render). */
    public static function marketingRules(): array {
        return [
            'marketing'                    => ['sometimes', 'nullable', 'array'],
            'marketing.tier'               => ['sometimes', 'array'],
            'marketing.tier.fr'            => ['required_with:marketing.tier', 'string', 'max:50'],
            'marketing.tier.en'            => ['nullable', 'string', 'max:50'],
            'marketing.tier.ar'            => ['nullable', 'string', 'max:50'],
            'marketing.display_name'       => ['sometimes', 'array'],
            'marketing.display_name.fr'    => ['required_with:marketing.display_name', 'string', 'max:100'],
            'marketing.display_name.en'    => ['nullable', 'string', 'max:100'],
            'marketing.display_name.ar'    => ['nullable', 'string', 'max:100'],
            'marketing.tagline'            => ['sometimes', 'array'],
            'marketing.tagline.fr'         => ['required_with:marketing.tagline', 'string', 'max:300'],
            'marketing.tagline.en'         => ['nullable', 'string', 'max:300'],
            'marketing.tagline.ar'         => ['nullable', 'string', 'max:300'],
            'marketing.price_note'         => ['sometimes', 'array'],
            'marketing.price_note.fr'      => ['required_with:marketing.price_note', 'string', 'max:150'],
            'marketing.price_note.en'      => ['nullable', 'string', 'max:150'],
            'marketing.price_note.ar'      => ['nullable', 'string', 'max:150'],
            'marketing.badge'              => ['sometimes', 'nullable', 'array'],
            'marketing.badge.fr'           => ['required_with:marketing.badge', 'string', 'max:50'],
            'marketing.badge.en'           => ['nullable', 'string', 'max:50'],
            'marketing.badge.ar'           => ['nullable', 'string', 'max:50'],
            'marketing.featured'           => ['sometimes', 'boolean'],
            'marketing.cta_label'          => ['sometimes', 'array'],
            'marketing.cta_label.fr'       => ['required_with:marketing.cta_label', 'string', 'max:80'],
            'marketing.cta_label.en'       => ['nullable', 'string', 'max:80'],
            'marketing.cta_label.ar'       => ['nullable', 'string', 'max:80'],
            'marketing.bullets'            => ['sometimes', 'array', 'max:20'],
            'marketing.bullets.*.included' => ['required', 'boolean'],
            'marketing.bullets.*.text'     => ['required', 'array'],
            'marketing.bullets.*.text.fr'  => ['required', 'string', 'max:150'],
            'marketing.bullets.*.text.en'  => ['nullable', 'string', 'max:150'],
            'marketing.bullets.*.text.ar'  => ['nullable', 'string', 'max:150'],
        ];
    }
}
