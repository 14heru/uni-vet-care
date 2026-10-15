<?php

namespace Tests\Feature;

use App\Exceptions\InvalidBookingStatusTransitionException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingStatusService;
use App\Services\AuditLogService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
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
            $slotKey = 'doctor:'.$fixture['doctor']->id.':'.fake()->unique()->bothify('##############');
            $booking->forceFill(['slot_key' => $slotKey])->save();

            $updated = app(BookingStatusService::class)->transition($booking, $fixture['actor'], $newStatus);

            $this->assertSame($newStatus, $updated->fresh()->status);
            $this->assertSame(
                $newStatus === Booking::STATUS_CONFIRMED ? $slotKey : null,
                $updated->fresh()->slot_key,
            );
            $this->assertSame(1, $booking->statusHistories()->count());
            $this->assertSame($newStatus, $booking->statusHistories()->firstOrFail()->status);
            $this->assertSame(1, AuditLog::query()->where('entity_id', $booking->id)->where('action', AuditLogService::ACTION_BOOKING_STATUS_CHANGED)->count());
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
                    app(BookingStatusService::class)->transition($booking, $fixture['actor'], $newStatus);
                    $this->fail("Expected {$currentStatus} -> {$newStatus} to be rejected.");
                } catch (InvalidBookingStatusTransitionException $exception) {
                    $this->assertSame(
                        'The requested booking status transition is not allowed.',
                        $exception->getMessage(),
                    );
                }

                $this->assertSame($currentStatus, $booking->fresh()->status);
                $this->assertSame(0, $booking->statusHistories()->count());
                $this->assertSame(0, AuditLog::query()->where('entity_id', $booking->id)->count());
            }
        }
    }

    public function test_successful_transition_writes_exactly_one_history_row_with_reason(): void
    {
        $fixture = $this->createFixture();
        $booking = $this->createBooking($fixture);

        app(BookingStatusService::class)->transition(
            $booking,
            $fixture['actor'],
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
        $audit = AuditLog::query()->where('entity_id', $booking->id)->firstOrFail();
        $this->assertSame(1, AuditLog::query()->where('entity_id', $booking->id)->where('action', AuditLogService::ACTION_BOOKING_STATUS_CHANGED)->count());
        $this->assertSame(AuditLogService::ENTITY_BOOKING, $audit->entity_type);
        $this->assertSame($booking->id, $audit->entity_id);
        $this->assertSame('PENDING', $audit->metadata['old_status']);
        $this->assertSame(Booking::STATUS_CONFIRMED, $audit->metadata['new_status']);
        $this->assertSame('Confirmed by clinic staff', $audit->metadata['reason']);
        $this->assertSame($booking->booking_code, $audit->metadata['booking_code']);
        $this->assertSame($booking->appointment_start, $audit->metadata['appointment_start']);
        $this->assertSame($booking->appointment_end, $audit->metadata['appointment_end']);
        $this->assertSame($booking->doctor_id, $audit->metadata['doctor_id']);
        $this->assertSame($booking->customer_id, $audit->metadata['customer_id']);
        $this->assertSame($booking->pet_id, $audit->metadata['pet_id']);
        $this->assertSame($booking->doctor_schedule_id, $audit->metadata['doctor_schedule_id']);
        $this->assertArrayNotHasKey('slot_key', $audit->metadata);
        $this->assertEqualsCanonicalizing([
            'old_status',
            'new_status',
            'reason',
            'booking_code',
            'appointment_start',
            'appointment_end',
            'doctor_id',
            'customer_id',
            'pet_id',
            'doctor_schedule_id',
        ], array_keys($audit->metadata));
        $this->assertSame($fixture['actor']->id, $audit->actor_user_id);
    }

    public function test_transition_supports_a_null_reason(): void
    {
        $fixture = $this->createFixture();
        $booking = $this->createBooking($fixture);
        app(BookingStatusService::class)->transition($booking, $fixture['actor'], Booking::STATUS_CANCELLED);

        $this->assertDatabaseHas('booking_status_histories', [
            'booking_id' => $booking->id,
            'status' => Booking::STATUS_CANCELLED,
            'reason' => null,
        ]);
        $audit = AuditLog::query()->where('entity_id', $booking->id)->firstOrFail();
        $this->assertSame(1, AuditLog::query()->where('entity_id', $booking->id)->count());
        $this->assertNull($audit->metadata['reason'] ?? null);
    }

    public function test_audit_actor_is_the_trusted_user_argument_not_a_booking_relationship(): void
    {
        $fixture = $this->createFixture();
        $booking = $this->createBooking($fixture);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $trustedActor = User::factory()->for($adminRole)->create();

        app(BookingStatusService::class)->transition($booking, $trustedActor, Booking::STATUS_CONFIRMED);

        $audit = AuditLog::query()->where('entity_id', $booking->id)->firstOrFail();
        $this->assertSame($trustedActor->id, $audit->actor_user_id);
        $this->assertNotSame($fixture['actor']->id, $audit->actor_user_id);
        $this->assertNotSame($fixture['doctor']->user_id, $audit->actor_user_id);
    }

    public function test_invalid_status_input_fails_safely_without_changing_booking_or_history(): void
    {
        $fixture = $this->createFixture();
        $booking = $this->createBooking($fixture);

        try {
            app(BookingStatusService::class)->transition($booking, $fixture['actor'], 'paid');
            $this->fail('Expected an unapproved status value to be rejected.');
        } catch (InvalidBookingStatusTransitionException $exception) {
            $this->assertSame(
                'The requested booking status transition is not allowed.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertSame(0, $booking->statusHistories()->count());
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_missing_booking_id_fails_safely_without_creating_history(): void
    {
        $fixture = $this->createFixture();
        $booking = new Booking();
        $booking->forceFill([
            'id' => PHP_INT_MAX,
            'status' => Booking::STATUS_PENDING,
        ]);
        $booking->exists = true;

        try {
            app(BookingStatusService::class)->transition($booking, $fixture['actor'], Booking::STATUS_CONFIRMED);
            $this->fail('Expected a missing persisted booking to be rejected.');
        } catch (InvalidBookingStatusTransitionException $exception) {
            $this->assertSame(
                'The requested booking status transition is not allowed.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('booking_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_status_and_history_and_bubbles_exception(): void
    {
        $fixture = $this->createFixture();
        $booking = $this->createBooking($fixture);
        $failure = new QueryException('mysql', 'insert into audit_logs', [], new \Exception('Simulated audit persistence failure'));

        AuditLog::saving(function () use ($failure): void {
            throw $failure;
        });

        try {
            app(BookingStatusService::class)->transition($booking, $fixture['actor'], Booking::STATUS_CONFIRMED);
            $this->fail('Expected the audit persistence failure to bubble up.');
        } catch (QueryException $exception) {
            $this->assertSame($failure, $exception);
        } finally {
            AuditLog::flushEventListeners();
            AuditLog::clearBootedModels();
        }

        $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
        $this->assertSame(0, $booking->statusHistories()->count());
        $this->assertDatabaseCount('audit_logs', 0);
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
                $processes[] = $this->startStatusWorker($fixture['bookingId'], $fixture['customerUserId'], $gate, $paths);
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
            $audits = $connection->table('audit_logs')
                ->where('entity_type', AuditLogService::ENTITY_BOOKING)
                ->where('entity_id', $fixture['bookingId'])
                ->where('action', AuditLogService::ACTION_BOOKING_STATUS_CHANGED)
                ->get();
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
        $this->assertCount(1, $audits);
        $this->assertSame($fixture['customerUserId'], $audits->first()->actor_user_id);
    }

    /** @return array{actor: User, customer: CustomerProfile, pet: Pet, doctor: DoctorProfile, schedule: DoctorSchedule} */
    private function createFixture(): array
    {
        $customerRole = Role::firstOrCreate(['name' => 'customer']);
        $actor = User::factory()->for($customerRole)->create();
        $customer = $actor->customerProfile()->create();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $doctorRole = Role::firstOrCreate(['name' => 'doctor']);
        $doctor = User::factory()->for($doctorRole)->create()->doctorProfile()->create();
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
        ]);

        return compact('actor', 'customer', 'pet', 'doctor', 'schedule');
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
    private function startStatusWorker(int $bookingId, int $actorUserId, string $gate, array $paths): array
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
            (string) $actorUserId,
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
        $connection->table('audit_logs')->where('entity_type', AuditLogService::ENTITY_BOOKING)->where('entity_id', $fixture['bookingId'])->delete();
        $connection->table('booking_status_histories')->where('booking_id', $fixture['bookingId'])->delete();
        $connection->table('bookings')->where('id', $fixture['bookingId'])->delete();
        $connection->table('doctor_schedules')->where('id', $fixture['scheduleId'])->delete();
        $connection->table('pets')->where('id', $fixture['petId'])->delete();
        $connection->table('doctor_profiles')->where('id', $fixture['doctorId'])->delete();
        $connection->table('customer_profiles')->where('id', $fixture['customerId'])->delete();
        $connection->table('users')->whereIn('id', [$fixture['customerUserId'], $fixture['doctorUserId']])->delete();
    }
}
