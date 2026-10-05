<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DoctorProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'specialization',
        'license_number',
        'bio',
        'photo',
        'status',
    ];

    /**
     * Get the user that owns the doctor profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the recurring schedules for the doctor profile.
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(DoctorSchedule::class, 'doctor_id');
    }

    /**
     * Get the schedule exceptions for the doctor profile.
     */
    public function exceptions(): HasMany
    {
        return $this->hasMany(ScheduleException::class, 'doctor_id');
    }
}
