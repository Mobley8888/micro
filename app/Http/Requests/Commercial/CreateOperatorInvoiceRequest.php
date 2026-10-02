<?php

namespace App\Http\Requests\Commercial;

use App\Models\BankAccount;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateOperatorInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->hasPermission('invoice.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'customer_id' => ['required', 'uuid', Rule::exists('customers', 'id')->where('company_id', $companyId)->where('status', 'active')],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,3'],
            'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'payment_method_id' => ['nullable', 'uuid', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'bank_account_id' => ['nullable', 'uuid', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'payment_amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0'],
            'payment_date' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'uuid'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->filled('payment_amount') && ! $this->filled('payment_method_id')) {
                $validator->errors()->add('payment_method_id', 'Choisissez un mode de paiement pour enregistrer un règlement.');
            }

            if ($this->filled('payment_method_id') && ! $this->user()?->hasPermission('payment.create')) {
                $validator->errors()->add('payment_method_id', 'Vous ne pouvez pas enregistrer de paiement.');
            }
            if ($this->filled('payment_method_id')) {
                $companyId = $this->user()?->company_id;
                $method = PaymentMethod::query()->where('company_id', $companyId)->find($this->input('payment_method_id'));
                $hasBankAccounts = BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->exists();
                if ($method?->code === 'cash' && $this->filled('bank_account_id')) {
                    $validator->errors()->add('bank_account_id', 'Un compte bancaire ne s’applique pas à un paiement en espèces.');
                } elseif ($method?->code !== 'cash' && $hasBankAccounts && ! $this->filled('bank_account_id')) {
                    $validator->errors()->add('bank_account_id', 'Sélectionnez le compte bancaire destinataire.');
                }
            }
        });
    }
}
