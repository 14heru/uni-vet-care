<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingStatusHistory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BookingStatusHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_has_many_status_history_entries(): void
    {
        $booking = $this->createBooking();

        $history = $booking->statusHistories()->create([
            'status' => Booking::STATUS_PENDING,
            'reason' => 'Booking created',
        ]);

        $this->assertInstanceOf(BookingStatusHistory::class, $history);
        $this->assertTrue($history->booking->is($booking));
        $this->assertTrue($booking->statusHistories()->whereKey($history->getKey())->exists());
    }

    public function test_multiple_history_rows_are_retrieved_in_chronological_order(): void
    {
        $booking = $this->createBooking();
        $pending = $booking->statusHistories()->create(['status' => Booking::STATUS_PENDING]);
        $confirmed = $booking->statusHistories()->create(['status' => Booking::STATUS_CONFIRMED]);
        $completed = $booking->statusHistories()->create(['status' => Booking::STATUS_COMPLETED]);

        DB::table('booking_status_histories')->where('id', $pending->id)->update(['created_at' => '2026-10-05 09:00:00']);
        DB::table('booking_status_histories')->where('id', $confirmed->id)->update(['created_at' => '2026-10-05 09:30:00']);
        DB::table('booking_status_histories')->where('id', $completed->id)->update(['created_at' => '2026-10-05 10:00:00']);

        $ordered = $booking->statusHistories()->get();

        $this->assertSame([$pending->id, $confirmed->id, $completed->id], $ordered->modelKeys());
        $this->assertSame(3, $booking->statusHistories()->count());
    }

    public function test_status_history_rejects_a_nonexistent_booking_foreign_key(): void
    {
        $this->expectException(QueryException::class);

        DB::table('booking_status_histories')->insert([
            'booking_id' => PHP_INT_MAX,
            'status' => Booking::STATUS_PENDING,
            'reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_deleting_a_booking_with_status_history_is_restricted(): void
    {
        $booking = $this->createBooking();
        $booking->statusHistories()->create(['status' => Booking::STATUS_PENDING]);

        $this->expectException(QueryException::class);

        $booking->delete();
    }

    public function test_all_approved_booking_status_values_can_be_stored(): void
    {
        $booking = $this->createBooking();

        foreach (Booking::STATUSES as $status) {
            $history = $booking->statusHistories()->create(['status' => $status]);
            $this->assertDatabaseHas('booking_status_histories', [
                'id' => $history->id,
                'booking_id' => $booking->id,
                'status' => $status,
            ]);
        }
    }

    public function test_reason_is_nullable_and_timestamps_are_stored(): void
    {
        $booking = $this->createBooking();
        $history = $booking->statusHistories()->create(['status' => Booking::STATUS_PENDING]);

        $this->assertDatabaseHas('booking_status_histories', [
            'id' => $history->id,
            'reason' => null,
        ]);
        $this->assertNotNull($history->fresh()->created_at);
        $this->assertNotNull($history->fresh()->updated_at);
    }

    public function test_history_lookup_index_starts_with_booking_id_and_created_at(): void
    {
        $indexes = Schema::getIndexes('booking_status_histories');
        $index = collect($indexes)->firstWhere('name', 'booking_status_histories_booking_created');

        $this->assertNotNull($index);
        $this->assertSame(['booking_id', 'created_at'], $index['columns']);
    }

    public function test_status_history_preserves_records_without_soft_deletes_or_payment_fields(): void
    {
        $this->assertFalse(Schema::hasColumn('booking_status_histories', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('booking_status_histories', 'payment_status'));
    }

    private function createBooking(): Booking
    {
        $customerRole = Role::firstOrCreate(['name' => 'customer']);
        $customer = User::factory()->for($customerRole)->create()->customerProfile()->create();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $doctorRole = Role::firstOrCreate(['name' => 'doctor']);
        $doctor = User::factory()->for($doctorRole)->create()->doctorProfile()->create();
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
        ]);

        $booking = new Booking();
        $booking->forceFill([
            'booking_code' => 'UVC-HISTORY-'.fake()->unique()->bothify('########'),
            'customer_id' => $customer->id,
            'pet_id' => $pet->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => Booking::STATUS_PENDING,
        ])->save();

        return $booking;
    }
}
