<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBookingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));
    }

    public function test_booking_page_scopes_active_pets_and_active_doctors_to_safe_choices(): void
    {
        $fixture = $this->createCustomer();
        $fixture['customer']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $fixture['customer']->pets()->create(['name' => 'Sleeping Pet', 'species' => 'Dog', 'status' => 'inactive']);
        $otherCustomer = $this->createCustomer();
        $otherCustomer['customer']->pets()->create(['name' => 'Private Pet', 'species' => 'Rabbit']);
        $this->createDoctor('Available Vet');
        $this->createDoctor('Inactive Profile Vet', profileStatus: 'inactive');
        $this->createDoctor('Inactive Account Vet', userStatus: 'inactive');

        $response = $this->actingAs($fixture['user'])->get(route('customer.bookings.create'));

        $response->assertOk()
            ->assertSee('Milo')
            ->assertDontSee('Sleeping Pet')
            ->assertDontSee('Private Pet')
            ->assertSee('Dr. Available Vet')
            ->assertDontSee('Inactive Profile Vet')
            ->assertDontSee('Inactive Account Vet');
    }

    public function test_non_customer_and_inactive_customer_cannot_open_booking_ui(): void
    {
        $doctor = $this->createDoctor('Clinic Vet');

        $this->actingAs($doctor['user'])
            ->get(route('customer.bookings.create'))
            ->assertForbidden();

        $inactiveCustomer = $this->createCustomer('inactive');
        $this->actingAs($inactiveCustomer['user'])
            ->get(route('customer.bookings.create'))
            ->assertForbidden();
    }

    public function test_customer_can_load_safe_available_slots_for_an_active_doctor(): void
    {
        $customer = $this->createCustomer();
        $doctor = $this->createDoctor('Available Vet');

        $response = $this->actingAs($customer['user'])->getJson(route('customer.booking-availability', [
            'doctor_id' => $doctor['profile']->id,
            'date' => '2026-10-05',
        ]));

        $response->assertOk()
            ->assertJsonPath('date', '2026-10-05')
            ->assertJsonPath('timezone', 'Asia/Jakarta')
            ->assertJsonPath('status', 'AVAILABLE')
            ->assertJsonCount(4, 'slots')
            ->assertJsonPath('slots.0.start_at', '2026-10-05T09:00+07:00')
            ->assertJsonPath('slots.0.doctor_schedule_id', $doctor['schedule']->id)
            ->assertJsonMissingPath('slots.0.source')
            ->assertJsonMissingPath('slots.0.reason')
            ->assertJsonMissingPath('doctor');
    }

    public function test_availability_is_customer_only_and_rejects_invalid_or_inactive_doctors(): void
    {
        $doctor = $this->createDoctor('Clinic Vet');
        $customer = $this->createCustomer();

        $this->actingAs($doctor['user'])
            ->getJson(route('customer.booking-availability', [
                'doctor_id' => $doctor['profile']->id,
                'date' => '2026-10-05',
            ]))
            ->assertForbidden();

        $this->actingAs($customer['user'])
            ->getJson(route('customer.booking-availability', [
                'doctor_id' => PHP_INT_MAX,
                'date' => '2026-10-05',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');

        $this->getJson(route('customer.booking-availability', [
            'doctor_id' => $doctor['profile']->id,
            'date' => '2026-02-30',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $inactiveCustomer = $this->createCustomer('inactive');
        $this->actingAs($inactiveCustomer['user'])
            ->getJson(route('customer.booking-availability', [
                'doctor_id' => $doctor['profile']->id,
                'date' => '2026-10-05',
            ]))
            ->assertForbidden();

        $inactiveDoctor = $this->createDoctor('Closed Vet', profileStatus: 'inactive');
        $this->actingAs($customer['user']);
        $this->getJson(route('customer.booking-availability', [
            'doctor_id' => $inactiveDoctor['profile']->id,
            'date' => '2026-10-05',
        ]))
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'This doctor is not available for online booking.',
                'slots' => [],
            ]);
    }

    public function test_browser_booking_redirects_to_safe_result_and_writes_existing_audit(): void
    {
        $customer = $this->createCustomer();
        $customer['customer']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $pet = $customer['customer']->pets()->firstOrFail();
        $doctor = $this->createDoctor('Available Vet');

        $response = $this->actingAs($customer['user'])->post(route('customer.bookings.store'), [
            '_token' => csrf_token(),
            'pet_id' => $pet->id,
            'doctor_id' => $doctor['profile']->id,
            'doctor_schedule_id' => $doctor['schedule']->id,
            'appointment_start' => '2026-10-05T09:00+07:00',
            'appointment_end' => '2026-10-05T09:30+07:00',
        ]);

        $booking = Booking::query()->sole();
        $response->assertRedirect(route('customer.bookings.show', $booking));
        $this->assertSame(Booking::STATUS_PENDING, $booking->status);
        $this->assertSame($customer['customer']->id, $booking->customer_id);

        $audit = AuditLog::query()
            ->where('action', AuditLogService::ACTION_BOOKING_CREATED)
            ->where('entity_id', $booking->id)
            ->sole();
        $this->assertSame($customer['user']->id, $audit->actor_user_id);

        $this->get(route('customer.bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->booking_code)
            ->assertSee('Monday, October 5, 2026')
            ->assertSee('09:00')
            ->assertSee('09:30 WIB')
            ->assertSee('Dr. Available Vet')
            ->assertSee('Milo (Cat)')
            ->assertSee(Booking::STATUS_PENDING)
            ->assertDontSee('slot_key')
            ->assertDontSee('customer_id');
    }

    public function test_browser_cannot_override_booking_customer_context(): void
    {
        $customer = $this->createCustomer();
        $customer['customer']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $pet = $customer['customer']->pets()->firstOrFail();
        $otherCustomer = $this->createCustomer();
        $doctor = $this->createDoctor('Available Vet');

        $this->actingAs($customer['user'])
            ->from(route('customer.bookings.create'))
            ->post(route('customer.bookings.store'), [
                'pet_id' => $pet->id,
                'doctor_id' => $doctor['profile']->id,
                'doctor_schedule_id' => $doctor['schedule']->id,
                'appointment_start' => '2026-10-05T09:00+07:00',
                'appointment_end' => '2026-10-05T09:30+07:00',
                'customer_id' => $otherCustomer['customer']->id,
            ])
            ->assertRedirect(route('customer.bookings.create'))
            ->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_slot_taken_after_availability_is_shown_returns_safe_retry_message(): void
    {
        $customer = $this->createCustomer();
        $customer['customer']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $pet = $customer['customer']->pets()->firstOrFail();
        $doctor = $this->createDoctor('Available Vet');

        $existing = new Booking;
        $existing->forceFill([
            'booking_code' => 'UVC-UI-EXISTING',
            'customer_id' => $customer['customer']->id,
            'pet_id' => $pet->id,
            'doctor_id' => $doctor['profile']->id,
            'doctor_schedule_id' => $doctor['schedule']->id,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => Booking::STATUS_PENDING,
        ])->save();

        $this->actingAs($customer['user'])
            ->from(route('customer.bookings.create'))
            ->post(route('customer.bookings.store'), [
                'pet_id' => $pet->id,
                'doctor_id' => $doctor['profile']->id,
                'doctor_schedule_id' => $doctor['schedule']->id,
                'appointment_start' => '2026-10-05T09:00+07:00',
                'appointment_end' => '2026-10-05T09:30+07:00',
            ])
            ->assertRedirect(route('customer.bookings.create'))
            ->assertSessionHasErrors(['appointment_start' => 'That appointment time is no longer available. Please choose another slot.']);

        $this->assertDatabaseCount('bookings', 1);
        $this->assertSame(0, AuditLog::query()->where('action', AuditLogService::ACTION_BOOKING_CREATED)->count());
        $this->assertDatabaseHas('bookings', ['id' => $existing->id, 'status' => Booking::STATUS_PENDING]);
    }

    public function test_empty_pet_state_is_explained(): void
    {
        $customer = $this->createCustomer();

        $this->actingAs($customer['user'])
            ->get(route('customer.bookings.create'))
            ->assertOk()
            ->assertSee('No active pets found');
    }

    public function test_empty_doctor_state_is_explained_and_guest_can_reach_sign_in_form(): void
    {
        $customer = $this->createCustomer();
        $customer['customer']->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $this->actingAs($customer['user'])
            ->get(route('customer.bookings.create'))
            ->assertOk()
            ->assertSee('No doctors are available right now');

        auth()->logout();
        $this->get(route('customer.bookings.create'))
            ->assertRedirect('/login');
        $this->get('/login')
            ->assertOk()
            ->assertSee('Customer sign in')
            ->assertSee('name="_token"', false);
    }

    private function createCustomer(string $status = 'active'): array
    {
        $role = Role::firstOrCreate(['name' => 'customer']);
        $user = User::factory()->for($role)->create(['status' => $status]);

        return ['user' => $user, 'customer' => $user->customerProfile()->create()];
    }

    private function createDoctor(
        string $name,
        string $userStatus = 'active',
        string $profileStatus = 'active',
    ): array {
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $user = User::factory()->for($role)->create(['name' => $name, 'status' => $userStatus]);
        $profile = $user->doctorProfile()->create(['status' => $profileStatus, 'specialization' => 'General care']);
        $schedule = $profile->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
        ]);

        return compact('user', 'profile', 'schedule');
    }
}
