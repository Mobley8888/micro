<?php

namespace App\Http\Requests\Commercial;

use App\Models\BankAccount;
use App\Models\CashSession;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $invoice = $this->route('invoice');

        return $user !== null
            && $invoice instanceof Invoice
            && $user->hasPermission('payment.create')
            && ($user->isSuperAdmin() || ($user->company_id !== null && $invoice->company_id === $user->company_id));
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $invoice = $this->route('invoice');
        $user = $this->user();
        $companyId = $user?->isSuperAdmin() && $invoice instanceof Invoice ? $invoice->company_id : $user?->company_id;

        if (! $invoice instanceof Invoice || $companyId === null) {
            return [];
        }

        $paymentMethod = PaymentMethod::query()
            ->where('company_id', $companyId)
            ->find($this->input('payment_method_id'));
        $hasOwnOpenSession = $user !== null && CashSession::query()->where('company_id', $companyId)
            ->where('opened_by', $user->id)->where('status', CashSession::STATUS_OPEN)->exists();
        $bankAccountExists = BankAccount::query()->where('company_id', $companyId)->where('is_active', true)->exists();
        $bankAccountRules = $paymentMethod?->code === 'cash' ? ['prohibited'] : [$bankAccountExists ? 'required' : 'nullable', 'uuid', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)->where('is_active', true)];
        $cashSessionRules = $paymentMethod?->code === 'cash'
            ? [$hasOwnOpenSession ? 'nullable' : 'required', 'uuid', Rule::exists('cash_sessions', 'id')->where('company_id', $companyId)->where('status', 'open')]
            : ['prohibited'];

        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'lte:'.number_format((float) $invoice->balance_due, 2, '.', '')],
            'payment_date' => ['required', 'date'],
            'payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'cash_session_id' => $cashSessionRules,
            'bank_account_id' => $bankAccountRules,
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cash_session_id.required' => 'Une session de caisse ouverte est obligatoire pour un paiement en espèces.',
            'cash_session_id.exists' => 'La session choisie est inactive ou appartient à une autre entreprise.',
            'cash_session_id.prohibited' => 'Une session de caisse ne s’applique qu’aux paiements en espèces.',
        ];
    }
}
