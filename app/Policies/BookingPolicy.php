<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\User;

class BookingPolicy
{
    public function view(User $user, Booking $booking): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return match ($this->roleName($user)) {
            'admin', 'owner' => true,
            'customer' => $this->ownsCustomerBooking($user, $booking),
            'doctor' => $user->doctorProfile()->whereKey($booking->doctor_id)->exists(),
            default => false,
        };
    }

    public function create(User $user, ?CustomerProfile $customer = null): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        $role = $this->roleName($user);

        if ($role === 'admin') {
            return true;
        }

        if ($role !== 'customer' || $customer === null || ! $customer->exists) {
            return false;
        }

        $profile = $user->customerProfile()->first();

        return $profile !== null && $profile->is($customer);
    }

    public function update(User $user, Booking $booking): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return match ($this->roleName($user)) {
            'admin' => true,
            'customer' => $this->ownsCustomerBooking($user, $booking),
            default => false,
        };
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->update($user, $booking);
    }

    public function transitionStatus(User $user, Booking $booking, string $targetStatus): bool
    {
        if (! in_array($targetStatus, Booking::STATUSES, true)) {
            return false;
        }

        if (! $this->isActive($user)) {
            return false;
        }

        return match ($this->roleName($user)) {
            'admin' => true,
            'customer' => $targetStatus === Booking::STATUS_CANCELLED
                && $this->ownsCustomerBooking($user, $booking),
            'doctor' => in_array($targetStatus, [
                Booking::STATUS_CONFIRMED,
                Booking::STATUS_COMPLETED,
                Booking::STATUS_NO_SHOW,
            ], true) && $user->doctorProfile()->whereKey($booking->doctor_id)->exists(),
            default => false,
        };
    }

    private function isActive(User $user): bool
    {
        return $user->newQuery()
            ->whereKey($user->getKey())
            ->where('status', 'active')
            ->exists();
    }

    private function roleName(User $user): ?string
    {
        return $user->role()->value('name');
    }

    private function ownsCustomerBooking(User $user, Booking $booking): bool
    {
        return $user->customerProfile()->whereKey($booking->customer_id)->exists();
    }
}
