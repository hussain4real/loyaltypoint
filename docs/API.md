# Loyalty Point API Documentation

**Base URL:** `http://loyalty-point.test/api/v1`

All endpoints are prefixed with `/api/v1`.

---

## Table of Contents

1. [Authentication](#authentication)
   - [Register](#post-authregister)
   - [Login](#post-authlogin)
   - [Get User](#get-authuser)
   - [Logout](#post-authlogout)
   - [Logout All Devices](#post-authlogout-all)
2. [Vendor OTP Authentication](#vendor-otp-authentication)
   - [Request OTP](#post-vendorauthrequest-otp)
   - [Verify OTP](#post-vendorauthverify-otp)
   - [Resend OTP](#post-vendorauthresend-otp)
3. [Providers](#providers)
   - [List Providers](#get-providers)
   - [Get Provider](#get-providersprovider)
4. [Customer Points (Self-Service)](#customer-points-self-service)
   - [Get Balance](#get-pointsbalance)
   - [Get Transactions](#get-pointstransactions)
5. [Point Exchange](#point-exchange)
   - [Preview Exchange](#post-pointsexchangepreview)
   - [Execute Exchange](#post-pointsexchange)
6. [Third-Party Customer Operations](#third-party-customer-operations)
   - [Get Customer Balance](#get-providersprovidercustomerscustomerpoints)
   - [Get Customer Transactions](#get-providersprovidercustomerscustomertransactions)
   - [Award Points](#post-providersprovidercustomerscustomerpointsaward)
   - [Deduct Points](#post-providersprovidercustomerscustomerpointsdeduct)

---

## Authentication

All authenticated endpoints require a Bearer token in the `Authorization` header:

```
Authorization: Bearer {token}
```

### POST /auth/register

Register a new user and receive an API token.

**Authentication:** None (Public)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | User's full name (max 255 chars) |
| `email` | string | Yes | Unique email address |
| `password` | string | Yes | Password (min 8 chars) |
| `password_confirmation` | string | Yes | Must match password |
| `device_name` | string | Yes | Device identifier (e.g., "iPhone 15", "Web App") |

**Example Request:**

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "SecurePass123!",
  "password_confirmation": "SecurePass123!",
  "device_name": "iPhone 15"
}
```

**Success Response (201):**

```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "token": "1|abc123def456...",
  "message": "Registration successful."
}
```

**Error Response (422):**

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": ["This email is already registered."]
  }
}
```

---

### POST /auth/login

Authenticate and receive an API token.

**Authentication:** None (Public)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Registered email address |
| `password` | string | Yes | User's password |
| `device_name` | string | Yes | Device identifier |

**Example Request:**

```json
{
  "email": "john@example.com",
  "password": "SecurePass123!",
  "device_name": "iPhone 15"
}
```

**Success Response (200):**

```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com"
  },
  "token": "2|xyz789ghi012...",
  "message": "Login successful."
}
```

**Error Response (422):**

```json
{
  "message": "The provided credentials are incorrect.",
  "errors": {
    "email": ["The provided credentials are incorrect."]
  }
}
```

---

### GET /auth/user

Get the authenticated user's profile.

**Authentication:** Required (Bearer Token)

**Success Response (200):**

```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com"
}
```

---

### POST /auth/logout

Revoke the current access token.

**Authentication:** Required (Bearer Token)

**Success Response (200):**

```json
{
  "message": "Logged out successfully."
}
```

---

### POST /auth/logout-all

Revoke all access tokens for the user (logout from all devices).

**Authentication:** Required (Bearer Token)

**Success Response (200):**

```json
{
  "message": "All devices logged out successfully."
}
```

---

## Vendor OTP Authentication

OTP-based authentication flow for vendor terminals. All vendor OTP endpoints require a `provider` slug to scope the authentication to a specific loyalty provider.

### POST /vendor/auth/request-otp

Send a one-time password to the user's email for a specific provider.

**Authentication:** None (Public)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Registered user's email |
| `vendor_name` | string | Yes | Vendor/terminal identifier |
| `provider` | string | Yes | Provider slug (e.g., `loyalty-plus`) |

**Example Request:**

```json
{
  "email": "alice@example.com",
  "vendor_name": "Store Terminal #1",
  "provider": "loyalty-plus"
}
```

**Success Response (200):**

```json
{
  "message": "Verification code sent to your email address.",
  "expires_in_minutes": 10,
  "provider": {
    "name": "Loyalty Plus",
    "slug": "loyalty-plus"
  }
}
```

**Error Response (422) - Invalid Provider:**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "provider": ["The specified provider does not exist."]
  }
}
```

**Error Response (422) - Inactive Provider:**

```json
{
  "message": "The specified provider is not active."
}
```

---

### POST /vendor/auth/verify-otp

Verify the OTP code and receive an access token with provider-scoped balance.

**Authentication:** None (Public)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | User's email |
| `code` | string | Yes | 6-digit OTP code |
| `device_name` | string | Yes | Device/terminal identifier |
| `provider` | string | Yes | Provider slug |

**Example Request:**

```json
{
  "email": "alice@example.com",
  "code": "123456",
  "device_name": "Store Terminal #1",
  "provider": "loyalty-plus"
}
```

**Success Response (200):**

```json
{
  "message": "Authentication successful.",
  "access_token": "3|mno345pqr678...",
  "token_type": "Bearer",
  "user": {
    "id": 2,
    "name": "Alice Johnson",
    "email": "alice@example.com"
  },
  "provider": {
    "id": 2,
    "name": "Loyalty Plus",
    "slug": "loyalty-plus"
  },
  "points_balance": 725
}
```

**Error Response (422):**

```json
{
  "message": "Invalid or expired verification code."
}
```

---

### POST /vendor/auth/resend-otp

Resend a new OTP code (invalidates previous codes).

**Authentication:** None (Public)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Yes | Registered user's email |
| `vendor_name` | string | Yes | Vendor/terminal identifier |
| `provider` | string | Yes | Provider slug |

**Example Request:**

```json
{
  "email": "alice@example.com",
  "vendor_name": "Store Terminal #1",
  "provider": "loyalty-plus"
}
```

**Success Response (200):**

```json
{
  "message": "A new verification code has been sent to your email address.",
  "expires_in_minutes": 10,
  "provider": {
    "name": "Loyalty Plus",
    "slug": "loyalty-plus"
  }
}
```

---

## Providers

### GET /providers

List all active loyalty point providers.

**Authentication:** None (Public)

**Success Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Bonus Network",
      "trade_name": "BonusNet",
      "slug": "bonus-network",
      "category": "entertainment",
      "description": "Earn bonus points on entertainment and gaming purchases.",
      "official_logo": "https://example.com/logos/bonus-network.png",
      "web_link": "https://bonusnetwork.example.com",
      "is_active": true,
      "exchange_rate_base": 1.5,
      "exchange_fee_percent": 3.0
    },
    {
      "id": 2,
      "name": "Loyalty Plus",
      "trade_name": "Loyalty+",
      "slug": "loyalty-plus",
      "category": "retail",
      "description": "Earn points on every purchase at partner retail stores.",
      "official_logo": "https://example.com/logos/loyalty-plus.png",
      "web_link": "https://loyaltyplus.example.com",
      "is_active": true,
      "exchange_rate_base": 1.0,
      "exchange_fee_percent": 0.0
    }
  ]
}
```

---

### GET /providers/{provider}

Get details of a specific provider by slug.

**Authentication:** None (Public)

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `provider` | string | Provider slug (e.g., `loyalty-plus`) |

**Success Response (200):**

```json
{
  "data": {
    "id": 2,
    "name": "Loyalty Plus",
    "trade_name": "Loyalty+",
    "slug": "loyalty-plus",
    "category": "retail",
    "description": "Earn points on every purchase at partner retail stores.",
    "official_logo": "https://example.com/logos/loyalty-plus.png",
    "web_link": "https://loyaltyplus.example.com",
    "is_active": true,
    "exchange_rate_base": 1.0,
    "exchange_fee_percent": 0.0
  }
}
```

**Error Response (404):**

```json
{
  "message": "No query results for model [App\\Models\\Provider]."
}
```

---

## Customer Points (Self-Service)

Endpoints for authenticated users to view their own point balances and transactions.

### GET /points/balance

Get the authenticated user's point balance(s).

**Authentication:** Required (Bearer Token)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `provider` | string | No | Provider slug to filter by |

**Example Request (All Providers):**

```
GET /api/v1/points/balance
```

**Success Response (200) - All Providers:**

```json
{
  "data": {
    "customer_id": 1,
    "balances": [
      {
        "provider": {
          "id": 2,
          "name": "Loyalty Plus",
          "slug": "loyalty-plus"
        },
        "points_balance": 725
      },
      {
        "provider": {
          "id": 3,
          "name": "Rewards Hub",
          "slug": "rewards-hub"
        },
        "points_balance": 250
      }
    ]
  }
}
```

**Example Request (Specific Provider):**

```
GET /api/v1/points/balance?provider=loyalty-plus
```

**Success Response (200) - Single Provider:**

```json
{
  "data": {
    "customer_id": 1,
    "provider": {
      "id": 2,
      "name": "Loyalty Plus",
      "slug": "loyalty-plus"
    },
    "points_balance": 725,
    "last_transaction_at": "2025-12-17T00:00:00+00:00"
  }
}
```

---

### GET /points/transactions

Get the authenticated user's transaction history.

**Authentication:** Required (Bearer Token)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `provider` | string | No | Provider slug to filter by |
| `from` | date | No | Start date (YYYY-MM-DD) |
| `to` | date | No | End date (YYYY-MM-DD) |
| `per_page` | integer | No | Results per page (default: 15, max: 100) |
| `page` | integer | No | Page number |

**Example Request:**

```
GET /api/v1/points/transactions?provider=loyalty-plus&from=2025-12-01&per_page=10
```

**Success Response (200):**

```json
{
  "data": [
    {
      "id": 5,
      "provider": {
        "id": 2,
        "name": "Loyalty Plus",
        "slug": "loyalty-plus"
      },
      "type": "earn",
      "points": 300,
      "balance_after": 725,
      "description": "Purchase #ORD-100003",
      "created_at": "2025-12-17T00:00:00+00:00"
    },
    {
      "id": 4,
      "provider": {
        "id": 2,
        "name": "Loyalty Plus",
        "slug": "loyalty-plus"
      },
      "type": "redeem",
      "points": -75,
      "balance_after": 425,
      "description": "Reward redemption",
      "created_at": "2025-12-07T00:00:00+00:00"
    }
  ],
  "links": {
    "first": "http://loyalty-point.test/api/v1/points/transactions?page=1",
    "last": "http://loyalty-point.test/api/v1/points/transactions?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 10,
    "to": 2,
    "total": 2
  }
}
```

---

## Point Exchange

Exchange points between different providers.

### POST /points/exchange/preview

Preview an exchange calculation without executing it.

**Authentication:** Required (Bearer Token)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `from_provider` | string | Yes | Source provider slug |
| `to_provider` | string | Yes | Destination provider slug (must be different) |
| `points` | integer | Yes | Points to exchange (min: 1) |

**Example Request:**

```json
{
  "from_provider": "loyalty-plus",
  "to_provider": "rewards-hub",
  "points": 100
}
```

**Success Response (200):**

```json
{
  "data": {
    "from_provider": {
      "slug": "loyalty-plus",
      "name": "Loyalty Plus",
      "exchange_rate_base": 1.0,
      "exchange_fee_percent": 0.0
    },
    "to_provider": {
      "slug": "rewards-hub",
      "name": "Rewards Hub",
      "exchange_rate_base": 1.25
    },
    "points_to_send": 100,
    "fee_percent": 0.0,
    "fee_deducted": 0,
    "net_points_after_fee": 100,
    "points_to_receive": 80,
    "current_balance": 725,
    "sufficient_balance": true
  }
}
```

**Exchange Rate Calculation:**

```
net_points = points_to_send - fee_deducted
fee_deducted = floor(points_to_send × fee_percent / 100)
points_to_receive = floor((net_points × from_rate) / to_rate)
```

---

### POST /points/exchange

Execute a point exchange between providers.

**Authentication:** Required (Bearer Token)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `from_provider` | string | Yes | Source provider slug |
| `to_provider` | string | Yes | Destination provider slug |
| `points` | integer | Yes | Points to exchange (min: 1, max: 10,000,000) |

**Example Request:**

```json
{
  "from_provider": "loyalty-plus",
  "to_provider": "rewards-hub",
  "points": 100
}
```

**Success Response (201):**

```json
{
  "data": {
    "points_sent": 100,
    "fee_deducted": 0,
    "points_received": 80,
    "transfer_out": {
      "id": 29,
      "provider": {
        "id": 2,
        "name": "Loyalty Plus",
        "slug": "loyalty-plus"
      },
      "type": "transfer_out",
      "points": -100,
      "balance_after": 625,
      "description": "Exchange to Rewards Hub",
      "created_at": "2025-12-22T10:30:00+00:00"
    },
    "transfer_in": {
      "id": 30,
      "provider": {
        "id": 3,
        "name": "Rewards Hub",
        "slug": "rewards-hub"
      },
      "type": "transfer_in",
      "points": 80,
      "balance_after": 330,
      "description": "Exchange from Loyalty Plus",
      "created_at": "2025-12-22T10:30:00+00:00"
    }
  },
  "message": "Points exchanged successfully."
}
```

**Error Response (422) - Insufficient Balance:**

```json
{
  "message": "Insufficient balance",
  "errors": {
    "points": ["Insufficient balance. Available: 50 points."]
  }
}
```

---

## Third-Party Customer Operations

Endpoints for third-party systems (vendors, partners) to manage customer points. These require tokens with specific abilities.

### Token Abilities

| Ability | Description |
|---------|-------------|
| `points:read` | Read customer point balances |
| `transactions:read` | Read customer transaction history |
| `points:award` | Award points to customers |
| `points:deduct` | Deduct points from customers |

---

### GET /providers/{provider}/customers/{customer}/points

Get a customer's point balance for a specific provider.

**Authentication:** Required (Bearer Token with `points:read` ability)

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `provider` | string | Provider slug |
| `customer` | integer | Customer user ID |

**Success Response (200):**

```json
{
  "data": {
    "customer_id": 2,
    "provider": {
      "id": 2,
      "name": "Loyalty Plus",
      "slug": "loyalty-plus"
    },
    "points_balance": 725,
    "last_transaction_at": "2025-12-17T00:00:00+00:00"
  }
}
```

**Error Response (403):**

```json
{
  "message": "Invalid ability provided."
}
```

---

### GET /providers/{provider}/customers/{customer}/transactions

Get a customer's transaction history for a specific provider.

**Authentication:** Required (Bearer Token with `transactions:read` ability)

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `provider` | string | Provider slug |
| `customer` | integer | Customer user ID |

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from` | date | No | Start date (YYYY-MM-DD) |
| `to` | date | No | End date (YYYY-MM-DD) |
| `per_page` | integer | No | Results per page (default: 15, max: 100) |
| `page` | integer | No | Page number |

**Success Response (200):** Same format as [GET /points/transactions](#get-pointstransactions)

---

### POST /providers/{provider}/customers/{customer}/points/award

Award points to a customer.

**Authentication:** Required (Bearer Token with `points:award` ability)

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `provider` | string | Provider slug |
| `customer` | integer | Customer user ID |

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `points` | integer | Yes | Points to award (min: 1, max: 1,000,000) |
| `description` | string | Yes | Transaction description (max 255 chars) |
| `metadata` | object | No | Additional data to store with transaction |

**Example Request:**

```json
{
  "points": 150,
  "description": "Purchase #ORD-600001",
  "metadata": {
    "order_id": "ORD-600001",
    "store_id": "STORE-001"
  }
}
```

**Success Response (201):**

```json
{
  "data": {
    "id": 31,
    "provider": {
      "id": 2,
      "name": "Loyalty Plus",
      "slug": "loyalty-plus"
    },
    "type": "bonus",
    "points": 150,
    "balance_after": 875,
    "description": "Purchase #ORD-600001",
    "created_at": "2025-12-22T11:00:00+00:00"
  },
  "message": "Points awarded successfully."
}
```

---

### POST /providers/{provider}/customers/{customer}/points/deduct

Deduct points from a customer (e.g., for redemptions).

**Authentication:** Required (Bearer Token with `points:deduct` ability)

**URL Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `provider` | string | Provider slug |
| `customer` | integer | Customer user ID |

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `points` | integer | Yes | Points to deduct (min: 1, max: 1,000,000) |
| `description` | string | Yes | Transaction description (max 255 chars) |
| `metadata` | object | No | Additional data to store with transaction |

**Example Request:**

```json
{
  "points": 100,
  "description": "Reward redemption - Free Coffee",
  "metadata": {
    "reward_id": "RWD-001",
    "redeemed_at": "STORE-001"
  }
}
```

**Success Response (201):**

```json
{
  "data": {
    "id": 32,
    "provider": {
      "id": 2,
      "name": "Loyalty Plus",
      "slug": "loyalty-plus"
    },
    "type": "redeem",
    "points": -100,
    "balance_after": 775,
    "description": "Reward redemption - Free Coffee",
    "created_at": "2025-12-22T11:05:00+00:00"
  },
  "message": "Points deducted successfully."
}
```

**Error Response (422) - Insufficient Balance:**

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "points": ["Insufficient balance. Available: 50 points."]
  }
}
```

---

## Transaction Types

| Type | Description | Points Value |
|------|-------------|--------------|
| `earn` | Points earned from purchases | Positive |
| `redeem` | Points redeemed for rewards | Negative |
| `bonus` | Promotional bonus points | Positive |
| `adjustment` | Manual adjustment | Positive or Negative |
| `transfer_out` | Points sent in exchange | Negative |
| `transfer_in` | Points received from exchange | Positive |

---

## HTTP Status Codes

| Code | Description |
|------|-------------|
| `200` | Success |
| `201` | Created (successful POST operations) |
| `401` | Unauthorized (missing or invalid token) |
| `403` | Forbidden (insufficient token abilities) |
| `404` | Not Found |
| `422` | Validation Error |
| `500` | Server Error |

---

## Rate Limiting

API requests are rate-limited. Include the following headers in responses:

- `X-RateLimit-Limit` - Maximum requests per minute
- `X-RateLimit-Remaining` - Remaining requests
- `Retry-After` - Seconds to wait (when rate limited)
