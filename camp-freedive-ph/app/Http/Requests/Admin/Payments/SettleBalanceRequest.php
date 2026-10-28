<?php

namespace App\Http\Requests\Admin\Payments;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/PaymentController::settleBalance(). */
class SettleBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'in:cash,gcash,bpi_bank_transfer,maya,card,other'],
            'transaction_id' => ['nullable', 'string', 'max:64'],
            'expected_balance' => ['nullable', 'numeric'], // the balance the form showed (stale-form check, ManualPaymentService)
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
