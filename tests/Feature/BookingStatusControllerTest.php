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
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BookingStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $booking = $this->createBooking($this->createFixture());

        $this->postJson(route('bookings.status.transition', $booking), ['status' => Booking::STATUS_CANCELLED])
            ->assertUnauthorized();
    }

    public function test_inactive_user_is_rejected(): void
    {
        [$customer, , $booking] = $this->createFixture();
        $customer->user->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($customer->user)
            ->postJson(route('bookings.status.transition', $booking), ['status' => Booking::STATUS_CANCELLED])
            ->assertForbidden();
    }

    public function test_customer_can_cancel_own_pending_booking_and_history_is_written_once(): void
    {
        [$customer, , $booking] = $this->createFixture();

        $response = $this->actingAs($customer->user)->postJson(
            route('bookings.status.transition', $booking),
            ['status' => Booking::STATUS_CANCELLED, 'reason' => 'Customer request'],
        );

        $response->assertOk()
            ->assertJsonPath('data.booking_code', $booking->booking_code)
            ->assertJsonPath('data.status', Booking::STATUS_CANCELLED)
            ->assertJsonPath('data.appointment_start', '2026-10-05 09:00:00')
            ->assertJsonPath('data.appointment_end', '2026-10-05 09:30:00')
            ->assertJsonMissingPath('data.slot_key')
            ->assertJsonMissingPath('data.customer_id')
            ->assertJsonMissingPath('data.reason');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 1);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => Booking::STATUS_CANCELLED,
            'reason' => 'Customer request',
        ]);
    }

    public function test_customer_cannot_confirm_own_booking(): void
    {
        [$customer, , $booking] = $this->createFixture();

        $this->actingAs($customer->user)
            ->postJson(route('bookings.status.transition', $booking), ['status' => Booking::STATUS_CONFIRMED])
            ->assertForbidden();

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 0);
    }

    public function test_customer_cannot_cancel_another_customers_booking(): void
    {
        [, , $booking] = $this->createFixture();
        $otherCustomer = $this->createCustomer();

        $this->actingAs($otherCustomer->user)
            ->postJson(route('bookings.status.transition', $booking), ['status' => Booking::STATUS_CANCELLED])
            ->assertForbidden();

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 0);
    }

    public function test_doctor_can_confirm_own_booking(): void
    {
        [, $doctor, $booking] = $this->createFixture();

        $this->actingAs($doctor->user)
            ->postJson(route('bookings.status.transition', $booking), ['status' => Booking::STATUS_CONFIRMED])
            ->assertOk()
            ->assertJsonPath('data.status', Booking::STATUS_CONFIRMED);

        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => Booking::STATUS_CONFIRMED,
        ]);
    }

    public function test_doctor_can_complete_or_mark_no_show_for_own_confirmed_booking(): void
    {
        foreach ([Booking::STATUS_COMPLETED, Booking::STATUS_NO_SHOW] as $targetStatus) {
            [, $doctor, $booking] = $this->createFixture(Booking::STATUS_CONFIRMED);

            $this->actingAs($doctor->user)
                ->postJson(route('bookings.status.transition', $booking), ['status' => $targetStatus])
                ->assertOk()
                ->assertJsonPath('data.status', $targetStatus);

            $this->assertDatabaseHas('booking_status_histories', [
                'booking_id' => $booking->id,
                'status' => $targetStatus,
            ]);
        }
    }

    public function test_doctor_cannot_transition_another_doctors_booking_or_cancel(): void
    {
        [, , $booking] = $this->createFixture();
        $otherDoctor = $this->createDoctor();

        $this->actingAs($otherDoctor->user)
            ->postJson(route('bookings.status.transition', $booking), ['status' => Booking::STATUS_CONFIRMED])
            ->assertForbidden();

        [, $ownDoctor, $ownBooking] = $this->createFixture();
        $this->actingAs($ownDoctor->user)
            ->postJson(route('bookings.status.transition', $ownBooking), ['status' => Booking::STATUS_CANCELLED])
            ->assertForbidden();

        $this->assertDatabaseCount('booking_status_histories', 0);
    }

    public function test_customer_and_doctor_profile_fields_cannot_bypass_ownership(): void
    {
        [, $bookingDoctor, $booking] = $this->createFixture();
        $customer = $this->createCustomer();
        $doctor = $this->createDoctor();

        $this->actingAs($customer->user)
            ->postJson(route('bookings.status.transition', $booking), [
                'status' => Booking::STATUS_CANCELLED,
                'customer_id' => $customer->id,
                'customer_profile_id' => $customer->id,
                'doctor_id' => $doctor->id,
                'doctor_profile_id' => $doctor->id,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'customer_profile_id', 'doctor_id', 'doctor_profile_id']);

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 0);

        $otherDoctorBooking = $this->createBooking($this->createFixture());
        $this->actingAs($doctor->user)
            ->postJson(route('bookings.status.transition', $otherDoctorBooking), [
                'status' => Booking::STATUS_CONFIRMED,
                'doctor_id' => $bookingDoctor->id,
                'doctor_profile_id' => $bookingDoctor->id,
                'customer_id' => $booking->customer_id,
                'customer_profile_id' => $booking->customer_id,
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['doctor_id', 'doctor_profile_id', 'customer_id', 'customer_profile_id']);

        $this->assertSame(Booking::STATUS_PENDING, $otherDoctorBooking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 0);
    }

    public function test_active_admin_can_request_each_target_and_service_rejects_invalid_lifecycle(): void
    {
        $admin = $this->createUser('admin');
        $admin->forceFill(['status' => 'active'])->save();
        $transitions = [
            [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED, 200],
            [Booking::STATUS_PENDING, Booking::STATUS_CANCELLED, 200],
            [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED, 200],
            [Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED, 200],
            [Booking::STATUS_CONFIRMED, Booking::STATUS_NO_SHOW, 200],
            [Booking::STATUS_CONFIRMED, Booking::STATUS_PENDING, 422],
            [Booking::STATUS_PENDING, Booking::STATUS_COMPLETED, 422],
        ];

        foreach ($transitions as [$currentStatus, $targetStatus, $expectedStatus]) {
            $booking = $this->createBooking($this->createFixture($currentStatus));
            $response = $this->actingAs($admin)
                ->postJson(route('bookings.status.transition', $booking), ['status' => $targetStatus]);

            $response->assertStatus($expectedStatus);

            if ($expectedStatus === 200) {
                $response->assertJsonPath('data.status', $targetStatus);
                $this->assertDatabaseHas('booking_status_histories', [
                    'booking_id' => $booking->id,
                    'status' => $targetStatus,
                ]);
            } else {
                $response->assertExactJson([
                    'message' => 'The requested booking status transition is not allowed.',
                ]);
                $this->assertSame($currentStatus, $booking->fresh()->status);
            }
        }
    }

    public function test_inactive_admin_and_owner_cannot_transition_bookings(): void
    {
        [, , $booking] = $this->createFixture();
        $admin = $this->createUser('admin');
        $admin->forceFill(['status' => 'inactive'])->save();
        $owner = $this->createUser('owner');

        $this->actingAs($admin)->postJson(
            route('bookings.status.transition', $booking),
            ['status' => Booking::STATUS_CONFIRMED],
        )->assertForbidden();

        $this->actingAs($owner)->postJson(
            route('bookings.status.transition', $booking),
            ['status' => Booking::STATUS_CONFIRMED],
        )->assertForbidden();

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 0);
    }

    public function test_same_status_request_returns_safe_unprocessable_response(): void
    {
        $terminalBooking = $this->createBooking($this->createFixture(Booking::STATUS_CONFIRMED));
        $this->actingAs($this->createUser('admin'))->postJson(
            route('bookings.status.transition', $terminalBooking),
            ['status' => Booking::STATUS_CONFIRMED],
        )->assertUnprocessable()
            ->assertExactJson(['message' => 'The requested booking status transition is not allowed.']);

        $this->assertSame(Booking::STATUS_CONFIRMED, $terminalBooking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 0);
        $this->assertDatabaseMissing('booking_status_histories', ['booking_id' => $terminalBooking->id]);
    }

    public function test_route_binding_selects_the_booking_and_request_ids_are_rejected(): void
    {
        [$customer, , $routeBooking] = $this->createFixture();
        $otherBooking = $this->createBooking($this->createFixture());

        $this->actingAs($customer->user)->postJson(
            route('bookings.status.transition', $routeBooking),
            ['status' => Booking::STATUS_CANCELLED, 'booking_id' => $otherBooking->id],
        )->assertUnprocessable()->assertJsonValidationErrors('booking_id');

        $this->assertSame(Booking::STATUS_PENDING, $routeBooking->fresh()->status);
        $this->assertSame(Booking::STATUS_PENDING, $otherBooking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 0);
    }

    public function test_route_is_post_auth_protected_and_named(): void
    {
        $route = Route::getRoutes()->getByName('bookings.status.transition');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('bookings/{booking}/status', $route->uri());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    /** @return array{CustomerProfile, DoctorProfile, Booking} */
    private function createFixture(string $bookingStatus = Booking::STATUS_PENDING): array
    {
        $customer = $this->createCustomer();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $doctor = $this->createDoctor();
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
        ]);

        return [$customer, $doctor, $this->createBookingFrom($customer, $doctor, $schedule, $pet, $bookingStatus)];
    }

    private function createBooking(array $fixture): Booking
    {
        return $fixture[2];
    }

    private function createBookingFrom(
        CustomerProfile $customer,
        DoctorProfile $doctor,
        DoctorSchedule $schedule,
        Pet $pet,
        string $status,
    ): Booking {
        $booking = new Booking();
        $booking->forceFill([
            'booking_code' => 'UVC-HTTP-'.fake()->unique()->bothify('########'),
            'customer_id' => $customer->id,
            'pet_id' => $pet->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => $status,
        ])->save();

        return $booking;
    }

    private function createUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::factory()->for($role)->create(['status' => 'active']);
    }

    private function createCustomer(): CustomerProfile
    {
        return $this->createUser('customer')->customerProfile()->create();
    }

    private function createDoctor(): DoctorProfile
    {
        return $this->createUser('doctor')->doctorProfile()->create();
    }
}
