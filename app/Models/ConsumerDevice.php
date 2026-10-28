<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConsumerDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerDevice extends Model
{
    /** @use HasFactory<ConsumerDeviceFactory> */
    use HasFactory;

    protected $fillable = ['reference', 'user_id', 'consumer_identity_id', 'public_key', 'key_fingerprint', 'assurance', 'revoked_at'];

    protected $hidden = ['public_key', 'key_fingerprint'];

    protected function casts(): array
    {
        return ['revoked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<ConsumerIdentity, $this> */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(ConsumerIdentity::class, 'consumer_identity_id');
    }
}
