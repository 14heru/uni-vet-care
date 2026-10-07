<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_post_routes_reject_missing_csrf_tokens(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 4)->setTime(12, 0));

        $customerRole = Role::firstOrCreate(['name' => 'customer']);
        $customerUser = User::factory()->for($customerRole)->create([
            'email' => 'csrf-customer@example.test',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);
        $customer = $customerUser->customerProfile()->create();
        $pet = $customer->pets()->create(['name' => 'Milo', 'species' => 'Cat']);

        $doctorRole = Role::firstOrCreate(['name' => 'doctor']);
        $doctorUser = User::factory()->for($doctorRole)->create(['status' => 'active']);
        $doctor = $doctorUser->doctorProfile()->create(['status' => 'active']);
        $schedule = $doctor->schedules()->create([
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'slot_duration_minutes' => 30,
            'status' => 'active',
        ]);

        $booking = new Booking();
        $booking->forceFill([
            'booking_code' => 'UVC-CSRF-TEST',
            'customer_id' => $customer->id,
            'pet_id' => $pet->id,
            'doctor_id' => $doctor->id,
            'doctor_schedule_id' => $schedule->id,
            'appointment_start' => '2026-10-05 09:00:00',
            'appointment_end' => '2026-10-05 09:30:00',
            'status' => Booking::STATUS_PENDING,
        ])->save();

        $application = $this->app;
        $originalEnvironment = $application->environment();
        $application->detectEnvironment(fn (): string => 'local');

        try {
            $this->assertFalse($application->runningUnitTests());

            $this->postJson('/login', [
                'email' => $customerUser->email,
                'password' => 'correct-password',
            ])->assertStatus(419);

            $this->assertGuest('web');
            $this->assertSame(
                0,
                AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGIN)->count(),
            );

            $this->actingAs($customerUser, 'web')
                ->postJson('/logout')
                ->assertStatus(419);

            $this->assertAuthenticatedAs($customerUser, 'web');
            $this->assertSame(
                0,
                AuditLog::query()->where('action', AuditLogService::ACTION_AUTH_LOGOUT)->count(),
            );

            $this->postJson('/customer/bookings', [
                'pet_id' => $pet->id,
                'doctor_id' => $doctor->id,
                'doctor_schedule_id' => $schedule->id,
                'appointment_start' => '2026-10-05T09:30+07:00',
                'appointment_end' => '2026-10-05T10:00+07:00',
            ])->assertStatus(419);

            $this->assertDatabaseCount('bookings', 1);
            $this->assertSame(
                0,
                AuditLog::query()->where('action', AuditLogService::ACTION_BOOKING_CREATED)->count(),
            );

            $this->postJson('/bookings/'.$booking->id.'/status', [
                'status' => Booking::STATUS_CANCELLED,
            ])->assertStatus(419);

            $this->assertSame(Booking::STATUS_PENDING, $booking->fresh()->status);
            $this->assertDatabaseCount('booking_status_histories', 0);
            $this->assertSame(
                0,
                AuditLog::query()->where('action', AuditLogService::ACTION_BOOKING_STATUS_CHANGED)->count(),
            );
        } finally {
            $application->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }
}
