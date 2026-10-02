<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuickStoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->company_id !== null && $this->user()->hasPermission('customer.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'type' => ['required', 'in:individual,company,institution'],
            'legal_name' => ['nullable', 'string', 'max:255', 'required_if:type,company,institution'],
            'first_name' => ['nullable', 'string', 'max:100', 'required_if:type,individual'],
            'last_name' => ['nullable', 'string', 'max:100', 'required_if:type,individual'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'integer', Rule::exists('customer_categories', 'id')->where('company_id', $companyId)],
        ];
    }
}
