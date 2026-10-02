<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'source_id' => ['nullable', 'integer', Rule::exists('lead_sources', 'id')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'stage' => ['required', 'in:new,contacted,qualification,proposal,negotiation,won,lost'],
            'potential_amount' => ['nullable', 'numeric', 'min:0'],
            'next_action_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
