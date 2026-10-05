<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DoctorScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_profile_has_many_schedules(): void
    {
        $doctor = $this->createDoctorProfile();
        $schedule = $doctor->schedules()->create($this->scheduleAttributes());

        $this->assertTrue($doctor->schedules->first()->is($schedule));
    }

    public function test_doctor_schedule_belongs_to_doctor_profile(): void
    {
        $doctor = $this->createDoctorProfile();
        $schedule = $doctor->schedules()->create($this->scheduleAttributes());

        $this->assertTrue($schedule->doctor->is($doctor));
    }

    public function test_doctor_id_rejects_a_nonexistent_doctor_profile(): void
    {
        $this->expectException(QueryException::class);

        DB::table('doctor_schedules')->insert([
            'doctor_id' => PHP_INT_MAX,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    public function test_deleting_a_doctor_profile_with_schedules_is_restricted(): void
    {
        $doctor = $this->createDoctorProfile();
        $doctor->schedules()->create($this->scheduleAttributes());

        $this->expectException(QueryException::class);

        $doctor->delete();
    }

    public function test_exact_duplicate_recurring_schedule_is_rejected(): void
    {
        $doctor = $this->createDoctorProfile();
        $attributes = $this->scheduleAttributes();
        $doctor->schedules()->create($attributes);

        $this->expectException(QueryException::class);

        DB::table('doctor_schedules')->insert([
            'doctor_id' => $doctor->id,
            ...$attributes,
        ]);
    }

    public function test_a_different_interval_for_the_same_day_is_accepted(): void
    {
        $doctor = $this->createDoctorProfile();
        $doctor->schedules()->create($this->scheduleAttributes());

        $secondSchedule = $doctor->schedules()->create($this->scheduleAttributes([
            'start_time' => '13:00:00',
            'end_time' => '15:00:00',
        ]));

        $this->assertDatabaseHas('doctor_schedules', [
            'id' => $secondSchedule->id,
            'start_time' => '13:00:00',
            'end_time' => '15:00:00',
        ]);
    }

    public function test_adjacent_intervals_can_coexist(): void
    {
        $doctor = $this->createDoctorProfile();
        $doctor->schedules()->create($this->scheduleAttributes([
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]));

        $adjacentSchedule = $doctor->schedules()->create($this->scheduleAttributes([
            'start_time' => '12:00:00',
            'end_time' => '15:00:00',
        ]));

        $this->assertDatabaseHas('doctor_schedules', ['id' => $adjacentSchedule->id]);
    }

    public function test_day_of_week_is_required(): void
    {
        $doctor = $this->createDoctorProfile();

        $this->expectException(QueryException::class);

        DB::table('doctor_schedules')->insert([
            'doctor_id' => $doctor->id,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    public function test_start_time_is_required(): void
    {
        $doctor = $this->createDoctorProfile();

        $this->expectException(QueryException::class);

        DB::table('doctor_schedules')->insert([
            'doctor_id' => $doctor->id,
            'day_of_week' => 1,
            'end_time' => '17:00:00',
        ]);
    }

    public function test_end_time_is_required(): void
    {
        $doctor = $this->createDoctorProfile();

        $this->expectException(QueryException::class);

        DB::table('doctor_schedules')->insert([
            'doctor_id' => $doctor->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
        ]);
    }

    public function test_slot_duration_defaults_to_thirty_and_status_defaults_to_active(): void
    {
        $schedule = $this->createDoctorProfile()->schedules()->create($this->scheduleAttributes());
        $schedule->refresh();

        $this->assertSame(30, $schedule->slot_duration_minutes);
        $this->assertSame('active', $schedule->status);
    }

    public function test_day_of_week_stores_iso_weekdays_one_through_seven(): void
    {
        $doctor = $this->createDoctorProfile();

        foreach (range(1, 7) as $dayOfWeek) {
            $doctor->schedules()->create($this->scheduleAttributes([
                'day_of_week' => $dayOfWeek,
            ]));
        }

        $storedDays = $doctor->schedules()
            ->orderBy('day_of_week')
            ->pluck('day_of_week')
            ->map(fn ($day) => (int) $day)
            ->all();

        $this->assertSame(range(1, 7), $storedDays);
    }

    public function test_schedule_time_values_persist(): void
    {
        $schedule = $this->createDoctorProfile()->schedules()->create($this->scheduleAttributes([
            'start_time' => '09:15:00',
            'end_time' => '17:45:00',
        ]));
        $schedule->refresh();

        $this->assertSame('09:15:00', $schedule->start_time);
        $this->assertSame('17:45:00', $schedule->end_time);
    }

    /**
     * Create an active doctor profile and its associated user.
     */
    private function createDoctorProfile(): DoctorProfile
    {
        $doctorRole = Role::firstOrCreate(['name' => 'doctor']);
        $user = User::factory()->for($doctorRole)->create();

        return $user->doctorProfile()->create();
    }

    /**
     * Get valid default schedule attributes, optionally overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scheduleAttributes(array $overrides = []): array
    {
        return array_merge([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ], $overrides);
    }
}
