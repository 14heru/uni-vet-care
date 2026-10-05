<?php

namespace Tests\Feature;

use App\Exceptions\BookingUnavailableException;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BookingService::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'Asia/Jakarta'));
    }

    public function test_customer_can_create_a_booking_with_server_generated_values(): void
    {
        $fixture = $this->createFixture();

        $booking = $this->book($fixture);

        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame($fixture['customer']->id, $booking->customer_id);
        $this->assertSame($fixture['pet']->id, $booking->pet_id);
        $this->assertSame($fixture['doctor']->id, $booking->doctor_id);
        $this->assertSame($fixture['schedule']->id, $booking->doctor_schedule_id);
        $this->assertSame('2026-10-05 09:00:00', $booking->appointment_start);
        $this->assertSame('2026-10-05 09:30:00', $booking->appointment_end);
        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_new_booking_is_pending_and_receives_a_unique_server_booking_code(): void
    {
        $fixture = $this->createFixture();

        $first = $this->book($fixture);
        $this->assertSame(Booking::STATUS_PENDING, $first->status);
        $this->assertMatchesRegularExpression('/^UVC-20261004-[A-Z0-9]{10}$/', $first->booking_code);

        $anotherFixture = $this->createFixture();
        $second = $this->book($anotherFixture);

        $this->assertNotSame($first->booking_code, $second->booking_code);
    }

    public function test_customer_cannot_book_another_customers_pet(): void
    {
        $fixture = $this->createFixture();
        $otherCustomer = $this->createCustomer();
        $otherPet = $otherCustomer->pets()->create(['name' => 'Luna', 'species' => 'Cat']);

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture, petId: $otherPet->id);
    }

    public function test_schedule_must_belong_to_the_selected_doctor(): void
    {
        $fixture = $this->createFixture();
        $otherDoctor = $this->createDoctor();
        $otherSchedule = $this->createSchedule($otherDoctor);

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture, scheduleId: $otherSchedule->id);
    }

    public function test_appointment_must_equal_a_generated_slot(): void
    {
        $fixture = $this->createFixture();

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture, start: '2026-10-05T09:15+07:00', end: '2026-10-05T09:45+07:00');
    }

    public function test_started_slot_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:05:00', 'Asia/Jakarta'));

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture);
    }

    public function test_unavailable_exception_slot_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $fixture['doctor']->exceptions()->create([
            'exception_date' => '2026-10-05',
            'type' => 'UNAVAILABLE',
            'status' => 'active',
        ]);

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture);
    }

    public function test_inactive_doctor_profile_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $fixture['doctor']->update(['status' => 'inactive']);

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture);
    }

    public function test_inactive_doctor_user_is_rejected(): void
    {
        $fixture = $this->createFixture();
        DB::table('users')->where('id', $fixture['doctor']->user_id)->update(['status' => 'inactive']);

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture);
    }

    public function test_cross_midnight_interval_is_rejected(): void
    {
        $fixture = $this->createFixture();

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture, start: '2026-10-05T23:30+07:00', end: '2026-10-06T00:00+07:00');
    }

    public function test_adjacent_generated_slots_can_both_be_booked(): void
    {
        $fixture = $this->createFixture();

        $first = $this->book($fixture);
        $second = $this->book($fixture, start: '2026-10-05T09:30+07:00', end: '2026-10-05T10:00+07:00');

        $this->assertNotSame($first->slot_key, $second->slot_key);
        $this->assertSame(2, Booking::query()->count());
    }

    public function test_an_already_booked_slot_is_rejected(): void
    {
        $fixture = $this->createFixture();
        $this->book($fixture);

        $this->expectException(BookingUnavailableException::class);
        $this->book($fixture);
    }

    public function test_unique_slot_key_collision_returns_a_safe_unavailable_exception(): void
    {
        $fixture = $this->createFixture();
        DB::table('bookings')->insert([
            'booking_code' => 'UVC-STALE-KEY',
            'customer_id' => $fixture['customer']->id,
            'pet_id' => $fixture['pet']->id,
            'doctor_id' => $fixture['doctor']->id,
            'doctor_schedule_id' => $fixture['schedule']->id,
            // Deliberately model a stale/inconsistent key to exercise the DB backstop.
            'appointment_start' => '2026-10-05 08:00:00',
            'appointment_end' => '2026-10-05 08:30:00',
            'status' => Booking::STATUS_PENDING,
            'slot_key' => 'doctor:'.$fixture['doctor']->id.':20261005090000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->book($fixture);
            $this->fail('Expected the unique slot-key collision to be handled.');
        } catch (BookingUnavailableException $exception) {
            $this->assertSame('The selected appointment slot is no longer available.', $exception->getMessage());
        }
    }

    public function test_independent_concurrent_requests_cannot_create_the_same_slot(): void
    {
        $this->assertSame('uni_vet_care_testing', config('database.connections.mysql.database'));

        $connectionName = 'booking_concurrency';
        config(['database.connections.'.$connectionName => config('database.connections.mysql')]);
        DB::purge($connectionName);
        $connection = DB::connection($connectionName);
        $fixture = $this->createCommittedConcurrencyFixture($connection);

        $tempDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'uvc-booking-concurrency-'.Str::random(12);
        $this->assertTrue(mkdir($tempDirectory));
        $gate = $tempDirectory.DIRECTORY_SEPARATOR.'gate';
        $workerPaths = [
            'ready' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-one-ready',
            'started' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-one-started',
        ];
        $secondWorkerPaths = [
            'ready' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-two-ready',
            'started' => $tempDirectory.DIRECTORY_SEPARATOR.'worker-two-started',
        ];
        $processes = [];
        $outputs = [];
        $lockHeld = false;
        $persistedBookingCount = null;

        try {
            $connection->beginTransaction();
            $connection->table('doctor_profiles')->where('id', $fixture['doctor_id'])->lockForUpdate()->first();
            $lockHeld = true;

            foreach ([$workerPaths, $secondWorkerPaths] as $paths) {
                $processes[] = $this->startConcurrencyWorker($fixture, $gate, $paths);
            }

            $this->waitForWorkerFiles([$workerPaths['ready'], $secondWorkerPaths['ready']], $processes);
            touch($gate);
            $this->waitForWorkerFiles([$workerPaths['started'], $secondWorkerPaths['started']], $processes);

            // Keep the row lock while both independent workers enter BookingService.
            usleep(300_000);
            $connection->commit();
            $lockHeld = false;

            foreach ($processes as $index => $process) {
                $outputs[$index] = $this->finishConcurrencyWorker($process);
            }

            $persistedBookingCount = $connection->table('bookings')
                ->where('doctor_id', $fixture['doctor_id'])
                ->count();
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

            $this->deleteCommittedConcurrencyFixture($connection, $fixture);
            DB::purge($connectionName);
            foreach (glob($tempDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($tempDirectory);
        }

        $results = array_map(function (string $output): array {
            $decoded = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        }, $outputs);

        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['outcome'] === 'created'));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['outcome'] === 'unavailable'));
        $this->assertSame(1, $persistedBookingCount);
    }

    public function test_appointment_input_must_use_asia_jakarta_offset_even_if_app_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $fixture = $this->createFixture();

        $booking = $this->book($fixture);

        $this->assertSame('2026-10-05 09:00:00', $booking->getRawOriginal('appointment_start'));
        $this->assertSame('2026-10-05 09:30:00', $booking->getRawOriginal('appointment_end'));
        $this->assertStringStartsWith('doctor:'.$fixture['doctor']->id.':', $booking->slot_key);
    }

    /** @return array{customer: CustomerProfile, pet: Pet, doctor: DoctorProfile, schedule: DoctorSchedule, user: User} */
    private function createFixture(): array
    {
        $customer = $this->createCustomer();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);
        $doctor = $this->createDoctor();
        $schedule = $this->createSchedule($doctor);

        return ['customer' => $customer, 'pet' => $pet, 'doctor' => $doctor, 'schedule' => $schedule, 'user' => $customer->user];
    }

    private function createCustomer(): CustomerProfile
    {
        $role = Role::firstOrCreate(['name' => 'customer']);

        return User::factory()->for($role)->create()->customerProfile()->create();
    }

    private function createDoctor(): DoctorProfile
    {
        $role = Role::firstOrCreate(['name' => 'doctor']);

        return User::factory()->for($role)->create()->doctorProfile()->create();
    }

    private function createSchedule(DoctorProfile $doctor): DoctorSchedule
    {
        return $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
        ]);
    }

    /** @param array{customer: CustomerProfile, pet: Pet, doctor: DoctorProfile, schedule: DoctorSchedule, user: User} $fixture */
    private function book(
        array $fixture,
        ?int $petId = null,
        ?int $scheduleId = null,
        string $start = '2026-10-05T09:00+07:00',
        string $end = '2026-10-05T09:30+07:00',
    ): Booking {
        return $this->service->create(
            $fixture['user'],
            $petId ?? $fixture['pet']->id,
            $fixture['doctor']->id,
            $scheduleId ?? $fixture['schedule']->id,
            $start,
            $end,
        );
    }

    /** @return array<string, int> */
    private function createCommittedConcurrencyFixture(\Illuminate\Database\Connection $connection): array
    {
        $now = now();
        $roleIds = [];

        foreach (['customer', 'doctor'] as $roleName) {
            $roleIds[$roleName] = $connection->table('roles')->where('name', $roleName)->value('id');
            if ($roleIds[$roleName] === null) {
                $roleIds[$roleName] = $connection->table('roles')->insertGetId([
                    'name' => $roleName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $insertUser = function (string $role, string $label) use ($connection, $roleIds, $now): int {
            return (int) $connection->table('users')->insertGetId([
                'role_id' => $roleIds[$role],
                'name' => 'Concurrency '.$label,
                'email' => Str::uuid().'@example.test',
                'status' => 'active',
                'password' => Hash::make('test-password'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        $customerUserId = $insertUser('customer', 'customer');
        $doctorUserId = $insertUser('doctor', 'doctor');
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
            'name' => 'Concurrency Pet',
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

        return [
            'customer_user_id' => $customerUserId,
            'doctor_user_id' => $doctorUserId,
            'customer_id' => (int) $customerId,
            'doctor_id' => (int) $doctorId,
            'pet_id' => (int) $petId,
            'schedule_id' => (int) $scheduleId,
        ];
    }

    /** @param array<string, int> $fixture
     *  @param array{ready: string, started: string} $paths
     *  @return array{resource: resource, pipes: array<int, resource>}
     */
    private function startConcurrencyWorker(array $fixture, string $gate, array $paths): array
    {
        $worker = base_path('tests/Support/booking_concurrency_worker.php');
        $command = [
            PHP_BINARY,
            $worker,
            $gate,
            $paths['ready'],
            $paths['started'],
            (string) $fixture['customer_user_id'],
            (string) $fixture['pet_id'],
            (string) $fixture['doctor_id'],
            (string) $fixture['schedule_id'],
            '2026-10-05T09:00+07:00',
            '2026-10-05T09:30+07:00',
        ];
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'uni_vet_care_testing',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ]);
        unset($environment['DB_URL']);

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path(), $environment);

        $this->assertIsResource($process);

        return ['resource' => $process, 'pipes' => $pipes];
    }

    /** @param list<string> $paths
     *  @param list<array{resource: resource, pipes: array<int, resource>}> $processes
     */
    private function waitForWorkerFiles(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            if (collect($paths)->every(fn (string $path): bool => file_exists($path))) {
                return;
            }

            foreach ($processes as $process) {
                $status = proc_get_status($process['resource']);
                if (!$status['running']) {
                    $stderr = stream_get_contents($process['pipes'][2]);
                    $this->fail('Concurrency worker exited before synchronization: '.$stderr);
                }
            }

            usleep(10_000);
        }

        $this->fail('Timed out waiting for concurrent booking workers.');
    }

    /** @param array{resource: resource, pipes: array<int, resource>} $process */
    private function finishConcurrencyWorker(array $process): string
    {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        foreach ($process['pipes'] as $pipe) {
            fclose($pipe);
        }
        $exitCode = proc_close($process['resource']);

        $this->assertSame(0, $exitCode, 'Concurrency worker failed: '.$stderr);

        return $stdout;
    }

    /** @param array<string, int> $fixture */
    private function deleteCommittedConcurrencyFixture(\Illuminate\Database\Connection $connection, array $fixture): void
    {
        $connection->table('bookings')->where('doctor_id', $fixture['doctor_id'])->delete();
        $connection->table('doctor_schedules')->where('id', $fixture['schedule_id'])->delete();
        $connection->table('pets')->where('id', $fixture['pet_id'])->delete();
        $connection->table('doctor_profiles')->where('id', $fixture['doctor_id'])->delete();
        $connection->table('customer_profiles')->where('id', $fixture['customer_id'])->delete();
        $connection->table('users')->whereIn('id', [$fixture['customer_user_id'], $fixture['doctor_user_id']])->delete();
    }
}
