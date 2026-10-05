<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit metadata must never contain passwords, tokens, payment secrets, or other credentials.
 */
class AuditLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable. Actor identity should be assigned
     * through the trusted actor relationship, not from request data.
     *
     * @var list<string>
     */
    protected $fillable = [
        'action',
        'entity_type',
        'entity_id',
        'description',
        'metadata',
    ];

    /**
     * Get the user who performed this action, when one exists.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
