<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\AvailabilityResult;
use App\Services\DoctorAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DoctorAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private DoctorAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DoctorAvailabilityService();
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));
    }

    public function test_normal_recurring_schedule_generates_expected_slots(): void
    {
        $doctor = $this->createDoctorWithSchedule();

        $result = $this->availability($doctor);

        $this->assertSame('AVAILABLE', $result->status);
        $this->assertSame([
            '2026-10-05T09:00+07:00',
            '2026-10-05T09:30+07:00',
            '2026-10-05T10:00+07:00',
            '2026-10-05T10:30+07:00',
        ], array_column($result->slots, 'start_at'));
    }

    public function test_multiple_schedule_rows_with_the_same_duration_generate_slots(): void
    {
        $doctor = $this->createDoctorWithSchedule(['start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
        ]);

        $result = $this->availability($doctor);

        $this->assertSame('AVAILABLE', $result->status);
        $this->assertCount(4, $result->slots);
    }

    public function test_inactive_doctor_profile_is_unavailable(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        $doctor->update(['status' => 'inactive']);

        $result = $this->availability($doctor);

        $this->assertUnavailable($result, 'DOCTOR_UNAVAILABLE');
    }

    public function test_inactive_user_is_unavailable(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        DB::table('users')->where('id', $doctor->user_id)->update(['status' => 'inactive']);

        $result = $this->availability($doctor);

        $this->assertUnavailable($result, 'DOCTOR_UNAVAILABLE');
    }

    public function test_inactive_recurring_schedule_is_unavailable(): void
    {
        $doctor = $this->createDoctorWithSchedule(['status' => 'inactive']);

        $this->assertUnavailable($this->availability($doctor), 'NO_ACTIVE_SCHEDULE');
    }

    public function test_no_recurring_schedule_for_the_weekday_is_unavailable(): void
    {
        $doctor = $this->createDoctorWithSchedule();

        $this->assertUnavailable(
            $this->availability($doctor, CarbonImmutable::parse('2026-10-06', 'UTC')),
            'NO_ACTIVE_SCHEDULE'
        );
    }

    public function test_active_unavailable_exception_overrides_the_recurring_schedule(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        $this->createException($doctor, ['type' => 'UNAVAILABLE']);

        $this->assertUnavailable($this->availability($doctor), 'DATE_UNAVAILABLE');
    }

    public function test_active_modified_exception_replaces_recurring_hours(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        $this->createException($doctor, [
            'type' => 'MODIFIED',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $result = $this->availability($doctor);

        $this->assertSame('AVAILABLE', $result->status);
        $this->assertSame([
            '2026-10-05T10:00+07:00',
            '2026-10-05T10:30+07:00',
        ], array_column($result->slots, 'start_at'));
        $this->assertSame(['MODIFIED_EXCEPTION', 'MODIFIED_EXCEPTION'], array_column($result->slots, 'source'));
    }

    public function test_modified_exception_on_unscheduled_weekday_is_unavailable(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        $this->createException($doctor, [
            'exception_date' => '2026-10-06',
            'type' => 'MODIFIED',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $this->assertUnavailable(
            $this->availability($doctor, CarbonImmutable::parse('2026-10-06', 'UTC')),
            'NO_ACTIVE_SCHEDULE'
        );
    }

    public function test_inactive_exception_is_ignored(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        $this->createException($doctor, ['status' => 'inactive', 'type' => 'UNAVAILABLE']);

        $result = $this->availability($doctor);

        $this->assertSame('AVAILABLE', $result->status);
        $this->assertCount(4, $result->slots);
    }

    public function test_modified_exception_with_different_schedule_durations_fails_closed(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '13:00:00',
            'end_time' => '14:00:00',
            'slot_duration_minutes' => 60,
        ]);
        $this->createException($doctor, [
            'type' => 'MODIFIED',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $this->assertUnavailable($this->availability($doctor), 'INVALID_SCHEDULE_CONFIGURATION');
    }

    public function test_interval_equal_to_one_slot_duration_generates_one_slot(): void
    {
        $doctor = $this->createDoctorWithSchedule([
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
        ]);

        $result = $this->availability($doctor);

        $this->assertCount(1, $result->slots);
        $this->assertSame('2026-10-05T09:30+07:00', $result->slots[0]['end_at']);
    }

    public function test_incomplete_remainder_is_dropped(): void
    {
        $doctor = $this->createDoctorWithSchedule([
            'start_time' => '09:00:00',
            'end_time' => '10:10:00',
        ]);

        $result = $this->availability($doctor);

        $this->assertSame([
            '2026-10-05T09:00+07:00',
            '2026-10-05T09:30+07:00',
        ], array_column($result->slots, 'start_at'));
    }

    public function test_today_past_current_and_future_slot_boundaries(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:15:00', 'Asia/Jakarta'));
        $doctor = $this->createDoctorWithSchedule(['end_time' => '12:00:00']);

        $result = $this->availability($doctor);

        $this->assertSame(
            ['UNAVAILABLE', 'UNAVAILABLE', 'UNAVAILABLE', 'AVAILABLE'],
            array_slice(array_column($result->slots, 'status'), 0, 4)
        );

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:30:00', 'Asia/Jakarta'));
        $atSlotStart = $this->availability($doctor);
        $this->assertSame('UNAVAILABLE', $atSlotStart->slots[3]['status']);
        $this->assertSame('UNAVAILABLE', $atSlotStart->slots[2]['status']);
        $this->assertSame('AVAILABLE', $atSlotStart->slots[4]['status']);
    }

    public function test_adjacent_schedule_intervals_are_valid(): void
    {
        $doctor = $this->createDoctorWithSchedule([
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
        ]);
        $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
        ]);

        $this->assertCount(4, $this->availability($doctor)->slots);
    }

    public function test_overlapping_recurring_schedules_fail_closed(): void
    {
        $doctor = $this->createDoctorWithSchedule(['start_time' => '09:00:00', 'end_time' => '12:00:00']);
        $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '11:00:00',
            'end_time' => '14:00:00',
            'slot_duration_minutes' => 30,
        ]);

        $this->assertUnavailable($this->availability($doctor), 'INVALID_SCHEDULE_CONFIGURATION');
    }

    public function test_cross_midnight_schedule_data_fails_closed(): void
    {
        $doctor = $this->createDoctorWithSchedule([
            'start_time' => '23:00:00',
            'end_time' => '02:00:00',
        ]);

        $this->assertUnavailable($this->availability($doctor), 'INVALID_SCHEDULE_CONFIGURATION');
    }

    public function test_asia_jakarta_is_used_even_when_application_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $doctor = $this->createDoctorWithSchedule();
        $requestDate = CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC');

        $result = $this->availability($doctor, $requestDate);

        $this->assertSame('2026-10-05', $result->date);
        $this->assertSame('Asia/Jakarta', $result->timezone);
        $this->assertSame('2026-10-05T09:00+07:00', $result->slots[0]['start_at']);
    }

    public function test_output_contains_only_safe_availability_data(): void
    {
        $doctor = $this->createDoctorWithSchedule();
        $this->createException($doctor, [
            'type' => 'MODIFIED',
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'reason' => 'Sensitive private note',
        ]);

        $payload = json_encode($this->availability($doctor)->toArray(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Sensitive private note', $payload);
        $this->assertStringNotContainsString('customer', strtolower($payload));
        $this->assertStringNotContainsString('booking_id', strtolower($payload));
        $this->assertStringContainsString('2026-10-05T10:00+07:00', $payload);
    }

    public function test_unknown_doctor_is_unavailable_without_exposing_internal_status(): void
    {
        $result = $this->service->getAvailability(999999, CarbonImmutable::parse('2026-10-05', 'UTC'));

        $this->assertUnavailable($result, 'DOCTOR_UNAVAILABLE');
    }

    private function createDoctorWithSchedule(array $schedule = []): DoctorProfile
    {
        $role = Role::firstOrCreate(['name' => 'doctor']);
        $user = User::factory()->for($role)->create();
        $doctor = $user->doctorProfile()->create();

        $doctor->schedules()->create(array_merge([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
        ], $schedule));

        return $doctor;
    }

    private function createException(DoctorProfile $doctor, array $attributes = []): void
    {
        $doctor->exceptions()->create(array_merge([
            'exception_date' => '2026-10-05',
            'type' => 'UNAVAILABLE',
            'status' => 'active',
            'start_time' => null,
            'end_time' => null,
        ], $attributes));
    }

    private function availability(
        DoctorProfile $doctor,
        ?CarbonImmutable $date = null,
    ): AvailabilityResult {
        return $this->service->getAvailability(
            $doctor->getKey(),
            $date ?? CarbonImmutable::parse('2026-10-05', 'UTC'),
        );
    }

    private function assertUnavailable(AvailabilityResult $result, string $reason): void
    {
        $this->assertSame('UNAVAILABLE', $result->status);
        $this->assertSame($reason, $result->reason);
        $this->assertSame([], $result->slots);
    }
}
