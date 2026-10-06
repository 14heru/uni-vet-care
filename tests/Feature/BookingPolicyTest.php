<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class BookingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_and_update_own_booking_and_can_cancel_it(): void
    {
        [$customer, , $booking] = $this->createBookingFixture();

        $this->assertTrue(Gate::forUser($customer->user)->allows('view', $booking));
        $this->assertTrue(Gate::forUser($customer->user)->allows('update', $booking));
        $this->assertTrue(Gate::forUser($customer->user)->allows('cancel', $booking));
    }

    public function test_customer_cannot_view_or_act_on_another_customers_booking(): void
    {
        [$customer, , $booking] = $this->createBookingFixture();
        $otherCustomer = $this->createCustomer();

        $this->assertFalse(Gate::forUser($otherCustomer->user)->allows('view', $booking));
        $this->assertFalse(Gate::forUser($otherCustomer->user)->allows('update', $booking));
        $this->assertFalse(Gate::forUser($otherCustomer->user)->allows('cancel', $booking));
        $this->assertNotSame($customer->id, $otherCustomer->id);
    }

    public function test_customer_can_create_only_in_own_customer_context(): void
    {
        [$customer] = $this->createBookingFixture();
        $otherCustomer = $this->createCustomer();

        $this->assertTrue(Gate::forUser($customer->user)->allows('create', [Booking::class, $customer]));
        $this->assertFalse(Gate::forUser($customer->user)->allows('create', [Booking::class, $otherCustomer]));
    }

    public function test_customer_cannot_create_without_customer_profile_context(): void
    {
        $customer = $this->createCustomer();

        $this->assertFalse(Gate::forUser($customer->user)->allows('create', [Booking::class]));
    }

    public function test_admin_can_view_update_cancel_and_create_any_booking(): void
    {
        [$customer, , $booking] = $this->createBookingFixture();
        $admin = $this->createUser('admin');

        $this->assertTrue(Gate::forUser($admin)->allows('view', $booking));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $booking));
        $this->assertTrue(Gate::forUser($admin)->allows('cancel', $booking));
        $this->assertTrue(Gate::forUser($admin)->allows('create', [Booking::class, $customer]));
    }

    public function test_owner_can_view_any_booking_but_cannot_mutate_or_create(): void
    {
        [$customer, , $booking] = $this->createBookingFixture();
        $owner = $this->createUser('owner');

        $this->assertTrue(Gate::forUser($owner)->allows('view', $booking));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $booking));
        $this->assertFalse(Gate::forUser($owner)->allows('cancel', $booking));
        $this->assertFalse(Gate::forUser($owner)->allows('create', [Booking::class, $customer]));
    }

    public function test_doctor_can_view_only_bookings_for_own_doctor_profile(): void
    {
        [, $doctor, $booking] = $this->createBookingFixture();
        $otherDoctor = $this->createDoctor();

        $this->assertTrue(Gate::forUser($doctor->user)->allows('view', $booking));
        $this->assertFalse(Gate::forUser($otherDoctor->user)->allows('view', $booking));
        $this->assertFalse(Gate::forUser($doctor->user)->allows('update', $booking));
        $this->assertFalse(Gate::forUser($doctor->user)->allows('cancel', $booking));
    }

    public function test_inactive_user_is_denied_even_with_an_authorized_role(): void
    {
        [$customer, , $booking] = $this->createBookingFixture();
        $customer->user->forceFill(['status' => 'inactive'])->save();

        $this->assertFalse(Gate::forUser($customer->user)->allows('view', $booking));
        $this->assertFalse(Gate::forUser($customer->user)->allows('create', [Booking::class, $customer]));
        $this->assertFalse(Gate::forUser($customer->user)->allows('update', $booking));
        $this->assertFalse(Gate::forUser($customer->user)->allows('cancel', $booking));
    }

    public function test_inactive_admin_cannot_view_create_update_or_cancel(): void
    {
        [$customer, , $booking] = $this->createBookingFixture();
        $admin = $this->createInactiveUser('admin');

        $this->assertFalse(Gate::forUser($admin)->allows('view', $booking));
        $this->assertFalse(Gate::forUser($admin)->allows('create', [Booking::class, $customer]));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $booking));
        $this->assertFalse(Gate::forUser($admin)->allows('cancel', $booking));
    }

    public function test_inactive_owner_cannot_view_booking(): void
    {
        [, , $booking] = $this->createBookingFixture();
        $owner = $this->createInactiveUser('owner');

        $this->assertFalse(Gate::forUser($owner)->allows('view', $booking));
    }

    public function test_inactive_doctor_cannot_view_booking(): void
    {
        [, $doctor, $booking] = $this->createBookingFixture();
        $doctor->user->forceFill(['status' => 'inactive'])->save();

        $this->assertFalse(Gate::forUser($doctor->user)->allows('view', $booking));
    }

    public function test_customer_without_customer_profile_fails_closed(): void
    {
        [, , $booking] = $this->createBookingFixture();
        $customerWithoutProfile = $this->createUser('customer');

        $this->assertFalse(Gate::forUser($customerWithoutProfile)->allows('create', [Booking::class]));
        $this->assertFalse(Gate::forUser($customerWithoutProfile)->allows('view', $booking));
    }

    public function test_doctor_without_doctor_profile_fails_closed(): void
    {
        [, , $booking] = $this->createBookingFixture();
        $doctorWithoutProfile = $this->createUser('doctor');

        $this->assertFalse(Gate::forUser($doctorWithoutProfile)->allows('view', $booking));
    }

    public function test_request_supplied_customer_ids_cannot_change_the_authorized_context(): void
    {
        [$customer, , $booking] = $this->createBookingFixture();
        [, , $otherBooking] = $this->createBookingFixture();
        $otherCustomer = $this->createCustomer();
        $this->actingAs($customer->user);

        request()->query->set('customer_id', $customer->id);
        request()->query->set('booking_id', $booking->id);

        $this->assertTrue(Gate::allows('view', $booking));
        $this->assertFalse(Gate::allows('view', $otherBooking));
        $this->assertFalse(Gate::allows('create', [Booking::class, $otherCustomer]));
    }

    public function test_transition_status_authorization_uses_role_target_and_persisted_ownership(): void
    {
        [$customer, $doctor, $booking] = $this->createBookingFixture();
        [, , $otherCustomerBooking] = $this->createBookingFixture();
        $otherDoctor = $this->createDoctor();
        $admin = $this->createUser('admin');
        $owner = $this->createUser('owner');

        $this->assertTrue(Gate::forUser($customer->user)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CANCELLED],
        ));
        $this->assertFalse(Gate::forUser($customer->user)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CONFIRMED],
        ));
        $this->assertFalse(Gate::forUser($customer->user)->allows(
            'transitionStatus', [$otherCustomerBooking, Booking::STATUS_CANCELLED],
        ));

        foreach ([Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED, Booking::STATUS_NO_SHOW] as $status) {
            $this->assertTrue(Gate::forUser($doctor->user)->allows('transitionStatus', [$booking, $status]));
        }
        $this->assertFalse(Gate::forUser($doctor->user)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CANCELLED],
        ));
        $this->assertFalse(Gate::forUser($otherDoctor->user)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CONFIRMED],
        ));

        foreach (Booking::STATUSES as $status) {
            $this->assertTrue(Gate::forUser($admin)->allows('transitionStatus', [$booking, $status]));
        }
        $this->assertFalse(Gate::forUser($owner)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CANCELLED],
        ));
    }

    public function test_transition_status_denies_inactive_users_and_missing_profiles(): void
    {
        [, , $booking] = $this->createBookingFixture();

        foreach (['admin', 'customer', 'doctor', 'owner'] as $roleName) {
            $inactiveUser = $this->createInactiveUser($roleName);
            $this->assertFalse(Gate::forUser($inactiveUser)->allows(
                'transitionStatus', [$booking, Booking::STATUS_CANCELLED],
            ));
        }

        $customerWithoutProfile = $this->createUser('customer');
        $doctorWithoutProfile = $this->createUser('doctor');

        $this->assertFalse(Gate::forUser($customerWithoutProfile)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CANCELLED],
        ));
        $this->assertFalse(Gate::forUser($doctorWithoutProfile)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CONFIRMED],
        ));
    }

    public function test_transition_status_denies_unknown_target_for_admin_customer_and_doctor(): void
    {
        [$customer, $doctor, $booking] = $this->createBookingFixture();
        $admin = $this->createUser('admin');

        foreach ([$admin, $customer->user, $doctor->user] as $user) {
            $this->assertFalse(Gate::forUser($user)->allows(
                'transitionStatus', [$booking, 'NOT_A_BOOKING_STATUS'],
            ));
        }
    }

    public function test_transition_status_denies_authenticated_user_without_role_relationship(): void
    {
        [, , $booking] = $this->createBookingFixture();
        $userWithoutRole = $this->createUser('admin');
        $userWithoutRole->setAttribute('role_id', null);

        $this->assertFalse(Gate::forUser($userWithoutRole)->allows(
            'transitionStatus', [$booking, Booking::STATUS_CONFIRMED],
        ));
    }

    private function createUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::factory()->for($role)->create();
    }

    private function createInactiveUser(string $roleName): User
    {
        $user = $this->createUser($roleName);
        $user->forceFill(['status' => 'inactive'])->save();

        return $user;
    }

    private function createCustomer(): CustomerProfile
    {
        return $this->createUser('customer')->customerProfile()->create();
    }

    private function createDoctor(): DoctorProfile
    {
        return $this->createUser('doctor')->doctorProfile()->create();
    }

    /** @return array{CustomerProfile, DoctorProfile, Booking} */
    private function createBookingFixture(): array
    {
        $customer = $this->createCustomer();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $doctor = $this->createDoctor();
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'slot_duration_minutes' => 30,
        ]);

        $booking = Booking::unguarded(fn () => Booking::query()->create([
            'booking_code' => 'UVC-'.uniqid(),
            'customer_id' => $customer->id,
            'pet_id' => $pet->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => Booking::STATUS_PENDING,
        ]));

        return [$customer, $doctor, $booking];
    }
}
