<?php

namespace Tests\Feature;

use App\Models\DoctorProfile;
use App\Models\Role;
use App\Models\ScheduleException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_profile_has_many_schedule_exceptions(): void
    {
        $doctor = $this->createDoctorProfile();
        $exception = $doctor->exceptions()->create($this->exceptionAttributes());

        $this->assertTrue($doctor->exceptions->first()->is($exception));
    }

    public function test_schedule_exception_belongs_to_doctor_profile(): void
    {
        $doctor = $this->createDoctorProfile();
        $exception = $doctor->exceptions()->create($this->exceptionAttributes());

        $this->assertTrue($exception->doctor->is($doctor));
    }

    public function test_doctor_id_rejects_a_nonexistent_doctor_profile(): void
    {
        $this->expectException(QueryException::class);

        DB::table('schedule_exceptions')->insert([
            'doctor_id' => PHP_INT_MAX,
            'exception_date' => '2026-10-12',
            'type' => 'UNAVAILABLE',
        ]);
    }

    public function test_deleting_a_doctor_profile_with_an_exception_is_restricted(): void
    {
        $doctor = $this->createDoctorProfile();
        $doctor->exceptions()->create($this->exceptionAttributes());

        $this->expectException(QueryException::class);

        $doctor->delete();
    }

    public function test_duplicate_doctor_and_date_is_rejected(): void
    {
        $doctor = $this->createDoctorProfile();
        $attributes = $this->exceptionAttributes();
        $doctor->exceptions()->create($attributes);

        $this->expectException(QueryException::class);

        DB::table('schedule_exceptions')->insert([
            'doctor_id' => $doctor->id,
            ...$attributes,
        ]);
    }

    public function test_same_doctor_can_have_exceptions_on_different_dates(): void
    {
        $doctor = $this->createDoctorProfile();
        $doctor->exceptions()->create($this->exceptionAttributes());

        $secondException = $doctor->exceptions()->create($this->exceptionAttributes([
            'exception_date' => '2026-10-13',
        ]));

        $this->assertDatabaseHas('schedule_exceptions', [
            'id' => $secondException->id,
            'exception_date' => '2026-10-13',
        ]);
    }

    public function test_different_doctors_can_have_exceptions_on_the_same_date(): void
    {
        $firstException = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes());
        $secondException = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes());

        $this->assertDatabaseHas('schedule_exceptions', [
            'id' => $firstException->id,
            'exception_date' => '2026-10-12',
        ]);
        $this->assertDatabaseHas('schedule_exceptions', [
            'id' => $secondException->id,
            'exception_date' => '2026-10-12',
        ]);
    }

    public function test_status_defaults_to_active(): void
    {
        $exception = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes());
        $exception->refresh();

        $this->assertSame('active', $exception->status);
    }

    public function test_reason_accepts_null(): void
    {
        $exception = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes(['reason' => null]));
        $exception->refresh();

        $this->assertNull($exception->reason);
    }

    public function test_start_and_end_times_accept_null(): void
    {
        $exception = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes([
                'start_time' => null,
                'end_time' => null,
            ]));
        $exception->refresh();

        $this->assertNull($exception->start_time);
        $this->assertNull($exception->end_time);
    }

    public function test_type_and_exception_date_persist(): void
    {
        $exception = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes([
                'type' => 'MODIFIED',
                'exception_date' => '2026-10-13',
                'start_time' => '10:00:00',
                'end_time' => '14:00:00',
            ]));
        $exception->refresh();

        $this->assertSame('MODIFIED', $exception->type);
        $this->assertSame('2026-10-13', $exception->exception_date);
    }

    public function test_unavailable_exception_can_persist_with_null_times(): void
    {
        $exception = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes([
                'start_time' => null,
                'end_time' => null,
            ]));
        $exception->refresh();

        $this->assertSame('UNAVAILABLE', $exception->type);
        $this->assertNull($exception->start_time);
        $this->assertNull($exception->end_time);
    }

    public function test_modified_exception_can_persist_with_times(): void
    {
        $exception = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes([
                'type' => 'MODIFIED',
                'start_time' => '10:00:00',
                'end_time' => '14:00:00',
            ]));
        $exception->refresh();

        $this->assertSame('10:00:00', $exception->start_time);
        $this->assertSame('14:00:00', $exception->end_time);
    }

    public function test_historical_exception_date_can_be_stored(): void
    {
        $exception = $this->createDoctorProfile()
            ->exceptions()
            ->create($this->exceptionAttributes([
                'exception_date' => '2020-01-01',
            ]));
        $exception->refresh();

        $this->assertSame('2020-01-01', $exception->exception_date);
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
     * Get valid default exception attributes, optionally overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function exceptionAttributes(array $overrides = []): array
    {
        return array_merge([
            'exception_date' => '2026-10-12',
            'type' => 'UNAVAILABLE',
            'start_time' => null,
            'end_time' => null,
        ], $overrides);
    }
}
