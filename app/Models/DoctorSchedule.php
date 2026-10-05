<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DoctorSchedule extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'day_of_week',
        'start_time',
        'end_time',
        'slot_duration_minutes',
        'status',
    ];

    /**
     * Get the doctor profile that owns the schedule.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class, 'doctor_id');
    }

    /**
     * Get the bookings associated with this recurring schedule.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'doctor_schedule_id');
    }
}
