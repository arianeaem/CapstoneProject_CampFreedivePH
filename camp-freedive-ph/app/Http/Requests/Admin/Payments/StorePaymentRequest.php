<?php

namespace App\Http\Requests\Admin\Payments;

use Illuminate\Foundation\Http\FormRequest;

/** Validation for Admin/PaymentController::store(). */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // access is checked by route middleware and policies
    }

    public function rules(): array
    {
        return [
            'booking_id' => ['required', 'exists:bookings,id'],
            'amount' => ['required', 'numeric', 'min:100'],
            'payment_method' => ['required', 'in:cash,gcash,bpi_bank_transfer,maya,other'],
            'payment_type' => ['required', 'in:downpayment,balance_settlement,full'],
            'transaction_id' => ['nullable', 'string', 'max:64'],
            'expected_balance' => ['nullable', 'numeric'], // the balance the form showed (stale-form check, ManualPaymentService)
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
