<?php

namespace Tests\Feature;

use App\Http\Requests\StoreBookingRequest;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StoreBookingRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('auth')->post('/_test/bookings', function (StoreBookingRequest $request) {
            return response()->json($request->validated());
        });
    }

    public function test_valid_booking_input_passes_and_only_validated_client_fields_are_returned(): void
    {
        $customer = $this->createCustomer();
        $doctor = $this->createDoctor();
        $schedule = $this->createSchedule($doctor);
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $response = $this->actingAs($customer->user)->postJson('/_test/bookings', [
            ...$this->validInput($pet->id, $doctor->id, $schedule->id),
            'role' => 'admin',
            'is_admin' => true,
            'user_id' => 999,
            'role_id' => 999,
            'actor_user_id' => 999,
            'changed_by' => 999,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ]);

        $response->assertOk()->assertExactJson([
            'pet_id' => $pet->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_start' => '2026-10-05T09:00+07:00',
            'appointment_end' => '2026-10-05T09:30+07:00',
        ]);
    }

    public function test_missing_doctor_id_fails_validation(): void
    {
        [$customer, $input] = $this->customerAndValidInput();
        unset($input['doctor_id']);

        $this->actingAs($customer->user)->postJson('/_test/bookings', $input)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');
    }

    public function test_missing_doctor_schedule_id_fails_validation(): void
    {
        [$customer, $input] = $this->customerAndValidInput();
        unset($input['doctor_schedule_id']);

        $this->actingAs($customer->user)->postJson('/_test/bookings', $input)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_schedule_id');
    }

    public function test_nonexistent_doctor_or_schedule_fails_validation(): void
    {
        [$customer, $input] = $this->customerAndValidInput();

        $this->actingAs($customer->user)->postJson('/_test/bookings', [
            ...$input,
            'doctor_id' => 999999,
            'doctor_schedule_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['doctor_id', 'doctor_schedule_id']);
    }

    public function test_malformed_or_non_datetime_appointment_values_fail_validation(): void
    {
        [$customer, $input] = $this->customerAndValidInput();

        $this->actingAs($customer->user)->postJson('/_test/bookings', [
            ...$input,
            'appointment_start' => 'not-a-datetime',
            'appointment_end' => '2026-10-05 09:30:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['appointment_start', 'appointment_end']);
    }

    public function test_start_must_be_before_end_and_cross_midnight_is_rejected(): void
    {
        [$customer, $input] = $this->customerAndValidInput();

        $this->actingAs($customer->user)->postJson('/_test/bookings', [
            ...$input,
            'appointment_start' => '2026-10-05T09:30+07:00',
            'appointment_end' => '2026-10-05T09:30+07:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('appointment_end');

        $this->actingAs($customer->user)->postJson('/_test/bookings', [
            ...$input,
            'appointment_start' => '2026-10-05T23:30+07:00',
            'appointment_end' => '2026-10-06T00:00+07:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('appointment_end');
    }

    public function test_customer_id_is_rejected_and_does_not_choose_authorization_context(): void
    {
        [$customer, $input] = $this->customerAndValidInput();
        $otherCustomer = $this->createCustomer();

        $this->actingAs($customer->user)->postJson('/_test/bookings', [
            ...$input,
            'customer_id' => $otherCustomer->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_server_controlled_booking_and_payment_fields_are_rejected(): void
    {
        [$customer, $input] = $this->customerAndValidInput();
        $privilegedFields = [
            'customer_profile_id' => 999,
            'booking_code' => 'CLIENT-CODE',
            'status' => 'CONFIRMED',
            'slot_key' => 'client-slot-key',
            'payment' => ['secret' => 'client-secret'],
            'payment_status' => 'PAID',
            'payment_secret' => 'client-secret',
            'payment_method' => 'anything',
            'payment_amount' => 1,
            'payment_reference' => 'client-reference',
            'payment_id' => 123,
            'transaction_id' => 'client-transaction',
        ];

        $response = $this->actingAs($customer->user)->postJson('/_test/bookings', [
            ...$input,
            ...$privilegedFields,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(array_keys($privilegedFields));
    }

    public function test_customer_authorization_uses_the_policy_and_authenticated_profile(): void
    {
        [$customer, $input] = $this->customerAndValidInput();

        $this->actingAs($customer->user)->postJson('/_test/bookings', $input)->assertOk();

        $customerWithoutProfile = $this->createUser('customer');
        $this->actingAs($customerWithoutProfile)->postJson('/_test/bookings', $input)->assertForbidden();
    }

    public function test_unauthenticated_user_is_rejected(): void
    {
        [, $input] = $this->customerAndValidInput();

        $this->postJson('/_test/bookings', $input)->assertUnauthorized();
    }

    public function test_inactive_user_is_rejected_by_authorization(): void
    {
        [$customer, $input] = $this->customerAndValidInput();
        $customer->user->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($customer->user)->postJson('/_test/bookings', $input)->assertForbidden();
    }

    public function test_admin_can_pass_authorization_without_a_customer_profile(): void
    {
        [$customer, $input] = $this->customerAndValidInput();
        $admin = $this->createUser('admin');

        $this->assertNull($admin->customerProfile()->first());
        $this->actingAs($admin)->postJson('/_test/bookings', $input)->assertOk();
    }

    /** @return array{CustomerProfile, array<string, mixed>} */
    private function customerAndValidInput(): array
    {
        $customer = $this->createCustomer();
        $doctor = $this->createDoctor();
        $schedule = $this->createSchedule($doctor);
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        return [$customer, $this->validInput($pet->id, $doctor->id, $schedule->id)];
    }

    /** @return array<string, mixed> */
    private function validInput(int $petId, int $doctorId, int $scheduleId): array
    {
        return [
            'pet_id' => $petId,
            'doctor_id' => $doctorId,
            'doctor_schedule_id' => $scheduleId,
            'appointment_start' => '2026-10-05T09:00+07:00',
            'appointment_end' => '2026-10-05T09:30+07:00',
        ];
    }

    private function createUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::factory()->for($role)->create();
    }

    private function createCustomer(): CustomerProfile
    {
        return $this->createUser('customer')->customerProfile()->create();
    }

    private function createDoctor(): DoctorProfile
    {
        return $this->createUser('doctor')->doctorProfile()->create();
    }

    private function createSchedule(DoctorProfile $doctor): DoctorSchedule
    {
        return $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'slot_duration_minutes' => 30,
        ]);
    }
}
