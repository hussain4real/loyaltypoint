<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StaffElevationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffElevation extends Model
{
    /** @use HasFactory<StaffElevationFactory> */
    use HasFactory;

    protected $fillable = ['staff_membership_id', 'granted_by', 'permission', 'partner_id', 'customer_id', 'reason', 'expires_at', 'revoked_at'];

    protected $hidden = ['session_binding'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
