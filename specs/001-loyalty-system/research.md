# Research: Loyalty Point System

**Feature**: 001-loyalty-system  
**Date**: 2025-12-19  
**Status**: Complete

## Research Tasks

This document consolidates findings from research conducted to resolve technical decisions and unknowns identified during planning.

---

## 1. Sanctum Token Abilities for Third-Party API Access

**Task**: Research Laravel Sanctum token abilities for scoped API permissions.

### Decision
Use Laravel Sanctum's built-in token abilities (scopes) to control third-party API access.

### Rationale
- Sanctum is already installed (v4.2.1) and the User model has `HasApiTokens` trait
- Token abilities provide granular permission control without OAuth complexity
- Native middleware support (`abilities:` and `ability:`) for route protection
- Simple testing with `Sanctum::actingAs()` in Pest tests

### Implementation Pattern
```php
// Creating token with abilities
$token = $user->createToken('third-party-app', ['points:read', 'points:award'])->plainTextToken;

// Middleware registration in bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
    ]);
})

// Route protection
Route::middleware(['auth:sanctum', 'ability:points:read'])->get('/customers/{id}/points', ...);
```

### Defined Scopes
| Scope | Permission |
|-------|-----------|
| `points:read` | Read customer point balance |
| `transactions:read` | Read customer transaction history |
| `points:award` | Award points to customer |
| `points:deduct` | Deduct points from customer |

### Alternatives Considered
- **Laravel Passport**: Rejected - OAuth2 complexity not needed for this use case
- **Custom middleware**: Rejected - Sanctum provides this out of the box

---

## 2. API Versioning Strategy

**Task**: Research best practices for API versioning in Laravel.

### Decision
Use URL-based versioning with `/api/v1/` prefix and namespace controllers in `Api\V1\`.

### Rationale
- URL versioning is explicit and easy for third-party developers to understand
- Laravel's route grouping supports prefix-based versioning natively
- Controller namespacing (`App\Http\Controllers\Api\V1\`) allows clean version separation
- Easy to introduce V2 later without breaking V1 consumers

### Implementation Pattern
```php
// routes/api.php
Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        // Customer endpoints
        Route::get('/points/balance', [PointController::class, 'balance']);
        Route::get('/points/transactions', [PointController::class, 'transactions']);
        
        // Third-party endpoints
        Route::middleware('ability:points:read')
            ->get('/customers/{customer}/points', [CustomerPointController::class, 'show']);
    });
});
```

### Alternatives Considered
- **Header-based versioning**: Rejected - less discoverable, harder for debugging
- **Query parameter versioning**: Rejected - not RESTful best practice
- **No versioning**: Rejected - breaks backward compatibility for third-party apps

---

## 3. Point Transaction Storage Strategy

**Task**: Research how to safely store and calculate point balances.

### Decision
Store points as integers with a `balance_after` snapshot on each transaction.

### Rationale
- Integer storage avoids floating-point precision issues
- `balance_after` provides audit trail and fast balance lookup
- Balance can be recalculated from transactions if needed (data integrity check)
- Database transactions with row locking prevent race conditions

### Implementation Pattern
```php
// PointTransaction model
Schema::create('point_transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('type');  // Enum: earn, redeem, bonus, adjustment
    $table->integer('points'); // Positive for credit, negative for debit
    $table->unsignedBigInteger('balance_after');
    $table->string('description')->nullable();
    $table->json('metadata')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
    
    $table->index(['user_id', 'created_at']);
    $table->index('expires_at');
});

// Atomic transaction with locking
DB::transaction(function () use ($user, $points, $type, $description) {
    $user->lockForUpdate();
    $currentBalance = $user->pointTransactions()->sum('points');
    $newBalance = $currentBalance + $points;
    
    PointTransaction::create([
        'user_id' => $user->id,
        'type' => $type->value,
        'points' => $points,
        'balance_after' => $newBalance,
        'description' => $description,
    ]);
});
```

### Alternatives Considered
- **Stored balance on User model**: Rejected - can drift from transactions, harder to audit
- **Decimal storage**: Rejected - introduces precision issues, points should be whole numbers
- **Event-sourced transactions**: Rejected - overkill for this scope

---

## 4. Eloquent API Resources for JSON Responses

**Task**: Research Eloquent API Resources for consistent JSON formatting.

### Decision
Use dedicated API Resource classes for all JSON responses.

### Rationale
- Constitution requires thin controllers (Principle V)
- Resources provide consistent JSON structure across endpoints
- Easy to version resources alongside controllers
- Built-in pagination support with `ResourceCollection`

### Implementation Pattern
```php
// app/Http/Resources/Api/V1/PointBalanceResource.php
class PointBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'customer_id' => $this->id,
            'points_balance' => $this->point_balance,
            'tier' => $this->loyalty_tier,
            'last_transaction_at' => $this->pointTransactions()->latest()->first()?->created_at,
        ];
    }
}

// app/Http/Resources/Api/V1/PointTransactionResource.php
class PointTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'points' => $this->points,
            'balance_after' => $this->balance_after,
            'description' => $this->description,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

### Alternatives Considered
- **Manual array construction**: Rejected - not DRY, inconsistent formatting
- **Fractal transformers**: Rejected - Laravel Resources are native and sufficient

---

## 5. Form Request Validation

**Task**: Research Form Request patterns for API validation.

### Decision
Create dedicated Form Request classes for all write operations.

### Rationale
- Constitution explicitly requires Form Requests (Principle V)
- Separates validation logic from controllers
- Automatic 422 response with JSON error format for API requests
- Authorization can be checked in the same class

### Implementation Pattern
```php
// app/Http/Requests/Api/V1/AwardPointsRequest.php
class AwardPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->tokenCan('points:award');
    }

    public function rules(): array
    {
        return [
            'points' => ['required', 'integer', 'min:1', 'max:1000000'],
            'description' => ['required', 'string', 'max:255'],
            'metadata' => ['sometimes', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'points.min' => 'Points must be a positive integer.',
            'points.max' => 'Cannot award more than 1,000,000 points in a single transaction.',
        ];
    }
}
```

### Alternatives Considered
- **Inline controller validation**: Rejected - violates Constitution Principle V
- **Validator service**: Rejected - Form Requests are the Laravel convention

---

## 6. Testing Strategy with Pest

**Task**: Research Pest v4 testing patterns for Sanctum-authenticated APIs.

### Decision
Use Pest Feature tests with `Sanctum::actingAs()` for API endpoint testing.

### Rationale
- Constitution requires Pest v4 with Feature tests (Principle II)
- `Sanctum::actingAs()` provides clean token ability simulation
- `RefreshDatabase` trait ensures test isolation
- Model factories enable realistic test data

### Implementation Pattern
```php
// tests/Feature/Api/V1/CustomerPointControllerTest.php
use App\Models\User;
use App\Models\PointTransaction;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('returns customer point balance for valid token with points:read scope', function () {
    $customer = User::factory()->create();
    PointTransaction::factory()->for($customer)->create(['points' => 500, 'balance_after' => 500]);
    
    Sanctum::actingAs(User::factory()->create(), ['points:read']);
    
    $response = $this->getJson("/api/v1/customers/{$customer->id}/points");
    
    $response->assertOk()
        ->assertJsonPath('data.customer_id', $customer->id)
        ->assertJsonPath('data.points_balance', 500);
});

it('returns 403 for token without points:read scope', function () {
    $customer = User::factory()->create();
    
    Sanctum::actingAs(User::factory()->create(), ['transactions:read']);
    
    $this->getJson("/api/v1/customers/{$customer->id}/points")
        ->assertForbidden();
});

it('returns 401 for unauthenticated request', function () {
    $customer = User::factory()->create();
    
    $this->getJson("/api/v1/customers/{$customer->id}/points")
        ->assertUnauthorized();
});
```

### Alternatives Considered
- **Unit tests only**: Rejected - Constitution prefers Feature tests for end-to-end coverage
- **Manual token creation**: Rejected - `Sanctum::actingAs()` is cleaner

---

## Summary

All technical decisions have been resolved. No items remain marked as "NEEDS CLARIFICATION."

| Topic | Decision |
|-------|----------|
| API Authentication | Sanctum token abilities with scoped permissions |
| API Versioning | URL-based (`/api/v1/`) with namespaced controllers |
| Point Storage | Integers with `balance_after` snapshot, DB transactions |
| JSON Responses | Eloquent API Resources |
| Validation | Form Request classes |
| Testing | Pest Feature tests with `Sanctum::actingAs()` |
