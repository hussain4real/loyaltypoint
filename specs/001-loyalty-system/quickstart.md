# Quickstart: Loyalty Point System

**Feature**: 001-loyalty-system  
**Date**: 2025-12-19

## Prerequisites

- PHP 8.3+
- Composer
- Node.js (for asset building)
- SQLite (development) or MySQL/PostgreSQL (production)

## Setup

### 1. Install Dependencies

```bash
composer install
npm install
```

### 2. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

### 3. Database Setup

```bash
# Run migrations
php artisan migrate

# Seed sample data (optional)
php artisan db:seed
```

### 4. Register Sanctum Middleware (if not already done)

In `bootstrap/app.php`, ensure the ability middleware aliases are registered:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
    ]);
})
```

---

## API Usage

### Authentication

All API endpoints require a Sanctum Bearer token in the Authorization header:

```
Authorization: Bearer <your-token>
```

### Creating API Tokens

#### Customer Token (for own balance/transactions)

```php
$user = User::find(1);
$token = $user->createToken('customer-app')->plainTextToken;
```

#### Third-Party Token (with scoped abilities)

```php
$user = User::find(1); // API client user
$token = $user->createToken('third-party-app', [
    'points:read',
    'transactions:read',
    'points:award',
    'points:deduct',
])->plainTextToken;
```

---

## Endpoint Examples

### Customer Endpoints

#### Get Own Point Balance

```bash
curl -X GET http://localhost:8000/api/v1/points/balance \
  -H "Authorization: Bearer <customer-token>" \
  -H "Accept: application/json"
```

**Response:**
```json
{
  "data": {
    "customer_id": 1,
    "points_balance": 1500,
    "tier": "gold",
    "last_transaction_at": "2025-12-19T10:30:00Z"
  }
}
```

#### Get Own Transaction History

```bash
curl -X GET "http://localhost:8000/api/v1/points/transactions?per_page=10" \
  -H "Authorization: Bearer <customer-token>" \
  -H "Accept: application/json"
```

---

### Third-Party Endpoints

#### Get Customer Points (requires `points:read`)

```bash
curl -X GET http://localhost:8000/api/v1/customers/123/points \
  -H "Authorization: Bearer <third-party-token>" \
  -H "Accept: application/json"
```

#### Get Customer Transactions (requires `transactions:read`)

```bash
curl -X GET "http://localhost:8000/api/v1/customers/123/transactions?from=2025-01-01&to=2025-12-31" \
  -H "Authorization: Bearer <third-party-token>" \
  -H "Accept: application/json"
```

#### Award Points (requires `points:award`)

```bash
curl -X POST http://localhost:8000/api/v1/customers/123/points/award \
  -H "Authorization: Bearer <third-party-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "points": 100,
    "description": "Partner purchase bonus",
    "metadata": {"order_id": "ORD-456"}
  }'
```

**Response (201 Created):**
```json
{
  "data": {
    "id": 789,
    "type": "bonus",
    "points": 100,
    "balance_after": 1600,
    "description": "Partner purchase bonus",
    "created_at": "2025-12-19T11:00:00Z"
  },
  "message": "Points awarded successfully."
}
```

#### Deduct Points (requires `points:deduct`)

```bash
curl -X POST http://localhost:8000/api/v1/customers/123/points/deduct \
  -H "Authorization: Bearer <third-party-token>" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "points": 50,
    "description": "Reward redemption",
    "metadata": {"reward_id": "RWD-789"}
  }'
```

---

## Error Responses

### 401 Unauthorized

```json
{
  "message": "Unauthenticated."
}
```

### 403 Forbidden (missing ability)

```json
{
  "message": "Invalid ability provided."
}
```

### 404 Not Found

```json
{
  "message": "Customer not found."
}
```

### 422 Validation Error

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "points": ["Points must be a positive integer."]
  }
}
```

### 422 Insufficient Balance

```json
{
  "message": "Insufficient points balance.",
  "errors": {
    "points": ["Cannot deduct more points than available balance."]
  }
}
```

---

## Running Tests

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/Api/V1/CustomerPointControllerTest.php

# Run with filter
php artisan test --filter="customer point balance"

# Run with coverage
php artisan test --coverage
```

---

## Token Abilities Reference

| Ability | Description |
|---------|-------------|
| `points:read` | Read any customer's point balance |
| `transactions:read` | Read any customer's transaction history |
| `points:award` | Award points to any customer |
| `points:deduct` | Deduct points from any customer |

---

## Development Commands

```bash
# Start development server
php artisan serve

# Run Pint for code style
vendor/bin/pint

# Run Pint check (CI)
vendor/bin/pint --test

# Fresh migrate and seed
php artisan migrate:fresh --seed
```
