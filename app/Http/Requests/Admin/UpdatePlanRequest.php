<?php
namespace App\Http\Requests\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest {
    public function authorize(): bool { return $this->user()?->hasRole('platform_admin') ?? false; }

    public function rules(): array {
        return array_merge([
            'name'          => ['sometimes', 'string', 'max:100'],
            'slug'          => ['sometimes', 'string', 'max:100', Rule::unique('subscription_plans', 'slug')->ignore($this->route('id'))],
            'scope'         => ['sometimes', 'in:hotel,organization'],
            'min_rooms'     => ['sometimes', 'integer', 'min:1'],
            'max_rooms'     => ['sometimes', 'nullable', 'integer', 'min:1'],
            'price_monthly' => ['sometimes', 'numeric', 'min:0'],
            'price_yearly'  => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'included_properties'  => ['sometimes', 'integer', 'min:1'],
            'extra_property_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency'      => ['sometimes', 'string', 'max:10'],
            'features'      => ['sometimes', 'array'],
            'is_active'     => ['sometimes', 'boolean'],
            'sort_order'    => ['sometimes', 'integer'],
        ], StorePlanRequest::featureRules(), StorePlanRequest::marketingRules());
    }
}
