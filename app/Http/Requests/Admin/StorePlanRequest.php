<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The form sends features one per line or as a list; either way,
        // blank lines are dropped rather than saved as empty bullet points.
        $features = $this->input('features', []);

        if (is_string($features)) {
            $features = preg_split('/\r\n|\r|\n/', $features);
        }

        $this->merge([
            'features' => array_values(array_filter(array_map(
                fn ($f) => is_string($f) ? trim($f) : $f,
                (array) $features
            ), fn ($f) => filled($f))),
        ]);
    }

    public function rules(): array
    {
        $planId = $this->route('plan')?->id;

        return [
            'name' => ['required', 'string', 'max:80'],
            'slug' => [
                'nullable', 'string', 'max:80', 'alpha_dash',
                Rule::unique('plans', 'slug')->ignore($planId),
            ],
            'tagline' => ['nullable', 'string', 'max:160'],
            // Whole naira. ₦100 is Paystack's floor for a card charge.
            'price' => ['required', 'integer', 'min:100', 'max:100000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'features' => ['array', 'max:20'],
            'features.*' => ['string', 'max:160'],
            'is_popular' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
