<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerSession extends Model
{
    /** @use HasFactory<ConsumerSessionFactory> */
    use HasFactory;

    protected $fillable = ['reference', 'user_id', 'consumer_identity_id', 'device_id', 'personal_access_token_id', 'auth_method', 'authenticated_at', 'last_active_at', 'expires_at', 'locked_at', 'revoked_at'];

    protected $hidden = ['personal_access_token_id'];

    protected function casts(): array
    {
        return ['authenticated_at' => 'immutable_datetime', 'last_active_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'locked_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ConsumerIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ConsumerIdentity::class, 'consumer_identity_id');
    }

    /** @return BelongsTo<ConsumerDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ConsumerDevice::class, 'device_id');
    }
}
