<?php

namespace App\Http\Requests;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TransitionBookingStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Booking::STATUSES)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'booking_id' => ['missing'],
            'customer_id' => ['missing'],
            'customer_profile_id' => ['missing'],
            'doctor_id' => ['missing'],
            'doctor_profile_id' => ['missing'],
            'user_id' => ['missing'],
            'role' => ['missing'],
            'slot_key' => ['missing'],
            'booking_code' => ['missing'],
            'payment' => ['missing'],
            'payment_status' => ['missing'],
            'payment_method' => ['missing'],
            'payment_amount' => ['missing'],
            'payment_reference' => ['missing'],
            'payment_id' => ['missing'],
            'payment_secret' => ['missing'],
            'transaction_id' => ['missing'],
            'pet_id' => ['missing'],
            'doctor_schedule_id' => ['missing'],
            'appointment_start' => ['missing'],
            'appointment_end' => ['missing'],
            'actor_user_id' => ['missing'],
            'changed_by' => ['missing'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedInput = ['status', 'reason', '_token'];

            foreach (array_diff(array_keys($this->all()), $allowedInput) as $unexpectedField) {
                $validator->errors()->add($unexpectedField, 'This field is not allowed.');
            }
        });
    }
}
