<?php

namespace App\Http\Requests;

use App\Models\Booking;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $customer = $user->customerProfile()->first();

        return Gate::forUser($user)->allows('create', [Booking::class, $customer]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'pet_id' => ['required', 'integer', 'exists:pets,id'],
            'doctor_id' => ['required', 'integer', 'exists:doctor_profiles,id'],
            'doctor_schedule_id' => ['required', 'integer', 'exists:doctor_schedules,id'],
            'appointment_start' => ['required', 'date_format:Y-m-d\TH:iP'],
            'appointment_end' => ['required', 'date_format:Y-m-d\TH:iP', 'after:appointment_start'],
            'customer_id' => ['missing'],
            'customer_profile_id' => ['missing'],
            'booking_code' => ['missing'],
            'slot_key' => ['missing'],
            'status' => ['missing'],
            'payment' => ['missing'],
            'payment_status' => ['missing'],
            'payment_method' => ['missing'],
            'payment_amount' => ['missing'],
            'payment_reference' => ['missing'],
            'payment_id' => ['missing'],
            'payment_secret' => ['missing'],
            'transaction_id' => ['missing'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (
                $validator->errors()->has('appointment_start')
                || $validator->errors()->has('appointment_end')
            ) {
                return;
            }

            $start = $this->input('appointment_start');
            $end = $this->input('appointment_end');

            if (substr($start, 0, 10) !== substr($end, 0, 10)) {
                $validator->errors()->add('appointment_end', 'The appointment must end on the same date it starts.');
            }
        });
    }
}
