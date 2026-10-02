<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cash.open') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'opening_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'opening_amount.required' => 'Le fonds d’ouverture est obligatoire.',
            'opening_amount.numeric' => 'Le fonds d’ouverture doit être un montant valide.',
            'opening_amount.decimal' => 'Le fonds d’ouverture peut comporter au maximum deux décimales.',
            'opening_amount.min' => 'Le fonds d’ouverture doit être positif ou nul.',
        ];
    }
}
