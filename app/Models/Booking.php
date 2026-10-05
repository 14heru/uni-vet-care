<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    use HasFactory;

    /**
     * Appointment creation must be handled by a future service that checks
     * customer/pet ownership, doctor/schedule eligibility, exceptions,
     * availability, and Asia/Jakarta appointment times. It must set slot_key
     * transactionally for concurrency protection and clear it on cancellation.
     * DATETIME values have no timezone; the service must interpret them in the
     * business timezone rather than relying on the application timezone.
     */

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_NO_SHOW = 'NO_SHOW';

    /**
     * The booking statuses supported by the MVP.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONFIRMED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * Booking code, status, foreign keys, and slot_key are server-controlled.
     * The booking workflow must assign them explicitly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'appointment_start',
        'appointment_end',
    ];

    /**
     * Get the customer profile that owns the booking.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id');
    }

    /**
     * Get the pet associated with the booking.
     */
    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class, 'pet_id');
    }

    /**
     * Get the doctor profile associated with the booking.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_id');
    }

    /**
     * Get the recurring schedule associated with the booking.
     */
    public function doctorSchedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_schedule_id');
    }

    /**
     * Get this booking's status history in chronological order.
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }
}
