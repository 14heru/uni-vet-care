<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\Pet;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DoctorBookingUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));
    }

    public function test_active_doctor_can_open_doctor_bookings_and_sees_own_booking(): void
    {
        $doctor = $this->createDoctor('Dr. Jane Doe');
        $customer = $this->createCustomer('Alice Customer');
        $pet = $this->createPet($customer, 'Luna', 'Cat');

        $booking = $this->createBooking(
            doctor: $doctor['profile'],
            customer: $customer,
            pet: $pet,
            bookingCode: 'UVC-20261004-TEST0001',
            appointmentStart: '2026-10-05 09:00:00',
            appointmentEnd: '2026-10-05 09:30:00',
            status: Booking::STATUS_CONFIRMED,
        );

        $response = $this->actingAs($doctor['user'])->get(route('doctor.bookings.index'));

        $response->assertOk()
            ->assertSee('Doctor Dashboard')
            ->assertSee('My Appointments')
            ->assertSee('Dr. Jane Doe')
            ->assertSee('UVC-20261004-TEST0001')
            ->assertSee('Alice Customer')
            ->assertSee('Luna')
            ->assertSee('Cat')
            ->assertSee('CONFIRMED');
    }

    public function test_doctor_sees_own_bookings_and_not_other_doctors_bookings(): void
    {
        $doctorA = $this->createDoctor('Dr. Alice Smith');
        $doctorB = $this->createDoctor('Dr. Bob Jones');

        $customer = $this->createCustomer('Charlie Brown');
        $petA = $this->createPet($customer, 'Snoopy', 'Beagle');
        $petB = $this->createPet($customer, 'Woodstock', 'Bird');

        $bookingA = $this->createBooking(
            doctor: $doctorA['profile'],
            customer: $customer,
            pet: $petA,
            bookingCode: 'UVC-DOC-AAA-001',
            appointmentStart: '2026-10-05 09:00:00',
            appointmentEnd: '2026-10-05 09:30:00',
            status: Booking::STATUS_PENDING,
        );

        $bookingB = $this->createBooking(
            doctor: $doctorB['profile'],
            customer: $customer,
            pet: $petB,
            bookingCode: 'UVC-DOC-BBB-002',
            appointmentStart: '2026-10-05 10:00:00',
            appointmentEnd: '2026-10-05 10:30:00',
            status: Booking::STATUS_CONFIRMED,
        );

        $response = $this->actingAs($doctorA['user'])->get(route('doctor.bookings.index'));

        $response->assertOk()
            ->assertSee('UVC-DOC-AAA-001')
            ->assertSee('Snoopy')
            ->assertDontSee('UVC-DOC-BBB-002')
            ->assertDontSee('Woodstock');
    }

    public function test_customer_cannot_access_doctor_booking_list(): void
    {
        $customer = $this->createCustomer('Regular Customer');

        $this->actingAs($customer->user)
            ->get(route('doctor.bookings.index'))
            ->assertForbidden();
    }

    public function test_admin_cannot_access_doctor_booking_list(): void
    {
        $admin = $this->createUserWithRole('admin');

        $this->actingAs($admin)
            ->get(route('doctor.bookings.index'))
            ->assertForbidden();
    }

    public function test_owner_cannot_access_doctor_booking_list(): void
    {
        $owner = $this->createUserWithRole('owner');

        $this->actingAs($owner)
            ->get(route('doctor.bookings.index'))
            ->assertForbidden();
    }

    public function test_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $this->get(route('doctor.bookings.index'))
            ->assertRedirect(route('login'));
    }

    public function test_inactive_doctor_user_is_rejected(): void
    {
        $doctor = $this->createDoctor('Dr. Inactive User', userStatus: 'inactive');

        $this->actingAs($doctor['user'])
            ->get(route('doctor.bookings.index'))
            ->assertForbidden();
    }

    public function test_inactive_doctor_profile_is_rejected(): void
    {
        $doctor = $this->createDoctor('Dr. Inactive Profile', profileStatus: 'inactive');

        $this->actingAs($doctor['user'])
            ->get(route('doctor.bookings.index'))
            ->assertForbidden();
    }

    public function test_empty_state_is_displayed_when_doctor_has_no_bookings(): void
    {
        $doctor = $this->createDoctor('Dr. Free Doctor');

        $response = $this->actingAs($doctor['user'])->get(route('doctor.bookings.index'));

        $response->assertOk()
            ->assertSee('No appointments scheduled')
            ->assertSee('You do not have any appointments on your schedule at this time.')
            ->assertDontSee('doctor-appointments-table');
    }

    public function test_display_data_renders_all_required_booking_fields(): void
    {
        $doctor = $this->createDoctor('Dr. Detail Tester');
        $customer = $this->createCustomer('Johnathan Davis');
        $pet = $this->createPet($customer, 'Bella', 'Golden Retriever');

        $this->createBooking(
            doctor: $doctor['profile'],
            customer: $customer,
            pet: $pet,
            bookingCode: 'UVC-20261005-DETAIL99',
            appointmentStart: '2026-10-05 09:00:00',
            appointmentEnd: '2026-10-05 09:30:00',
            status: Booking::STATUS_PENDING,
        );

        $response = $this->actingAs($doctor['user'])->get(route('doctor.bookings.index'));

        $response->assertOk()
            ->assertSee('UVC-20261005-DETAIL99')
            ->assertSee('Johnathan Davis')
            ->assertSee('Bella')
            ->assertSee('Golden Retriever')
            ->assertSee('Monday, October 5, 2026')
            ->assertSee('09:00 - 09:30 WIB')
            ->assertSee('PENDING');
    }

    public function test_doctor_route_is_named_and_protected_by_auth_and_doctor_role_middleware(): void
    {
        $route = Route::getRoutes()->getByName('doctor.bookings.index');

        $this->assertNotNull($route);
        $this->assertSame('GET', $route->methods()[0]);
        $this->assertSame('doctor/bookings', $route->uri());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('role:doctor', $route->gatherMiddleware());
    }

    /**
     * @return array{user: User, profile: DoctorProfile}
     */
    private function createDoctor(
        string $name,
        string $userStatus = 'active',
        string $profileStatus = 'active',
    ): array {
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $user = User::factory()->for($role)->create([
            'name' => $name,
            'status' => $userStatus,
        ]);
        $profile = $user->doctorProfile()->create([
            'status' => $profileStatus,
            'specialization' => 'General Medicine',
            'license_number' => 'VET-' . strtoupper(fake()->bothify('??####')),
        ]);

        return compact('user', 'profile');
    }

    private function createCustomer(string $name, string $status = 'active'): CustomerProfile
    {
        $role = Role::firstOrCreate(['name' => 'customer']);
        $user = User::factory()->for($role)->create([
            'name' => $name,
            'status' => $status,
        ]);

        return $user->customerProfile()->create();
    }

    private function createPet(CustomerProfile $customer, string $name, string $species): Pet
    {
        return $customer->pets()->create([
            'name' => $name,
            'species' => $species,
            'status' => 'active',
        ]);
    }

    private function createUserWithRole(string $roleName, string $status = 'active'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::factory()->for($role)->create(['status' => $status]);
    }

    private function createBooking(
        DoctorProfile $doctor,
        CustomerProfile $customer,
        Pet $pet,
        string $bookingCode,
        string $appointmentStart,
        string $appointmentEnd,
        string $status = Booking::STATUS_PENDING,
    ): Booking {
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
        ]);

        $booking = new Booking();
        $booking->forceFill([
            'booking_code' => $bookingCode,
            'customer_id' => $customer->id,
            'pet_id' => $pet->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_start' => $appointmentStart,
            'appointment_end' => $appointmentEnd,
            'status' => $status,
            'slot_key' => $status === Booking::STATUS_CANCELLED ? null : "{$doctor->id}:{$appointmentStart}",
        ]);
        $booking->save();

        return $booking;
    }
}
