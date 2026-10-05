<?php

namespace App\Services;

use App\Exceptions\BookingUnavailableException;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use App\Models\Pet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingService
{
    private const BUSINESS_TIMEZONE = 'Asia/Jakarta';

    public function __construct(private readonly DoctorAvailabilityService $availability) {}

    /**
     * Create a booking from the authenticated user and an exact generated slot.
     * Appointment strings must retain the +07:00 offset returned by availability.
     */
    public function create(
        User $authenticatedUser,
        int $petId,
        int $doctorId,
        int $doctorScheduleId,
        string $appointmentStart,
        string $appointmentEnd,
    ): Booking {
        $start = $this->parseBusinessDateTime($appointmentStart);
        $end = $this->parseBusinessDateTime($appointmentEnd);

        if ($start === null || $end === null || !$start->isSameDay($end) || !$end->greaterThan($start)) {
            throw BookingUnavailableException::unavailable();
        }

        try {
            return DB::transaction(function () use (
                $authenticatedUser,
                $petId,
                $doctorId,
                $doctorScheduleId,
                $start,
                $end,
            ): Booking {
                // Serialize bookings per doctor before availability/occupancy reads.
                $doctor = DoctorProfile::query()
                    ->whereKey($doctorId)
                    ->lockForUpdate()
                    ->first();

                $user = User::query()
                    ->with(['customerProfile', 'role'])
                    ->find($authenticatedUser->getKey());
                $role = $user?->role?->name;
                $customer = $role === 'customer' ? $user?->customerProfile : null;
                $pet = match ($role) {
                    'customer' => $customer instanceof CustomerProfile
                        ? Pet::query()
                            ->whereKey($petId)
                            ->where('customer_id', $customer->getKey())
                            ->first()
                        : null,
                    'admin' => Pet::query()->with('customer')->find($petId),
                    default => null,
                };

                if ($role === 'admin') {
                    $customer = $pet?->customer;
                }
                $schedule = $doctor === null
                    ? null
                    : DoctorSchedule::query()
                        ->whereKey($doctorScheduleId)
                        ->where('doctor_id', $doctor->getKey())
                        ->where('day_of_week', $start->isoWeekday())
                        ->where('status', 'active')
                        ->first();

                if (
                    $user === null
                    || $user->status !== 'active'
                    || !in_array($role, ['customer', 'admin'], true)
                    || !$customer instanceof CustomerProfile
                    || $pet === null
                    || $doctor === null
                    || $schedule === null
                ) {
                    throw BookingUnavailableException::unavailable();
                }

                $availability = $this->availability->getAvailability(
                    (int) $doctor->getKey(),
                    $start->startOfDay(),
                );

                $requestedStart = $start->format('Y-m-d\TH:iP');
                $requestedEnd = $end->format('Y-m-d\TH:iP');
                $isGeneratedAvailableSlot = collect($availability->slots)->contains(
                    fn (array $slot): bool => $slot['status'] === 'AVAILABLE'
                        && $slot['start_at'] === $requestedStart
                        && $slot['end_at'] === $requestedEnd
                        && $this->slotMatchesSchedule($slot, $schedule),
                );

                if (!$isGeneratedAvailableSlot) {
                    throw BookingUnavailableException::unavailable();
                }

                $startValue = $start->format('Y-m-d H:i:s');
                $endValue = $end->format('Y-m-d H:i:s');
                $isOccupied = Booking::query()
                    ->where('doctor_id', $doctor->getKey())
                    ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
                    ->where('appointment_start', '<', $endValue)
                    ->where('appointment_end', '>', $startValue)
                    ->exists();

                if ($isOccupied) {
                    throw BookingUnavailableException::unavailable();
                }

                $slotKey = sprintf(
                    'doctor:%d:%s',
                    $doctor->getKey(),
                    $start->format('YmdHis'),
                );

                // Historical/non-occupying statuses must not keep a slot key reserved.
                Booking::query()
                    ->where('slot_key', $slotKey)
                    ->whereNotIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
                    ->update(['slot_key' => null]);

                $booking = new Booking();
                $booking->forceFill([
                    'booking_code' => $this->newBookingCode(),
                    'customer_id' => $customer->getKey(),
                    'pet_id' => $pet->getKey(),
                    'doctor_id' => $doctor->getKey(),
                    'doctor_schedule_id' => $schedule->getKey(),
                    'appointment_start' => $startValue,
                    'appointment_end' => $endValue,
                    'status' => Booking::STATUS_PENDING,
                    'slot_key' => $slotKey,
                ])->save();

                return $booking;
            });
        } catch (QueryException $exception) {
            if ($this->isSlotKeyCollision($exception)) {
                throw BookingUnavailableException::unavailable();
            }

            throw $exception;
        }
    }

    private function parseBusinessDateTime(string $value): ?CarbonImmutable
    {
        $dateTime = CarbonImmutable::createFromFormat('!Y-m-d\TH:iP', $value);

        if ($dateTime === false || $dateTime->format('Y-m-d\TH:iP') !== $value) {
            return null;
        }

        if ($dateTime->format('P') !== '+07:00') {
            return null;
        }

        return $dateTime->setTimezone(self::BUSINESS_TIMEZONE);
    }

    private function newBookingCode(): string
    {
        do {
            $code = 'UVC-'.now(self::BUSINESS_TIMEZONE)->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (Booking::query()->where('booking_code', $code)->exists());

        return $code;
    }

    /**
     * Ensure the selected active schedule is the source interval for this slot.
     * Modified exceptions may move hours, but retain the schedule's slot duration.
     *
     * @param  array{start_at: string, end_at: string, status: string, source: string, reason: string|null}  $slot
     */
    private function slotMatchesSchedule(array $slot, DoctorSchedule $schedule): bool
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d\TH:iP', $slot['start_at']);
        $end = CarbonImmutable::createFromFormat('!Y-m-d\TH:iP', $slot['end_at']);
        $duration = (int) $schedule->slot_duration_minutes;

        if ($start === false || $end === false || $duration <= 0 || (int) $start->diffInMinutes($end) !== $duration) {
            return false;
        }

        if ($slot['source'] === 'MODIFIED_EXCEPTION') {
            return true;
        }

        $scheduleStart = CarbonImmutable::parse($start->toDateString().' '.$schedule->start_time, self::BUSINESS_TIMEZONE);
        $scheduleEnd = CarbonImmutable::parse($start->toDateString().' '.$schedule->end_time, self::BUSINESS_TIMEZONE);

        return $start->greaterThanOrEqualTo($scheduleStart)
            && $end->lessThanOrEqualTo($scheduleEnd)
            && $scheduleStart->diffInMinutes($start) % $duration === 0;
    }

    private function isSlotKeyCollision(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains($exception->getMessage(), 'bookings_slot_key_unique');
    }
}
