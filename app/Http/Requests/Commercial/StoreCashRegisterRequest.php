<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCashRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('cash.create') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $user = $this->user();
        $companyId = $user?->isSuperAdmin() ? $this->input('company_id') : $user?->company_id;

        return [
            'company_id' => $user?->isSuperAdmin()
                ? ['required', 'uuid', Rule::exists('companies', 'id')->where('is_active', true)]
                : ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('cash_registers', 'code')->where('company_id', $companyId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
