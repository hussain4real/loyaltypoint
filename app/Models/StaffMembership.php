<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\StaffMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffMembership extends Model
{
    /** @use HasFactory<StaffMembershipFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'role', 'partner_ids', 'customer_ids', 'enabled'];

    protected $hidden = ['mfa_secret', 'enrollment_token_hash', 'last_totp_counter', 'session_binding'];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'partner_ids' => 'array',
            'customer_ids' => 'array',
            'enabled' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_confirmed_at' => 'immutable_datetime',
            'last_totp_counter' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
