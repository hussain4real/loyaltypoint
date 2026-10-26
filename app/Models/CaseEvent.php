<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CaseEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CaseEvent extends Model
{
    /** @use HasFactory<CaseEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['operational_case_id', 'actor_id', 'from_state', 'to_state', 'reason', 'evidence_reference', 'owner_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Case history is append-only.'));
        static::deleting(fn () => throw new LogicException('Case history is append-only.'));
    }
}
