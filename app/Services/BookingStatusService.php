<?php

namespace App\Services;

use App\Exceptions\InvalidBookingStatusTransitionException;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BookingStatusService
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        Booking::STATUS_PENDING => [
            Booking::STATUS_CONFIRMED,
            Booking::STATUS_CANCELLED,
        ],
        Booking::STATUS_CONFIRMED => [
            Booking::STATUS_COMPLETED,
            Booking::STATUS_CANCELLED,
            Booking::STATUS_NO_SHOW,
        ],
        Booking::STATUS_COMPLETED => [],
        Booking::STATUS_CANCELLED => [],
        Booking::STATUS_NO_SHOW => [],
    ];

    /**
     * Record a valid lifecycle transition and its history atomically.
     * The trusted caller must supply a human-readable reason without credentials.
     */
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function transition(Booking $booking, User $actor, string $newStatus, ?string $reason = null): Booking
    {
        if (! $booking->exists || ! in_array($newStatus, Booking::STATUSES, true)) {
            throw InvalidBookingStatusTransitionException::rejected();
        }

        return DB::transaction(function () use ($booking, $actor, $newStatus, $reason): Booking {
            $persistedBooking = Booking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->first();

            if ($persistedBooking === null) {
                throw InvalidBookingStatusTransitionException::rejected();
            }

            $currentStatus = $persistedBooking->status;
            $allowedStatuses = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

            if (! in_array($newStatus, $allowedStatuses, true)) {
                throw InvalidBookingStatusTransitionException::rejected();
            }

            $persistedBooking->status = $newStatus;

            if (in_array($newStatus, [
                Booking::STATUS_CANCELLED,
                Booking::STATUS_COMPLETED,
                Booking::STATUS_NO_SHOW,
            ], true)) {
                $persistedBooking->slot_key = null;
            }

            $persistedBooking->save();

            $persistedBooking->statusHistories()->create([
                'status' => $newStatus,
                'reason' => $reason,
            ]);

            $this->auditLog->log(
                $actor,
                AuditLogService::ACTION_BOOKING_STATUS_CHANGED,
                AuditLogService::ENTITY_BOOKING,
                (int) $persistedBooking->getKey(),
                'Booking status changed',
                [
                    'old_status' => $currentStatus,
                    'new_status' => $newStatus,
                    'reason' => $reason,
                    'booking_code' => $persistedBooking->booking_code,
                    'appointment_start' => $persistedBooking->appointment_start,
                    'appointment_end' => $persistedBooking->appointment_end,
                    'doctor_id' => $persistedBooking->doctor_id,
                    'customer_id' => $persistedBooking->customer_id,
                    'pet_id' => $persistedBooking->pet_id,
                    'doctor_schedule_id' => $persistedBooking->doctor_schedule_id,
                ],
            );

            return $persistedBooking;
        });
    }
}
