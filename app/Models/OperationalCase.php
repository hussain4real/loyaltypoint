<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OperationalCaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalCase extends Model
{
    /** @use HasFactory<OperationalCaseFactory> */
    use HasFactory;

    protected $fillable = ['reference', 'category', 'source_reference', 'identity_hash', 'partner_id', 'customer_id', 'owner_id', 'owner_team', 'state', 'due_at', 'resolution_code', 'evidence_reference', 'resolved_at', 'closed_at'];

    public static function identityFor(string $category, string $sourceReference): string
    {
        return hash('sha256', json_encode([$category, $sourceReference], JSON_THROW_ON_ERROR));
    }

    protected static function booted(): void
    {
        static::creating(function (self $case): void {
            $case->identity_hash = self::identityFor($case->category, $case->source_reference);
        });
    }

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CaseEvent::class);
    }

    public function isOverdue(): bool
    {
        return ! in_array($this->state, ['resolved', 'closed'], true) && $this->due_at->isPast();
    }
}
