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
3. [Vendor Account Linking](#vendor-account-linking)
   - [Lookup by Vendor Email](#get-vendorcustomersby-vendor-email)
4. [Vendor Cross-Account Exchange](#vendor-cross-account-exchange)
   - [Preview Vendor Exchange](#post-vendorpointsexchangepreview)
   - [Execute Vendor Exchange](#post-vendorpointsexchange)
5. [Vendor Points (Auto-Scoped)](#vendor-points-auto-scoped)
   - [Get Balance](#get-vendorpointsbalance)
   - [Get Transactions](#get-vendorpointstransactions)
6. [Providers](#providers)
   - [List Providers](#get-providers)
   - [Get Provider](#get-providersprovider)
7. [Customer Points (Self-Service)](#customer-points-self-service)
   - [Get Balance](#get-pointsbalance)
   - [Get Transactions](#get-pointstransactions)
8. [Point Exchange](#point-exchange)
   - [Preview Exchange](#post-pointsexchangepreview)
   - [Execute Exchange](#post-pointsexchange)
9. [Third-Party Customer Operations](#third-party-customer-operations)
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

OTP-based authentication flow for vendor terminals.

### Two Authentication Flows

1. **First-Time Linking (Platform Email)**: Use `email` (platform email) to identify and authenticate a user. Requires `provider`. Include `vendor_email` during verification to create the link.

2. **Linked Account Authentication (Vendor Email)**: Once an account is linked, use `vendor_email` alone to identify the user. No `provider` needed - it's automatically derived from the link. The OTP will be sent to the vendor_email address.

### POST /vendor/auth/request-otp

Send a one-time password to authenticate a user for a specific provider.

**Authentication:** None (Public)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Required if no `vendor_email` | User's platform email (for first-time linking) |
| `vendor_email` | string | Required if no `email` | Vendor's customer email (for linked accounts) |
| `vendor_name` | string | Yes | Vendor/terminal identifier |
| `provider` | string | Required with `email` | Provider slug (e.g., `loyalty-plus`) |

**Example Request (First-Time - Platform Email):**

```json
{
  "email": "alice@example.com",
  "vendor_name": "Store Terminal #1",
  "provider": "loyalty-plus"
}
```

**Example Request (Linked Account - Vendor Email):**

```json
{
  "vendor_email": "alice.customer@vendor.com",
  "vendor_name": "Store Terminal #1"
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

**Error Response (404) - Vendor Email Not Linked:**

When using `vendor_email` but no account is linked:

```json
{
  "message": "No linked account found for this vendor email."
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
| `email` | string | Required if no `vendor_email` | User's platform email (for first-time linking) |
| `vendor_email` | string | Required if no `email` | Vendor's customer email (for linked accounts, or to link during verification) |
| `code` | string | Yes | 6-digit OTP code |
| `device_name` | string | Yes | Device/terminal identifier |
| `provider` | string | Required with `email` | Provider slug |

#### First-Time Linking Flow

When using `email` with `vendor_email`, a link is created between the vendor's customer email and the platform user. This enables:
- **Account persistence**: When vendor apps are reinstalled, the vendor can look up which platform user is linked to their customer email
- **Cross-account exchange**: Customers can exchange points between different platform accounts linked to the same vendor email

**Example Request (First-Time Linking):**

```json
{
  "email": "alice@example.com",
  "code": "123456",
  "device_name": "Store Terminal #1",
  "provider": "loyalty-plus",
  "vendor_email": "alice.customer@vendor.com"
}
```

#### Linked Account Flow

Once linked, use `vendor_email` alone to authenticate:

**Example Request (Linked Account):**

```json
{
  "vendor_email": "alice.customer@vendor.com",
  "code": "123456",
  "device_name": "Store Terminal #1"
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

**Error Response (404) - Vendor Email Not Linked:**

When using `vendor_email` only but no account is linked:

```json
{
  "message": "No linked account found for this vendor email."
}
```

**Error Response (422 - Invalid OTP):**

```json
{
  "message": "Invalid or expired verification code."
}
```

**Error Response (422 - Vendor Email Already Linked):**

When the vendor_email is already linked to a different platform user for this provider:

```json
{
  "message": "This vendor email is already linked to a different user for this provider."
}
```

---

### POST /vendor/auth/resend-otp

Resend a new OTP code (invalidates previous codes).

**Authentication:** None (Public)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | Required if no `vendor_email` | User's platform email (for first-time linking) |
| `vendor_email` | string | Required if no `email` | Vendor's customer email (for linked accounts) |
| `vendor_name` | string | Yes | Vendor/terminal identifier |
| `provider` | string | Required with `email` | Provider slug |

**Example Request (First-Time - Platform Email):**

```json
{
  "email": "alice@example.com",
  "vendor_name": "Store Terminal #1",
  "provider": "loyalty-plus"
}
```

**Example Request (Linked Account - Vendor Email):**

```json
{
  "vendor_email": "alice.customer@vendor.com",
  "vendor_name": "Store Terminal #1"
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

**Error Response (404) - Vendor Email Not Linked:**

When using `vendor_email` but no account is linked:

```json
{
  "message": "No linked account found for this vendor email."
}
```

---

## Vendor Account Linking

Vendor account linking allows vendors to maintain a persistent relationship between their customer emails and platform users. This is useful when:
- Vendor apps are reinstalled and need to recover the linked platform account
- Cross-account exchanges are needed (exchanging points between different platform users)

### GET /vendor/customers/by-vendor-email

Look up all platform accounts linked to a vendor email. Returns linked accounts with their point balances.

**Authentication:** Bearer Token (Vendor token with provider scope)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `vendor_email` | string | Yes | The vendor's customer email |
| `provider` | string | No | Filter by specific provider slug |

**Example Request:**

```
GET /api/v1/vendor/customers/by-vendor-email?vendor_email=alice@vendor.com
Authorization: Bearer {vendor_token}
```

**Success Response (200):**

```json
{
  "vendor_email": "alice@vendor.com",
  "linked_accounts": [
    {
      "provider": {
        "id": 1,
        "name": "Airline Miles",
        "slug": "airline-miles"
      },
      "user": {
        "id": 1,
        "name": "Alice Smith",
        "email": "alice@example.com"
      },
      "points_balance": 5000,
      "linked_at": "2025-01-15T10:30:00.000000Z"
    },
    {
      "provider": {
        "id": 2,
        "name": "Rewards Hub",
        "slug": "rewards-hub"
      },
      "user": {
        "id": 2,
        "name": "Alice Johnson",
        "email": "alice.work@example.com"
      },
      "points_balance": 1500,
      "linked_at": "2025-01-16T14:20:00.000000Z"
    }
  ]
}
```

**Success Response with Provider Filter (200):**

```
GET /api/v1/vendor/customers/by-vendor-email?vendor_email=alice@vendor.com&provider=airline-miles
```

```json
{
  "vendor_email": "alice@vendor.com",
  "linked_accounts": [
    {
      "provider": {
        "id": 1,
        "name": "Airline Miles",
        "slug": "airline-miles"
      },
      "user": {
        "id": 1,
        "name": "Alice Smith",
        "email": "alice@example.com"
      },
      "points_balance": 5000,
      "linked_at": "2025-01-15T10:30:00.000000Z"
    }
  ]
}
```

**Error Response (422 - Validation Error):**

```json
{
  "message": "The vendor email field is required.",
  "errors": {
    "vendor_email": ["The vendor email field is required."]
  }
}
```

**Error Response (404 - No Links Found):**

```json
{
  "message": "No linked accounts found for this vendor email."
}
```

---

## Vendor Cross-Account Exchange

Vendor cross-account exchange allows vendors to facilitate point exchanges between different platform users who are linked to the same vendor email. This is useful when a customer has multiple platform accounts (e.g., personal and work) and wants to consolidate or transfer points between them.

**How it works:**
1. Both platform accounts must be linked to the same vendor email
2. The exchange uses value-based conversion (points → dollars → points)
3. Three-tier fee structure applies: source provider fee, destination provider fee, and app fee

### POST /vendor/points/exchange/preview

Preview a cross-account exchange without executing it. Returns detailed breakdown of fees and conversion.

**Authentication:** Bearer Token (Vendor token with provider scope)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `vendor_email` | string | Yes | The vendor's customer email (umbrella identity) |
| `from_provider` | string | Yes | Source provider slug |
| `to_provider` | string | Yes | Destination provider slug (must be different from source) |
| `points` | integer | Yes | Number of points to exchange from source |

**Example Request:**

```json
{
  "vendor_email": "alice@vendor.com",
  "from_provider": "airline-miles",
  "to_provider": "rewards-hub",
  "points": 1000
}
```

**Success Response (200):**

```json
{
  "preview": {
    "from_account": {
      "user": {
        "id": 1,
        "name": "Alice Smith",
        "email": "alice@example.com"
      },
      "provider": {
        "id": 1,
        "name": "Airline Miles",
        "slug": "airline-miles"
      },
      "current_balance": 5000,
      "balance_after": 4000
    },
    "to_account": {
      "user": {
        "id": 2,
        "name": "Alice Johnson",
        "email": "alice.work@example.com"
      },
      "provider": {
        "id": 2,
        "name": "Rewards Hub",
        "slug": "rewards-hub"
      },
      "current_balance": 1500,
      "balance_after": 2400
    },
    "exchange": {
      "points_to_exchange": 1000,
      "source_value": 100.00,
      "source_fee_rate": 0.05,
      "source_fee": 5.00,
      "value_after_source_fee": 95.00,
      "app_fee_rate": 0.02,
      "app_fee": 1.90,
      "value_after_app_fee": 93.10,
      "destination_fee_rate": 0.03,
      "destination_fee": 2.79,
      "net_value": 90.31,
      "destination_ratio": 0.10,
      "points_to_receive": 903
    }
  }
}
```

**Error Response (404 - No Link for Provider):**

```json
{
  "message": "No account linked for source provider."
}
```

**Error Response (422 - Insufficient Balance):**

```json
{
  "message": "Insufficient points balance for exchange."
}
```

---

### POST /vendor/points/exchange

Execute a cross-account exchange between two platform users linked to the same vendor email.

**Authentication:** Bearer Token (Vendor token with provider scope)

**Request Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `vendor_email` | string | Yes | The vendor's customer email (umbrella identity) |
| `from_provider` | string | Yes | Source provider slug |
| `to_provider` | string | Yes | Destination provider slug (must be different from source) |
| `points` | integer | Yes | Number of points to exchange from source |

**Example Request:**

```json
{
  "vendor_email": "alice@vendor.com",
  "from_provider": "airline-miles",
  "to_provider": "rewards-hub",
  "points": 1000
}
```

**Success Response (200):**

```json
{
  "message": "Exchange completed successfully.",
  "exchange": {
    "exchange_id": "abc12345-def6-7890-ghij-klmnopqrstuv",
    "from_account": {
      "user": {
        "id": 1,
        "name": "Alice Smith",
        "email": "alice@example.com"
      },
      "provider": {
        "id": 1,
        "name": "Airline Miles",
        "slug": "airline-miles"
      },
      "points_deducted": 1000,
      "new_balance": 4000
    },
    "to_account": {
      "user": {
        "id": 2,
        "name": "Alice Johnson",
        "email": "alice.work@example.com"
      },
      "provider": {
        "id": 2,
        "name": "Rewards Hub",
        "slug": "rewards-hub"
      },
      "points_received": 903,
      "new_balance": 2403
    },
    "fees": {
      "source_fee": 5.00,
      "app_fee": 1.90,
      "destination_fee": 2.79,
      "total_fees": 9.69
    },
    "conversion": {
      "source_value": 100.00,
      "net_value": 90.31
    }
  }
}
```

**Error Response (404 - No Link for Provider):**

```json
{
  "message": "No account linked for destination provider."
}
```

**Error Response (422 - Same User):**

When both providers are linked to the same platform user:

```json
{
  "message": "Both providers are linked to the same user. Use regular exchange instead."
}
```

---

## Vendor Points (Auto-Scoped)

These endpoints are designed for vendor-authenticated users who have linked their account via vendor email. They return data for **all accounts** linked to the same vendor email (including across different platform users), eliminating the need to specify a provider parameter.

**Key Feature:** If a vendor email like `alice@vendor.com` is linked to multiple platform accounts (e.g., `alice@personal.com` and `alice@work.com`), these endpoints return balances and transactions for ALL of them.

**Note:** These endpoints return a `400` error if the authenticated user has no vendor links.

### GET /vendor/points/balance

Get point balances for all accounts linked to the same vendor email.

**Authentication:** Bearer Token (User authenticated via vendor OTP)

**Success Response (200):**

```json
{
  "data": [
    {
      "user": {
        "id": 1,
        "name": "Alice Personal",
        "email": "alice@personal.com"
      },
      "provider": {
        "id": 1,
        "name": "Loyalty Plus",
        "slug": "loyalty-plus"
      },
      "points_balance": 625
    },
    {
      "user": {
        "id": 1,
        "name": "Alice Personal",
        "email": "alice@personal.com"
      },
      "provider": {
        "id": 2,
        "name": "Rewards Hub",
        "slug": "rewards-hub"
      },
      "points_balance": 1500
    },
    {
      "user": {
        "id": 2,
        "name": "Alice Work",
        "email": "alice@work.com"
      },
      "provider": {
        "id": 3,
        "name": "Bonus Network",
        "slug": "bonus-network"
      },
      "points_balance": 1950
    }
  ]
}
```

**Error Response (400 - No Vendor Link):**

```json
{
  "message": "No vendor account linked. Use the standard /points/balance endpoint with ?provider= parameter."
}
```

---

### GET /vendor/points/transactions

Get transaction history for all accounts linked to the same vendor email.

**Authentication:** Bearer Token (User authenticated via vendor OTP)

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `from` | date | No | Filter transactions from this date (Y-m-d) |
| `to` | date | No | Filter transactions up to this date (Y-m-d) |
| `per_page` | integer | No | Number of results per page (default: 15) |

**Success Response (200):**

```json
{
  "data": [
    {
      "id": 1,
      "type": "earned",
      "points": 500,
      "description": "Purchase reward",
      "meta": null,
      "provider": {
        "id": 1,
        "name": "Loyalty Plus",
        "slug": "loyalty-plus"
      },
      "created_at": "2024-01-15T10:30:00.000000Z"
    },
    {
      "id": 2,
      "type": "redeemed",
      "points": -100,
      "description": "Redeemed for discount",
      "meta": null,
      "provider": {
        "id": 2,
        "name": "Rewards Hub",
        "slug": "rewards-hub"
      },
      "created_at": "2024-01-14T14:20:00.000000Z"
    }
  ],
  "links": {
    "first": "http://loyalty-point.test/api/v1/vendor/points/transactions?page=1",
    "last": "http://loyalty-point.test/api/v1/vendor/points/transactions?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 2,
    "total": 2
  }
}
```

**Error Response (400 - No Vendor Link):**

```json
{
  "message": "No vendor account linked. Use the standard /points/transactions endpoint with ?provider= parameter."
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
      "points_to_value_ratio": 0.5,
      "transfer_fee_percent": 2.5
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
      "points_to_value_ratio": 0.1,
      "transfer_fee_percent": 1.5
    }
  ]
}
```

**Provider Fields:**

| Field | Description |
|-------|-------------|
| `points_to_value_ratio` | Value of 1 point in currency (e.g., 0.1 means 10 points = $1) |
| `transfer_fee_percent` | Fee charged when transferring OUT of this provider |
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
    "points_to_value_ratio": 0.1,
    "transfer_fee_percent": 1.5
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

Exchange points between different providers using value-based conversion.

**How Exchange Works:**

1. **Convert points to value:** `gross_value = points × source_provider.points_to_value_ratio`
2. **Calculate fees:** Three fees apply - source provider fee, destination provider fee, and app fee (5%)
3. **Deduct fees:** `net_value = gross_value - (gross_value × total_fee_percent / 100)`
4. **Convert to destination points:** `points_received = floor(net_value / destination_provider.points_to_value_ratio)`

### POST /points/exchange/preview

Preview an exchange calculation without executing it. Shows detailed fee breakdown.

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
  "points": 1000
}
```

**Success Response (200):**

```json
{
  "data": {
    "points_to_send": 1000,
    "from_provider": {
      "slug": "loyalty-plus",
      "name": "Loyalty Plus",
      "points_to_value_ratio": 0.1,
      "transfer_fee_percent": 1.5
    },
    "to_provider": {
      "slug": "rewards-hub",
      "name": "Rewards Hub",
      "points_to_value_ratio": 1.0,
      "transfer_fee_percent": 3.5
    },
    "current_balance": 2500,
    "sufficient_balance": true,
    "gross_value": 100.0,
    "fees": {
      "source_provider_fee": {
        "percent": 1.5,
        "value": 1.5
      },
      "destination_provider_fee": {
        "percent": 3.5,
        "value": 3.5
      },
      "app_fee": {
        "percent": 5.0,
        "value": 5.0
      },
      "total": {
        "percent": 10.0,
        "value": 10.0
      }
    },
    "net_value": 90.0,
    "points_to_receive": 90
  }
}
```

**Calculation Breakdown (Example above):**

| Step | Calculation | Result |
|------|-------------|--------|
| 1. Gross Value | 1000 points × $0.10 | $100.00 |
| 2. Source Fee (1.5%) | $100 × 1.5% | $1.50 |
| 3. Dest Fee (3.5%) | $100 × 3.5% | $3.50 |
| 4. App Fee (5%) | $100 × 5% | $5.00 |
| 5. Total Fees | $1.50 + $3.50 + $5.00 | $10.00 |
| 6. Net Value | $100 - $10 | $90.00 |
| 7. Points Received | $90 / $1.00 | 90 points |

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
  "points": 1000
}
```

**Success Response (201):**

```json
{
  "data": {
    "points_sent": 1000,
    "gross_value": 100.0,
    "total_fee_percent": 10.0,
    "total_fee_value": 10.0,
    "net_value": 90.0,
    "points_received": 90,
    "transfer_out": {
      "id": 29,
      "provider": {
        "id": 2,
        "name": "Loyalty Plus",
        "slug": "loyalty-plus"
      },
      "type": "transfer_out",
      "points": -1000,
      "balance_after": 1500,
      "description": "Transfer to Rewards Hub",
      "created_at": "2025-12-23T10:30:00+00:00"
    },
    "transfer_in": {
      "id": 30,
      "provider": {
        "id": 3,
        "name": "Rewards Hub",
        "slug": "rewards-hub"
      },
      "type": "transfer_in",
      "points": 90,
      "balance_after": 90,
      "description": "Transfer from Loyalty Plus",
      "created_at": "2025-12-23T10:30:00+00:00"
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
