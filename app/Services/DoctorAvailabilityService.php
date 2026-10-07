<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\DoctorProfile;
use App\Models\DoctorSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class DoctorAvailabilityService
{
    private const BUSINESS_TIMEZONE = 'Asia/Jakarta';

    /**
     * Calculate candidate appointment slots for one doctor and local date.
     *
     * Generate slots from active schedules and exceptions, then mark slots
     * occupied by pending or confirmed bookings. Booking creation must still
     * revalidate availability transactionally to protect against races.
     */
    public function getAvailability(int $doctorId, CarbonImmutable $localDate): AvailabilityResult
    {
        $date = CarbonImmutable::parse($localDate->format('Y-m-d'), self::BUSINESS_TIMEZONE)
            ->startOfDay();
        $dateString = $date->toDateString();

        $doctor = DoctorProfile::query()
            ->with('user')
            ->find($doctorId);

        if ($doctor === null || $doctor->status !== 'active' || $doctor->user?->status !== 'active') {
            return $this->unavailable($dateString, 'DOCTOR_UNAVAILABLE');
        }

        $schedules = $doctor->schedules()
            ->where('status', 'active')
            ->where('day_of_week', $date->isoWeekday())
            ->orderBy('start_time')
            ->get();

        if ($schedules->isEmpty()) {
            return $this->unavailable($dateString, 'NO_ACTIVE_SCHEDULE');
        }

        $exception = $doctor->exceptions()
            ->where('exception_date', $dateString)
            ->where('status', 'active')
            ->first();

        if ($exception !== null) {
            if ($exception->type === 'UNAVAILABLE') {
                return $this->unavailable($dateString, 'DATE_UNAVAILABLE');
            }

            if ($exception->type !== 'MODIFIED') {
                return $this->unavailable($dateString, 'INVALID_SCHEDULE_CONFIGURATION');
            }
        }

        $intervals = $this->recurringIntervals($schedules, $date);

        if ($intervals === null || $this->hasOverlappingIntervals($intervals)) {
            return $this->unavailable($dateString, 'INVALID_SCHEDULE_CONFIGURATION');
        }

        if ($exception !== null) {
            $durations = $schedules
                ->pluck('slot_duration_minutes')
                ->map(fn ($duration): int => (int) $duration)
                ->unique()
                ->values();

            if ($durations->count() !== 1 || $durations->first() <= 0) {
                return $this->unavailable($dateString, 'INVALID_SCHEDULE_CONFIGURATION');
            }

            $start = $this->clockTime($exception->start_time, $date);
            $end = $this->clockTime($exception->end_time, $date);

            if ($start === null || $end === null || $start->greaterThanOrEqualTo($end)) {
                return $this->unavailable($dateString, 'INVALID_SCHEDULE_CONFIGURATION');
            }

            $intervals = [[
                'start' => $start,
                'end' => $end,
                'duration' => $durations->first(),
                'source' => 'MODIFIED_EXCEPTION',
                'doctor_schedule_id' => (int) $schedules->first()->getKey(),
            ]];
        }

        return $this->buildResult($doctorId, $date, $intervals);
    }

    /**
     * @param  Collection<int, DoctorSchedule>  $schedules
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, duration: int, source: string, doctor_schedule_id: int}>|null
     */
    private function recurringIntervals(Collection $schedules, CarbonImmutable $date): ?array
    {
        $intervals = [];

        foreach ($schedules as $schedule) {
            $start = $this->clockTime($schedule->start_time, $date);
            $end = $this->clockTime($schedule->end_time, $date);
            $duration = (int) $schedule->slot_duration_minutes;

            if ($start === null || $end === null || $start->greaterThanOrEqualTo($end) || $duration <= 0) {
                return null;
            }

            $intervals[] = [
                'start' => $start,
                'end' => $end,
                'duration' => $duration,
                'source' => 'RECURRING_SCHEDULE',
                'doctor_schedule_id' => (int) $schedule->getKey(),
            ];
        }

        return $intervals;
    }

    /**
     * Parse a MySQL TIME value as a same-day business-local clock time.
     */
    private function clockTime(?string $time, CarbonImmutable $date): ?CarbonImmutable
    {
        if ($time === null || preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $time, $parts) !== 1) {
            return null;
        }

        $hour = (int) $parts[1];
        $minute = (int) $parts[2];
        $second = (int) $parts[3];

        if ($hour > 23 || $minute > 59 || $second !== 0) {
            return null;
        }

        return $date->setTime($hour, $minute, $second);
    }

    /**
     * Detect partial, nested, or duplicate overlaps without repairing them.
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, duration: int, source: string}>  $intervals
     */
    private function hasOverlappingIntervals(array $intervals): bool
    {
        for ($first = 0; $first < count($intervals); $first++) {
            for ($second = $first + 1; $second < count($intervals); $second++) {
                if (
                    $intervals[$first]['start']->lessThan($intervals[$second]['end'])
                    && $intervals[$first]['end']->greaterThan($intervals[$second]['start'])
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, duration: int, source: string, doctor_schedule_id: int}>  $intervals
     */
    private function buildResult(int $doctorId, CarbonImmutable $date, array $intervals): AvailabilityResult
    {
        $now = CarbonImmutable::now(self::BUSINESS_TIMEZONE);
        $isPastDate = $date->lessThan($now->startOfDay());
        $slots = [];
        $bookings = Booking::query()
            ->where('doctor_id', $doctorId)
            ->whereIn('status', [Booking::STATUS_PENDING, Booking::STATUS_CONFIRMED])
            ->where('appointment_start', '<', $date->endOfDay()->format('Y-m-d H:i:s'))
            ->where('appointment_end', '>', $date->startOfDay()->format('Y-m-d H:i:s'))
            ->get(['appointment_start', 'appointment_end']);

        foreach ($intervals as $interval) {
            $start = $interval['start'];
            $duration = $interval['duration'];

            while ($start->addMinutes($duration)->lessThanOrEqualTo($interval['end'])) {
                $end = $start->addMinutes($duration);
                $isPastOrStarted = $isPastDate || (
                    $date->isSameDay($now) && $start->lessThanOrEqualTo($now)
                );
                $startValue = $start->format('Y-m-d H:i:s');
                $endValue = $end->format('Y-m-d H:i:s');
                $isBooked = $bookings->contains(
                    fn (Booking $booking): bool => $startValue < $booking->appointment_end
                        && $endValue > $booking->appointment_start,
                );

                $slots[] = [
                    'start_at' => $start->format('Y-m-d\TH:iP'),
                    'end_at' => $end->format('Y-m-d\TH:iP'),
                    'status' => $isPastOrStarted ? 'UNAVAILABLE' : ($isBooked ? 'BOOKED' : 'AVAILABLE'),
                    'source' => $interval['source'],
                    'reason' => $isPastOrStarted ? 'PAST_OR_STARTED' : ($isBooked ? 'BOOKED' : null),
                    'doctor_schedule_id' => $interval['doctor_schedule_id'],
                ];

                $start = $end;
            }
        }

        usort($slots, fn (array $left, array $right): int => strcmp($left['start_at'], $right['start_at']));

        $hasAvailableSlot = collect($slots)->contains(fn (array $slot): bool => $slot['status'] === 'AVAILABLE');

        return new AvailabilityResult(
            date: $date->toDateString(),
            timezone: self::BUSINESS_TIMEZONE,
            status: $hasAvailableSlot ? 'AVAILABLE' : 'UNAVAILABLE',
            reason: $hasAvailableSlot ? null : 'NO_AVAILABLE_SLOTS',
            slots: $slots,
        );
    }

    private function unavailable(string $date, string $reason): AvailabilityResult
    {
        return new AvailabilityResult(
            date: $date,
            timezone: self::BUSINESS_TIMEZONE,
            status: 'UNAVAILABLE',
            reason: $reason,
            slots: [],
        );
    }
}
