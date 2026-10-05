<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_belongs_to_customer_pet_doctor_and_schedule(): void
    {
        $fixture = $this->createFixture();
        $booking = Booking::query()->findOrFail($this->insertBooking($fixture));

        $this->assertTrue($booking->customer->is($fixture['customer']));
        $this->assertTrue($booking->pet->is($fixture['pet']));
        $this->assertTrue($booking->doctor->is($fixture['doctor']));
        $this->assertTrue($booking->doctorSchedule->is($fixture['schedule']));
    }

    public function test_customer_profile_has_many_bookings(): void
    {
        $fixture = $this->createFixture();
        $bookingId = $this->insertBooking($fixture);

        $this->assertTrue($fixture['customer']->bookings()->whereKey($bookingId)->exists());
    }

    public function test_pet_has_many_bookings(): void
    {
        $fixture = $this->createFixture();
        $bookingId = $this->insertBooking($fixture);

        $this->assertTrue($fixture['pet']->bookings()->whereKey($bookingId)->exists());
    }

    public function test_doctor_profile_has_many_bookings(): void
    {
        $fixture = $this->createFixture();
        $bookingId = $this->insertBooking($fixture);

        $this->assertTrue($fixture['doctor']->bookings()->whereKey($bookingId)->exists());
    }

    public function test_doctor_schedule_has_many_bookings(): void
    {
        $fixture = $this->createFixture();
        $bookingId = $this->insertBooking($fixture);

        $this->assertTrue($fixture['schedule']->bookings()->whereKey($bookingId)->exists());
    }

    public function test_booking_code_must_be_unique(): void
    {
        $fixture = $this->createFixture();
        $attributes = ['booking_code' => 'UVC-BOOKING-001'];
        $this->insertBooking($fixture, $attributes);

        $this->expectException(QueryException::class);

        $this->insertBooking($fixture, $attributes);
    }

    public function test_non_null_slot_key_must_be_unique(): void
    {
        $fixture = $this->createFixture();
        $attributes = ['slot_key' => 'doctor-1-20261005-1000'];
        $this->insertBooking($fixture, $attributes);

        $this->expectException(QueryException::class);

        $this->insertBooking($fixture, $attributes);
    }

    public function test_multiple_null_slot_keys_are_allowed(): void
    {
        $fixture = $this->createFixture();

        $firstBooking = $this->insertBooking($fixture, ['booking_code' => 'UVC-NULL-001']);
        $secondBooking = $this->insertBooking($fixture, ['booking_code' => 'UVC-NULL-002']);

        $this->assertDatabaseHas('bookings', ['id' => $firstBooking, 'slot_key' => null]);
        $this->assertDatabaseHas('bookings', ['id' => $secondBooking, 'slot_key' => null]);
    }

    public function test_nonexistent_customer_profile_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $attributes = $this->bookingAttributes($fixture, ['customer_id' => PHP_INT_MAX]);

        $this->expectException(QueryException::class);
        DB::table('bookings')->insert($attributes);
    }

    public function test_nonexistent_pet_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $attributes = $this->bookingAttributes($fixture, ['pet_id' => PHP_INT_MAX]);

        $this->expectException(QueryException::class);
        DB::table('bookings')->insert($attributes);
    }

    public function test_nonexistent_doctor_profile_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $attributes = $this->bookingAttributes($fixture, ['doctor_id' => PHP_INT_MAX]);

        $this->expectException(QueryException::class);
        DB::table('bookings')->insert($attributes);
    }

    public function test_nonexistent_doctor_schedule_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $attributes = $this->bookingAttributes($fixture, ['doctor_schedule_id' => PHP_INT_MAX]);

        $this->expectException(QueryException::class);
        DB::table('bookings')->insert($attributes);
    }

    public function test_deleting_customer_profile_with_booking_is_restricted(): void
    {
        $fixture = $this->createFixture();
        $customerRole = Role::firstOrCreate(['name' => 'customer']);
        $customerWithoutPets = User::factory()->for($customerRole)->create()->customerProfile()->create();
        // Isolate the booking customer foreign key; pet ownership is service validation.
        $this->insertBooking($fixture, ['customer_id' => $customerWithoutPets->id]);

        $this->expectException(QueryException::class);
        $customerWithoutPets->delete();
    }

    public function test_deleting_pet_with_booking_is_restricted(): void
    {
        $fixture = $this->createFixture();
        $this->insertBooking($fixture);

        $this->expectException(QueryException::class);
        $fixture['pet']->delete();
    }

    public function test_deleting_doctor_profile_with_booking_is_restricted(): void
    {
        $fixture = $this->createFixture();
        $doctorRole = Role::firstOrCreate(['name' => 'doctor']);
        $doctorWithoutSchedules = User::factory()->for($doctorRole)->create()->doctorProfile()->create();
        // Isolate the booking doctor foreign key; cross-doctor schedule matching is service validation.
        $this->insertBooking($fixture, ['doctor_id' => $doctorWithoutSchedules->id]);

        $this->expectException(QueryException::class);
        $doctorWithoutSchedules->delete();
    }

    public function test_deleting_doctor_schedule_with_booking_is_restricted(): void
    {
        $fixture = $this->createFixture();
        $this->insertBooking($fixture);

        $this->expectException(QueryException::class);
        $fixture['schedule']->delete();
    }

    public function test_status_defaults_to_pending(): void
    {
        $fixture = $this->createFixture();
        $bookingId = $this->insertBooking($fixture);

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'status' => Booking::STATUS_PENDING,
        ]);
    }

    public function test_model_declares_the_five_approved_booking_statuses(): void
    {
        $this->assertSame([
            'PENDING',
            'CONFIRMED',
            'COMPLETED',
            'CANCELLED',
            'NO_SHOW',
        ], Booking::STATUSES);
    }

    public function test_appointment_datetimes_persist_as_timezone_naive_values(): void
    {
        $fixture = $this->createFixture();
        $bookingId = $this->insertBooking($fixture, [
            'appointment_start' => '2026-10-05 10:00:00',
            'appointment_end' => '2026-10-05 10:30:00',
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $bookingId,
            'appointment_start' => '2026-10-05 10:00:00',
            'appointment_end' => '2026-10-05 10:30:00',
        ]);
    }

    public function test_bookings_preserve_history_without_soft_delete_or_payment_fields(): void
    {
        $this->assertFalse(Schema::hasColumn('bookings', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('bookings', 'payment_status'));
    }

    /**
     * @return array{
     *     customer: CustomerProfile,
     *     pet: Pet,
     *     doctor: DoctorProfile,
     *     schedule: DoctorSchedule
     * }
     */
    private function createFixture(): array
    {
        $customerRole = Role::firstOrCreate(['name' => 'customer']);
        $customer = User::factory()->for($customerRole)->create()->customerProfile()->create();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $doctorRole = Role::firstOrCreate(['name' => 'doctor']);
        $doctor = User::factory()->for($doctorRole)->create()->doctorProfile()->create();
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'slot_duration_minutes' => 30,
        ]);

        return compact('customer', 'pet', 'doctor', 'schedule');
    }

    /**
     * Insert a booking directly so each test exercises database constraints.
     *
     * @param  array{
     *     customer: CustomerProfile,
     *     pet: Pet,
     *     doctor: DoctorProfile,
     *     schedule: DoctorSchedule
     * }  $fixture
     * @param  array<string, mixed>  $overrides
     */
    private function insertBooking(array $fixture, array $overrides = []): int
    {
        return (int) DB::table('bookings')->insertGetId($this->bookingAttributes($fixture, $overrides));
    }

    /**
     * @param  array{
     *     customer: CustomerProfile,
     *     pet: Pet,
     *     doctor: DoctorProfile,
     *     schedule: DoctorSchedule
     * }  $fixture
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function bookingAttributes(array $fixture, array $overrides = []): array
    {
        return array_merge([
            'booking_code' => 'UVC-'.fake()->unique()->bothify('########'),
            'customer_id' => $fixture['customer']->id,
            'pet_id' => $fixture['pet']->id,
            'doctor_id' => $fixture['doctor']->id,
            'doctor_schedule_id' => $fixture['schedule']->id,
            'appointment_start' => '2026-10-05 10:00:00',
            'appointment_end' => '2026-10-05 10:30:00',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }
}
