<?php

namespace App\Http\Requests;

use App\Domain\MarketSuggestions\Questionnaire;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarketPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['country', 'region', 'reference_currency', 'target_asset', 'excluded_assets'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = strtoupper(trim($this->input($key)));
            }
        }
        $holdings = $this->input('holdings', []);
        if (is_array($holdings)) {
            $normalized['holdings'] = array_values(array_filter(array_map(function ($holding) {
                if (is_array($holding) && is_string($holding['asset'] ?? null)) {
                    $holding['asset'] = strtoupper(trim($holding['asset']));
                }

                return $holding;
            }, $holdings), fn ($holding) => ! is_array($holding) || ! empty($holding['asset'])));
        }
        $this->merge($normalized);
    }

    public function rules(): array
    {
        $asset = ['string', 'max:16', 'regex:/^[A-Z0-9._-]+$/D'];
        $rules = [
            'country' => ['required', Rule::in(array_keys(Questionnaire::countries()))],
            'region' => ['nullable', 'string', 'max:60', Rule::requiredIf($this->input('country') === 'CA')],
            'exchange' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9]+$/D', 'exists:exchanges,class'],
            'access_confirmed' => ['sometimes', 'boolean'],
            'reference_currency' => ['required', ...$asset],
            'allocation' => ['required', Rule::in(array_keys(Questionnaire::BANDS))],
            'target_asset' => [Rule::requiredIf($this->input('goal') === 'accumulate'), 'nullable', ...$asset],
            'holdings' => ['present', 'array', 'max:5'],
            'holdings.*' => ['array:asset,band'],
            'holdings.*.asset' => ['required', 'distinct', ...$asset],
            'holdings.*.band' => ['required', Rule::in(array_keys(Questionnaire::BANDS))],
            'excluded_assets' => ['nullable', 'string', 'max:200', 'regex:/^[A-Z0-9._,\s-]*$/D'],
            'exclude_stablecoins' => ['sometimes', 'boolean'],
        ];
        if ($this->input('country') === 'CA') {
            $rules['region'][] = Rule::in(array_keys(Questionnaire::PROVINCES));
        }
        foreach (Questionnaire::choices() as $key => $choices) {
            $rules[$key] = ['required', Rule::in(array_keys($choices))];
        }

        return $rules;
    }

    public function answers(): array
    {
        return array_replace(Questionnaire::defaults(), $this->validated(), [
            'access_confirmed' => $this->boolean('access_confirmed'),
            'exclude_stablecoins' => $this->boolean('exclude_stablecoins'),
        ]);
    }
}
