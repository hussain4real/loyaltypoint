# Data Model: Loyalty Point System

**Feature**: 001-loyalty-system  
**Date**: 2025-12-19  
**Status**: Complete

## Entity Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              User (Customer)                             │
│  Existing model extended with loyalty functionality                      │
├─────────────────────────────────────────────────────────────────────────┤
│  id: bigint (PK)                                                        │
│  name: string                                                           │
│  email: string (unique)                                                 │
│  ...existing fields...                                                  │
├─────────────────────────────────────────────────────────────────────────┤
│  + pointTransactions(): HasMany<PointTransaction>                       │
│  + getPointBalanceAttribute(): int (computed)                           │
│  + getLoyaltyTierAttribute(): string (computed)                         │
└─────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ 1:N
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                           PointTransaction                               │
│  Records all point movements (earn, redeem, bonus, adjustment)          │
├─────────────────────────────────────────────────────────────────────────┤
│  id: bigint (PK)                                                        │
│  user_id: bigint (FK -> users.id, indexed)                             │
│  type: string (TransactionType enum)                                    │
│  points: integer (+ for credit, - for debit)                           │
│  balance_after: bigint unsigned                                         │
│  description: string (nullable, max 255)                                │
│  metadata: json (nullable)                                              │
│  expires_at: timestamp (nullable, indexed)                              │
│  created_at: timestamp (indexed with user_id)                           │
│  updated_at: timestamp                                                  │
├─────────────────────────────────────────────────────────────────────────┤
│  + user(): BelongsTo<User>                                              │
│  + scopeForUser($query, $userId): Builder                               │
│  + scopeCredits($query): Builder                                        │
│  + scopeDebits($query): Builder                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Entities

### 1. User (Extended)

The existing `User` model is extended with loyalty point functionality.

**File**: `app/Models/User.php`

#### New Relationships

| Relationship | Type | Related Model | Description |
|--------------|------|---------------|-------------|
| `pointTransactions` | HasMany | PointTransaction | All point transactions for this user |

#### New Computed Attributes

| Attribute | Type | Description |
|-----------|------|-------------|
| `point_balance` | int | Sum of all transaction points (accessor) |
| `loyalty_tier` | string | Tier based on total earned points (accessor) |

#### Tier Rules (Computed)

| Tier | Points Threshold |
|------|------------------|
| Bronze | 0 - 999 |
| Silver | 1,000 - 4,999 |
| Gold | 5,000 - 9,999 |
| Platinum | 10,000+ |

---

### 2. PointTransaction

Records all point movements for audit and balance calculation.

**File**: `app/Models/PointTransaction.php`

#### Schema

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, auto-increment | Unique identifier |
| `user_id` | bigint | FK -> users.id, NOT NULL, CASCADE DELETE | Owner of the transaction |
| `type` | string | NOT NULL | Transaction type (earn, redeem, bonus, adjustment) |
| `points` | integer | NOT NULL | Point change (+ credit, - debit) |
| `balance_after` | bigint unsigned | NOT NULL | User's balance after this transaction |
| `description` | string(255) | NULL | Human-readable description |
| `metadata` | json | NULL | Additional context (order_id, reward_id, etc.) |
| `expires_at` | timestamp | NULL | Optional expiration for earned points |
| `created_at` | timestamp | NOT NULL | Transaction timestamp |
| `updated_at` | timestamp | NOT NULL | Last update timestamp |

#### Indexes

| Index Name | Columns | Type | Purpose |
|------------|---------|------|---------|
| `point_transactions_user_id_created_at_index` | user_id, created_at | Composite | Fast lookup of user transactions by date |
| `point_transactions_expires_at_index` | expires_at | Single | Expired points cleanup queries |

#### Relationships

| Relationship | Type | Related Model | Description |
|--------------|------|---------------|-------------|
| `user` | BelongsTo | User | The customer who owns this transaction |

#### Scopes

| Scope | Description |
|-------|-------------|
| `forUser($userId)` | Filter transactions by user ID |
| `credits()` | Only positive point transactions |
| `debits()` | Only negative point transactions |
| `notExpired()` | Exclude expired transactions |
| `expiringSoon($days)` | Points expiring within N days |

---

### 3. TransactionType (Enum)

Defines the types of point transactions.

**File**: `app/Enums/TransactionType.php`

| Value | Description | Points Sign |
|-------|-------------|-------------|
| `Earn` | Points earned from purchase | Positive |
| `Redeem` | Points spent on reward | Negative |
| `Bonus` | Manually awarded bonus points | Positive |
| `Adjustment` | Administrative adjustment | Either |

---

## Migration

**File**: `database/migrations/2025_12_19_XXXXXX_create_point_transactions_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('points');
            $table->unsignedBigInteger('balance_after');
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
```

---

## Factory

**File**: `database/factories/PointTransactionFactory.php`

```php
<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PointTransaction>
 */
class PointTransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => $this->faker->randomElement(TransactionType::cases())->value,
            'points' => $this->faker->numberBetween(10, 500),
            'balance_after' => $this->faker->numberBetween(0, 10000),
            'description' => $this->faker->sentence(),
            'metadata' => null,
            'expires_at' => null,
        ];
    }

    public function earn(int $points = 100): static
    {
        return $this->state(fn () => [
            'type' => TransactionType::Earn->value,
            'points' => $points,
        ]);
    }

    public function redeem(int $points = 100): static
    {
        return $this->state(fn () => [
            'type' => TransactionType::Redeem->value,
            'points' => -abs($points),
        ]);
    }

    public function bonus(int $points = 100): static
    {
        return $this->state(fn () => [
            'type' => TransactionType::Bonus->value,
            'points' => $points,
        ]);
    }

    public function withBalance(int $balance): static
    {
        return $this->state(fn () => [
            'balance_after' => $balance,
        ]);
    }
}
```

---

## Validation Rules

### AwardPointsRequest

| Field | Rules | Messages |
|-------|-------|----------|
| `points` | required, integer, min:1, max:1000000 | Points must be a positive integer (max 1M) |
| `description` | required, string, max:255 | Description is required |
| `metadata` | sometimes, array | Optional additional data |

### DeductPointsRequest

| Field | Rules | Messages |
|-------|-------|----------|
| `points` | required, integer, min:1, max:1000000 | Points must be a positive integer |
| `description` | required, string, max:255 | Description is required |
| `metadata` | sometimes, array | Optional additional data |

*Additional validation*: Deduction cannot exceed current balance (checked in service layer).

---

## State Transitions

Point transactions are immutable records. Balance changes occur through creating new transactions.

```
[No Points] ──earn──> [Has Points] ──earn──> [More Points]
                           │
                           │ redeem (if sufficient)
                           ▼
                      [Less Points] or [No Points]
```

### Business Rules

1. **Earning**: Always positive points, creates new transaction
2. **Redemption**: Cannot exceed current balance, creates negative transaction
3. **Bonus**: Admin/API awarded, always positive
4. **Adjustment**: Can be positive or negative, requires reason

---

## Computed Balance Calculation

```php
// User model accessor
public function getPointBalanceAttribute(): int
{
    return $this->pointTransactions()
        ->where(function ($query) {
            $query->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
        })
        ->sum('points');
}
```

**Performance Note**: For high-volume users, consider caching the balance with cache invalidation on transaction creation.
