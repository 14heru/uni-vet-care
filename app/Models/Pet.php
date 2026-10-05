<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pet extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'species',
        'breed',
        'sex',
        'birth_date',
        'weight',
        'color',
        'notes',
        'status',
    ];

    /**
     * Get the customer profile that owns the pet.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class, 'customer_id');
    }

    /**
     * Get the bookings for this pet.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
