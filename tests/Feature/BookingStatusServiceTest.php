<?php

namespace Tests\Feature;

use App\Exceptions\InvalidBookingStatusTransitionException;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingStatusService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_approved_transition_succeeds(): void
    {
        $fixture = $this->createFixture();
        $transitions = [
            [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED],
            [Booking::STATUS_PENDING, Booking::STATUS_CANCELLED],
            [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED],
            [Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED],
            [Booking::STATUS_CONFIRMED, Booking::STATUS_NO_SHOW],
        ];

        foreach ($transitions as [$currentStatus, $newStatus]) {
            $booking = $this->createBooking($fixture, $currentStatus);

            $updated = app(BookingStatusService::class)->transition($booking, $newStatus);

            $this->assertSame($newStatus, $updated->fresh()->status);
            $this->assertSame(1, $booking->statusHistories()->count());
            $this->assertSame($newStatus, $booking->statusHistories()->firstOrFail()->status);
        }
    }

    public function test_every_forbidden_and_same_status_transition_is_rejected_without_history(): void
    {
        $fixture = $this->createFixture();
        $allowed = [
            Booking::STATUS_PENDING => [Booking::STATUS_CONFIRMED, Booking::STATUS_CANCELLED],
            Booking::STATUS_CONFIRMED => [
                Booking::STATUS_COMPLETED,
                Booking::STATUS_CANCELLED,
                Booking::STATUS_NO_SHOW,
            ],
            Booking::STATUS_COMPLETED => [],
            Booking::STATUS_CANCELLED => [],
            Booking::STATUS_NO_SHOW => [],
        ];

        foreach (Booking::STATUSES as $currentStatus) {
            foreach (Booking::STATUSES as $newStatus) {
                if (in_array($newStatus, $allowed[$currentStatus], true)) {
                    continue;
                }

                $booking = $this->createBooking($fixture, $currentStatus);

                try {
                    app(BookingStatusService::class)->transition($booking, $newStatus);
                    $this->fail("Expected {$currentStatus} -> {$newStatus} to be rejected.");
                } catch (InvalidBookingStatusTransitionException $exception) {
                    $this->assertSame(
                        'The requested booking status transition is not allowed.',
                        $exception->getMessage(),
                    );
                }

                $this->assertSame($currentStatus, $booking->fresh()->status);
                $this->assertSame(0, $booking->statusHistories()->count());
            }
        }
    }

    public function test_successful_transition_writes_exactly_one_history_row_with_reason(): void
    {
        $booking = $this->createBooking($this->createFixture());

        app(BookingStatusService::class)->transition(
            $booking,
            Booking::STATUS_CONFIRMED,
            'Confirmed by clinic staff',
        );

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
        $this->assertDatabaseCount('booking_status_histories', 1);
        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => Booking::STATUS_CONFIRMED,
            'reason' => 'Confirmed by clinic staff',
        ]);
    }

    public function test_transition_supports_a_null_reason(): void
    {
        $booking = $this->createBooking($this->createFixture());

        app(BookingStatusService::class)->transition($booking, Booking::STATUS_CANCELLED);

        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => Booking::STATUS_CANCELLED,
            'reason' => null,
        ]);
    }

    public function test_invalid_status_input_fails_safely_without_changing_booking_or_history(): void
    {
        $booking = $this->createBooking($this->createFixture());

        try {
            app(BookingStatusService::class)->transition($booking, 'paid');
            $this->fail('Expected an unapproved status value to be rejected.');
        } catch (InvalidBookingStatusTransitionException $exception) {
            $this->assertSame(
                'The requested booking status transition is not allowed.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertSame(0, $booking->statusHistories()->count());
    }

    public function test_missing_booking_id_fails_safely_without_creating_history(): void
    {
        $booking = new Booking();
        $booking->forceFill([
            'id' => PHP_INT_MAX,
            'status' => Booking::STATUS_PENDING,
        ]);
        $booking->exists = true;

        try {
            app(BookingStatusService::class)->transition($booking, Booking::STATUS_CONFIRMED);
            $this->fail('Expected a missing persisted booking to be rejected.');
        } catch (InvalidBookingStatusTransitionException $exception) {
            $this->assertSame(
                'The requested booking status transition is not allowed.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_status_histories', 0);
    }

    public function test_concurrent_same_status_transitions_serialize_and_write_one_history(): void
    {
        $this->assertSame('uni_vet_care_testing', config('database.connections.mysql.database'));

        $connectionName = 'booking_status_concurrency';
        config(['database.connections.'.$connectionName => config('database.connections.mysql')]);
        DB::purge($connectionName);
        $connection = DB::connection($connectionName);
        $fixture = $this->createCommittedBooking($connection);
        $tempDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'uvc-booking-status-'.Str::random(12);
        $this->assertTrue(mkdir($tempDirectory));
        $gate = $tempDirectory.DIRECTORY_SEPARATOR.'gate';
        $workers = [
            [
                'ready' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-one-ready',
                'started' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-one-started',
            ],
            [
                'ready' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-two-ready',
                'started' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-two-started',
            ],
        ];
        $processes = [];
        $outputs = [];
        $lockHeld = false;

        try {
            $connection->beginTransaction();
            $connection->table('bookings')->where('id', $fixture['bookingId'])->lockForUpdate()->first();
            $lockHeld = true;

            foreach ($workers as $paths) {
                $processes[] = $this->startStatusWorker($fixture['bookingId'], $gate, $paths);
            }

            $this->waitForWorkerFiles(array_column($workers, 'ready'), $processes);
            touch($gate);
            $this->waitForWorkerFiles(array_column($workers, 'started'), $processes);
            usleep(300_000);
            $connection->commit();
            $lockHeld = false;

            foreach ($processes as $index => $process) {
                $outputs[$index] = $this->finishStatusWorker($process);
            }

            $history = $connection->table('booking_status_histories')
                ->where('booking_id', $fixture['bookingId'])
                ->get();
            $persistedStatus = $connection->table('bookings')
                ->where('id', $fixture['bookingId'])
                ->value('status');
        } finally {
            if ($lockHeld && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            foreach ($processes as $process) {
                if (isset($process['resource']) && is_resource($process['resource'])) {
                    proc_terminate($process['resource']);
                    foreach ($process['pipes'] as $pipe) {
                        if (is_resource($pipe)) {
                            fclose($pipe);
                        }
                    }
                    proc_close($process['resource']);
                }
            }

            $this->deleteCommittedBooking($connection, $fixture);
            DB::purge($connectionName);
            foreach (glob($tempDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($tempDirectory);
        }

        $results = array_map(
            fn (string $output): array => json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR),
            $outputs,
        );

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['outcome'] === 'transitioned'));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['outcome'] === 'rejected'));
        $this->assertSame(Booking::STATUS_CONFIRMED, $persistedStatus);
        $this->assertCount(1, $history);
        $this->assertSame(Booking::STATUS_CONFIRMED, $history->first()->status);
    }

    /** @return array{customer: CustomerProfile, pet: Pet, doctor: DoctorProfile, schedule: DoctorSchedule} */
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
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
        ]);

        return compact('customer', 'pet', 'doctor', 'schedule');
    }

    /** @param array{customer: CustomerProfile, pet: Pet, doctor: DoctorProfile, schedule: DoctorSchedule} $fixture */
    private function createBooking(array $fixture, string $status = Booking::STATUS_PENDING): Booking
    {
        $booking = new Booking();
        $booking->forceFill([
            'booking_code' => 'UVC-STATUS-'.fake()->unique()->bothify('########'),
            'customer_id' => $fixture['customer']->id,
            'pet_id' => $fixture['pet']->id,
            'doctor_id' => $fixture['doctor']->id,
            'doctor_schedule_id' => $fixture['schedule']->id,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => $status,
        ])->save();

        return $booking;
    }

    private function createCommittedBooking(Connection $connection): array
    {
        $now = now();
        $roleIds = [];

        foreach (['customer', 'doctor'] as $roleName) {
            $roleId = $connection->table('roles')->where('name', $roleName)->value('id');
            $roleIds[$roleName] = $roleId ?? $connection->table('roles')->insertGetId([
                'name' => $roleName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $customerUserId = $this->insertUser($connection, (int) $roleIds['customer'], $now);
        $doctorUserId = $this->insertUser($connection, (int) $roleIds['doctor'], $now);
        $customerId = $connection->table('customer_profiles')->insertGetId([
            'user_id' => $customerUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $doctorId = $connection->table('doctor_profiles')->insertGetId([
            'user_id' => $doctorUserId,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $petId = $connection->table('pets')->insertGetId([
            'customer_id' => $customerId,
            'name' => 'Status worker pet',
            'species' => 'Cat',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scheduleId = $connection->table('doctor_schedules')->insertGetId([
            'doctor_id' => $doctorId,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $bookingId = $connection->table('bookings')->insertGetId([
            'booking_code' => 'UVC-STATUS-WORKER-'.Str::upper(Str::random(10)),
            'customer_id' => $customerId,
            'pet_id' => $petId,
            'doctor_id' => $doctorId,
            'doctor_schedule_id' => $scheduleId,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => Booking::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return compact('customerUserId', 'doctorUserId', 'customerId', 'doctorId', 'petId', 'scheduleId', 'bookingId');
    }

    private function insertUser(Connection $connection, int $roleId, mixed $now): int
    {
        return (int) $connection->table('users')->insertGetId([
            'role_id' => $roleId,
            'name' => 'Status service fixture',
            'email' => Str::uuid().'@example.test',
            'status' => 'active',
            'password' => Hash::make('test-password'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array{ready: string, started: string} $paths */
    private function startStatusWorker(int $bookingId, string $gate, array $paths): array
    {
        $worker = base_path('tests/Support/booking_status_concurrency_worker.php');
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'uni_vet_care_testing',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
        ]);
        unset($environment['DB_URL']);

        $process = proc_open([
            PHP_BINARY,
            $worker,
            $gate,
            $paths['ready'],
            $paths['started'],
            (string) $bookingId,
            Booking::STATUS_CONFIRMED,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path(), $environment);

        $this->assertIsResource($process);

        return ['resource' => $process, 'pipes' => $pipes];
    }

    /** @param list<string> $paths */
    private function waitForWorkerFiles(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            if (collect($paths)->every(fn (string $path): bool => file_exists($path))) {
                return;
            }

            foreach ($processes as $process) {
                $status = proc_get_status($process['resource']);
                if (! $status['running']) {
                    $stderr = stream_get_contents($process['pipes'][2]);
                    $this->fail('Status concurrency worker exited early: '.$stderr);
                }
            }

            usleep(10_000);
        }

        $this->fail('Timed out waiting for status concurrency workers.');
    }

    private function finishStatusWorker(array $process): string
    {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        foreach ($process['pipes'] as $pipe) {
            fclose($pipe);
        }
        $exitCode = proc_close($process['resource']);

        $this->assertSame(0, $exitCode, 'Status concurrency worker failed: '.$stderr);

        return $stdout;
    }

    private function deleteCommittedBooking(Connection $connection, array $fixture): void
    {
        $connection->table('booking_status_histories')->where('booking_id', $fixture['bookingId'])->delete();
        $connection->table('bookings')->where('id', $fixture['bookingId'])->delete();
        $connection->table('doctor_schedules')->where('id', $fixture['scheduleId'])->delete();
        $connection->table('pets')->where('id', $fixture['petId'])->delete();
        $connection->table('doctor_profiles')->where('id', $fixture['doctorId'])->delete();
        $connection->table('customer_profiles')->where('id', $fixture['customerId'])->delete();
        $connection->table('users')->whereIn('id', [$fixture['customerUserId'], $fixture['doctorUserId']])->delete();
    }
}
