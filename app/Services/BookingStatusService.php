<?php

namespace App\Services;

use App\Exceptions\InvalidBookingStatusTransitionException;
use App\Models\Booking;
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
    public function transition(Booking $booking, string $newStatus, ?string $reason = null): Booking
    {
        if (! $booking->exists || ! in_array($newStatus, Booking::STATUSES, true)) {
            throw InvalidBookingStatusTransitionException::rejected();
        }

        return DB::transaction(function () use ($booking, $newStatus, $reason): Booking {
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
            $persistedBooking->save();

            $persistedBooking->statusHistories()->create([
                'status' => $newStatus,
                'reason' => $reason,
            ]);

            return $persistedBooking;
        });
    }
}
