<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cash.close') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'closing_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'closing_amount.required' => 'Le montant compté est obligatoire.',
            'closing_amount.numeric' => 'Le montant compté doit être valide.',
            'closing_amount.decimal' => 'Le montant compté peut comporter au maximum deux décimales.',
            'closing_amount.min' => 'Le montant compté doit être positif ou nul.',
        ];
    }
}
