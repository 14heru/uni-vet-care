<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
