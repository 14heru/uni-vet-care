<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

class CustomerBookingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));
    }

    public function test_authenticated_customer_can_create_a_valid_booking(): void
    {
        $fixture = $this->createFixture();

        $response = $this->actingAs($fixture['customer']->user)
            ->postJson(route('customer.bookings.store'), $this->validInput($fixture));

        $response->assertCreated()
            ->assertJsonPath('data.status', Booking::STATUS_PENDING)
            ->assertJsonPath('data.appointment_start', '2026-10-05 09:00:00')
            ->assertJsonPath('data.appointment_end', '2026-10-05 09:30:00')
            ->assertJsonMissingPath('data.slot_key')
            ->assertJsonMissingPath('data.customer_id')
            ->assertJsonMissingPath('data.pet_id');

        $this->assertDatabaseHas('bookings', [
            'booking_code' => $response->json('data.booking_code'),
            'customer_id' => $fixture['customer']->id,
            'pet_id' => $fixture['pet']->id,
            'doctor_id' => $fixture['doctor']->id,
            'doctor_schedule_id' => $fixture['schedule']->id,
            'status' => Booking::STATUS_PENDING,
        ]);

        $booking = Booking::query()->where('booking_code', $response->json('data.booking_code'))->firstOrFail();
        $this->assertNotEmpty($booking->slot_key);
        $this->assertMatchesRegularExpression('/^UVC-20261004-[A-Z0-9]{10}$/', $booking->booking_code);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson(route('customer.bookings.store'), [])->assertUnauthorized();
    }

    public function test_inactive_customer_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $fixture['customer']->user->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($fixture['customer']->user)
            ->postJson(route('customer.bookings.store'), $this->validInput($fixture))
            ->assertForbidden();
    }

    public function test_customer_cannot_book_another_customers_pet(): void
    {
        $fixture = $this->createFixture();
        $otherCustomer = $this->createCustomer();
        $otherPet = $otherCustomer->pets()->create(['name' => 'Luna', 'species' => 'Cat']);

        $response = $this->actingAs($fixture['customer']->user)
            ->postJson(route('customer.bookings.store'), $this->validInput($fixture, petId: $otherPet->id));

        $response->assertUnprocessable()
            ->assertExactJson(['message' => 'The selected appointment slot is no longer available.']);
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_customer_context_fields_cannot_change_booking_ownership(): void
    {
        $fixture = $this->createFixture();
        $otherCustomer = $this->createCustomer();

        $this->actingAs($fixture['customer']->user)
            ->postJson(route('customer.bookings.store'), [
                ...$this->validInput($fixture),
                'customer_id' => $otherCustomer->id,
                'customer_profile_id' => $otherCustomer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_id', 'customer_profile_id']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_server_controlled_booking_and_payment_fields_cannot_control_created_booking(): void
    {
        $fixture = $this->createFixture();

        $this->actingAs($fixture['customer']->user)
            ->postJson(route('customer.bookings.store'), [
                ...$this->validInput($fixture),
                'booking_code' => 'CLIENT-CODE',
                'slot_key' => 'CLIENT-SLOT',
                'status' => Booking::STATUS_CONFIRMED,
                'payment_status' => 'PAID',
                'payment_reference' => 'CLIENT-REFERENCE',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['booking_code', 'slot_key', 'status', 'payment_status', 'payment_reference']);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_doctor_and_owner_cannot_use_customer_booking_endpoint(): void
    {
        $fixture = $this->createFixture();

        foreach (['doctor', 'owner'] as $roleName) {
            $this->actingAs($this->createUser($roleName))
                ->postJson(route('customer.bookings.store'), $this->validInput($fixture))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_admin_can_create_booking_without_customer_profile_context(): void
    {
        $fixture = $this->createFixture();
        $admin = $this->createUser('admin');

        $this->assertNull($admin->customerProfile()->first());

        $response = $this->actingAs($admin)
            ->postJson(route('customer.bookings.store'), $this->validInput($fixture));

        $response->assertCreated()->assertJsonPath('data.status', Booking::STATUS_PENDING);
        $this->assertDatabaseHas('bookings', [
            'booking_code' => $response->json('data.booking_code'),
            'customer_id' => $fixture['customer']->id,
            'pet_id' => $fixture['pet']->id,
        ]);
    }

    public function test_invalid_request_fails_before_booking_service_runs(): void
    {
        $fixture = $this->createFixture();
        $this->mock(BookingService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('create');
        });
        $input = $this->validInput($fixture);
        $input['appointment_start'] = 'not-a-datetime';

        $this->actingAs($fixture['customer']->user)
            ->postJson(route('customer.bookings.store'), $input)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('appointment_start');

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_unavailable_slot_returns_safe_business_error(): void
    {
        $fixture = $this->createFixture();

        $response = $this->actingAs($fixture['customer']->user)
            ->postJson(route('customer.bookings.store'), $this->validInput(
                $fixture,
                start: '2026-10-05T09:15+07:00',
                end: '2026-10-05T09:45+07:00',
            ));

        $response->assertUnprocessable()
            ->assertExactJson(['message' => 'The selected appointment slot is no longer available.'])
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('QueryException');
        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_booking_route_is_named_and_protected_for_customer_and_admin_roles(): void
    {
        $route = Route::getRoutes()->getByName('customer.bookings.store');

        $this->assertNotNull($route);
        $this->assertSame('POST', $route->methods()[0]);
        $this->assertSame('customer/bookings', $route->uri());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('role:customer,admin', $route->gatherMiddleware());
    }

    /** @return array{customer: CustomerProfile, pet: Pet, doctor: DoctorProfile, schedule: DoctorSchedule} */
    private function createFixture(): array
    {
        $customer = $this->createCustomer();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $doctorUser = $this->createUser('doctor');
        $doctor = $doctorUser->doctorProfile()->create();
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
        ]);

        return compact('customer', 'pet', 'doctor', 'schedule');
    }

    private function createCustomer(): CustomerProfile
    {
        return $this->createUser('customer')->customerProfile()->create();
    }

    private function createUser(string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        return User::factory()->for($role)->create();
    }

    /** @param array{customer: CustomerProfile, pet: Pet, doctor: DoctorProfile, schedule: DoctorSchedule} $fixture
     * @return array<string, int|string>
     */
    private function validInput(
        array $fixture,
        ?int $petId = null,
        string $start = '2026-10-05T09:00+07:00',
        string $end = '2026-10-05T09:30+07:00',
    ): array {
        return [
            'pet_id' => $petId ?? $fixture['pet']->id,
            'doctor_id' => $fixture['doctor']->id,
            'doctor_schedule_id' => $fixture['schedule']->id,
            'appointment_start' => $start,
            'appointment_end' => $end,
        ];
    }
}
