<?php

namespace Tests\Feature;

use App\Http\Requests\TransitionBookingStatusRequest;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TransitionBookingStatusRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth')->post('/_test/bookings/{booking}/status', function (TransitionBookingStatusRequest $request) {
            return response()->json($request->validated());
        });
    }

    public function test_valid_status_and_optional_reason_are_the_only_validated_inputs(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/_test/bookings/1/status', [
            'status' => Booking::STATUS_CONFIRMED,
            'reason' => 'Confirmed by clinic staff',
        ])->assertOk()->assertExactJson([
            'status' => Booking::STATUS_CONFIRMED,
            'reason' => 'Confirmed by clinic staff',
        ]);
    }

    public function test_status_is_required_and_limited_to_the_five_known_values(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/_test/bookings/1/status', [])->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($user)->postJson('/_test/bookings/1/status', ['status' => 'confirmed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        foreach (Booking::STATUSES as $status) {
            $this->actingAs($user)->postJson('/_test/bookings/1/status', ['status' => $status])->assertOk();
        }
    }

    public function test_reason_is_nullable_and_length_bounded(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/_test/bookings/1/status', [
            'status' => Booking::STATUS_CANCELLED,
            'reason' => null,
        ])->assertOk()->assertExactJson([
            'status' => Booking::STATUS_CANCELLED,
            'reason' => null,
        ]);

        $this->actingAs($user)->postJson('/_test/bookings/1/status', [
            'status' => Booking::STATUS_CANCELLED,
            'reason' => str_repeat('a', 1001),
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_privileged_and_arbitrary_booking_fields_are_rejected(): void
    {
        $user = $this->createUser();
        $fields = [
            'booking_id' => 99,
            'customer_id' => 99,
            'customer_profile_id' => 99,
            'doctor_id' => 99,
            'doctor_profile_id' => 99,
            'user_id' => 99,
            'role' => 'admin',
            'slot_key' => 'client-slot',
            'booking_code' => 'CLIENT-CODE',
            'payment' => ['secret' => 'client-secret'],
            'payment_status' => 'PAID',
            'payment_reference' => 'client-reference',
            'status_reason' => 'arbitrary field',
            'appointment_start' => '2026-10-05 09:00:00',
        ];

        $this->actingAs($user)->postJson('/_test/bookings/1/status', [
            'status' => Booking::STATUS_CONFIRMED,
            ...$fields,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($fields));
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        $this->postJson('/_test/bookings/1/status', ['status' => Booking::STATUS_CONFIRMED])
            ->assertUnauthorized();
    }

    private function createUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'customer']);

        return User::factory()->for($role)->create();
    }
}
