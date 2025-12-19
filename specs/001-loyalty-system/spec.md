# Feature Specification: Loyalty Point System

**Feature Branch**: `001-loyalty-system`  
**Created**: 2025-12-19  
**Status**: Draft  
**Input**: User description: "Build a loyalty point application for customers. We will also need to create some endpoints for third party app to be able to get user points from our platform"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Customer Earns Points from Purchases (Priority: P1)

As a customer, I want to earn loyalty points when I make purchases so that I can accumulate rewards for future use.

**Why this priority**: This is the core value proposition of a loyalty system - customers must be able to earn points. Without earning, there is no loyalty program.

**Independent Test**: Can be fully tested by simulating a purchase transaction and verifying points are credited to the customer's account.

**Acceptance Scenarios**:

1. **Given** a registered customer with 0 points, **When** they make a purchase of $100, **Then** they should earn points based on the configured earning rate (e.g., 1 point per $1 = 100 points).
2. **Given** a registered customer with 500 existing points, **When** they make a purchase of $50, **Then** their total points should increase to 550 points.
3. **Given** a customer makes a purchase, **When** the points are credited, **Then** a transaction record should be created showing the points earned, source, and timestamp.

---

### User Story 2 - Customer Views Point Balance (Priority: P1)

As a customer, I want to view my current loyalty point balance so that I know how many points I have available.

**Why this priority**: Customers need visibility into their rewards to stay engaged with the loyalty program.

**Independent Test**: Can be tested by authenticating as a customer and viewing their point balance on a dashboard or via API.

**Acceptance Scenarios**:

1. **Given** a customer with 1,500 points, **When** they view their loyalty dashboard, **Then** they should see their current balance of 1,500 points.
2. **Given** a customer with 0 points, **When** they view their balance, **Then** they should see 0 points with a friendly message encouraging first purchase.
3. **Given** a customer, **When** they view their balance, **Then** they should also see their tier status (if applicable).

---

### User Story 3 - Third-Party App Retrieves Customer Points (Priority: P1)

As a third-party application, I want to retrieve a customer's point balance via API so that I can display or use their loyalty information in my application.

**Why this priority**: Critical for ecosystem integration - third-party apps are a core requirement specified by the user.

**Independent Test**: Can be tested by making an authenticated API request with valid credentials and customer identifier.

**Acceptance Scenarios**:

1. **Given** a valid API token and customer ID, **When** a GET request is made to `/api/v1/customers/{id}/points`, **Then** the response should include the customer's current point balance.
2. **Given** an invalid API token, **When** a request is made to the points endpoint, **Then** a 401 Unauthorized response should be returned.
3. **Given** a valid API token but non-existent customer ID, **When** a request is made, **Then** a 404 Not Found response should be returned.
4. **Given** a valid request, **When** points are retrieved, **Then** the response should include: `points_balance`, `tier`, `last_transaction_date`, and `customer_id`.

---

### User Story 4 - Customer Redeems Points for Rewards (Priority: P2)

As a customer, I want to redeem my loyalty points for rewards so that I can get value from my accumulated points.

**Why this priority**: Redemption completes the value cycle but requires earning to be implemented first.

**Independent Test**: Can be tested by attempting to redeem points against a reward and verifying balance decreases.

**Acceptance Scenarios**:

1. **Given** a customer with 1,000 points and a reward costing 500 points, **When** they redeem for that reward, **Then** their balance should decrease to 500 points.
2. **Given** a customer with 200 points and a reward costing 500 points, **When** they attempt to redeem, **Then** they should receive an error indicating insufficient points.
3. **Given** a successful redemption, **When** the transaction completes, **Then** a redemption record should be created with the reward details.

---

### User Story 5 - Customer Views Transaction History (Priority: P2)

As a customer, I want to view my point transaction history so that I can see how I earned and spent my points.

**Why this priority**: Transparency builds trust in the loyalty program.

**Independent Test**: Can be tested by viewing transaction history and verifying all earn/redeem events are listed.

**Acceptance Scenarios**:

1. **Given** a customer with multiple transactions, **When** they view their history, **Then** they should see a paginated list of transactions with type (earn/redeem), amount, description, and date.
2. **Given** a customer with no transactions, **When** they view history, **Then** they should see an empty state with appropriate messaging.
3. **Given** a customer views history, **When** filtering by date range, **Then** only transactions within that range should display.

---

### User Story 6 - Third-Party App Retrieves Transaction History (Priority: P2)

As a third-party application, I want to retrieve a customer's transaction history via API so that I can display their loyalty activity.

**Why this priority**: Extends API capabilities for richer third-party integrations.

**Independent Test**: Can be tested by making an authenticated API request for transactions.

**Acceptance Scenarios**:

1. **Given** a valid API token and customer ID, **When** a GET request is made to `/api/v1/customers/{id}/transactions`, **Then** the response should include paginated transaction history.
2. **Given** optional query parameters for date filtering, **When** included in the request, **Then** results should be filtered accordingly.
3. **Given** a request with `per_page` parameter, **When** the request is made, **Then** pagination should respect the specified limit.

---

### User Story 7 - Admin Awards Bonus Points (Priority: P3)

As an administrator, I want to manually award bonus points to customers so that I can run promotions or resolve customer service issues.

**Why this priority**: Important for operations but not critical for MVP launch.

**Independent Test**: Can be tested by an admin awarding points and verifying the customer's balance increases.

**Acceptance Scenarios**:

1. **Given** an authenticated admin and a customer ID, **When** the admin awards 500 bonus points, **Then** the customer's balance should increase by 500.
2. **Given** a bonus point award, **When** the transaction is created, **Then** it should be marked with type "bonus" and include an admin note.
3. **Given** a third-party app with admin scope, **When** it calls the award points endpoint, **Then** points should be credited to the customer.

---

### User Story 8 - Third-Party App Awards Points (Priority: P3)

As a third-party application, I want to award points to customers via API so that I can integrate point earning into my platform.

**Why this priority**: Enables deeper integrations where partners can trigger point awards.

**Independent Test**: Can be tested by making an authenticated POST request to award points.

**Acceptance Scenarios**:

1. **Given** a valid API token with `points:award` scope, **When** a POST request is made to `/api/v1/customers/{id}/points/award`, **Then** points should be added to the customer's balance.
2. **Given** a request with a reason/description, **When** points are awarded, **Then** the transaction should include that reason.
3. **Given** an API token without the required scope, **When** attempting to award points, **Then** a 403 Forbidden response should be returned.

---

### Edge Cases

- What happens when a customer attempts to redeem more points than they have? → Should return validation error with insufficient balance message.
- How does system handle concurrent point transactions? → Should use database transactions with locking to prevent race conditions.
- What happens if a third-party provides negative point values? → Should validate and reject negative values.
- What happens when a customer is deleted? → Point transactions should be soft-deleted or archived for audit purposes.
- How are point expirations handled? → Points should have optional expiration dates; expired points are excluded from balance calculations.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: System MUST allow customers to earn points based on configurable earning rules.
- **FR-002**: System MUST track and display customer point balances accurately.
- **FR-003**: System MUST record all point transactions with type, amount, description, and timestamp.
- **FR-004**: System MUST provide authenticated API endpoints for third-party applications to retrieve customer point data.
- **FR-005**: System MUST support point redemption with balance validation.
- **FR-006**: System MUST authenticate API requests using Laravel Sanctum tokens.
- **FR-007**: System MUST support API versioning (starting with v1) for backward compatibility.
- **FR-008**: System MUST paginate list responses (transactions, etc.) with configurable page sizes.
- **FR-009**: System MUST validate all API inputs and return appropriate error responses.
- **FR-010**: System MUST support admin operations for manual point adjustments (awards, deductions).
- **FR-011**: System MUST log all point transactions for audit purposes.
- **FR-012**: Third-party API tokens MUST support scoped permissions (e.g., `points:read`, `points:award`).

### Non-Functional Requirements

- **NFR-001**: API response time SHOULD be under 200ms for balance queries.
- **NFR-002**: System MUST handle concurrent point transactions safely using database transactions.
- **NFR-003**: All API endpoints MUST use HTTPS in production.
- **NFR-004**: Point calculations MUST be accurate (no floating point issues) - store as integers.

### Key Entities

- **Customer (User)**: The user who participates in the loyalty program. Has a point balance and transaction history. Extends the existing User model.
  
- **PointTransaction**: A record of points earned, redeemed, or adjusted. Key attributes:
  - `id`: Unique identifier
  - `user_id`: Foreign key to Customer/User
  - `type`: Enum (earn, redeem, bonus, adjustment)
  - `points`: Integer (positive for credit, negative for debit)
  - `balance_after`: Integer (customer's balance after transaction)
  - `description`: Text describing the transaction source
  - `metadata`: JSON for additional context (order ID, reward ID, etc.)
  - `expires_at`: Optional expiration date for earned points
  - `created_at`: Timestamp

- **Reward**: (Future consideration) Items or benefits that can be redeemed with points. Key attributes:
  - `id`: Unique identifier
  - `name`: Reward name
  - `description`: Reward description
  - `points_required`: Integer cost in points
  - `is_active`: Boolean for availability
  - `stock`: Optional inventory count

- **ApiClient**: Represents a third-party application with API access. Uses Laravel Sanctum personal access tokens with abilities/scopes for permission control.

## API Endpoints (v1)

### Public Endpoints (Customer Authenticated)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/points/balance` | Get authenticated customer's point balance |
| GET | `/api/v1/points/transactions` | Get authenticated customer's transaction history |

### Third-Party Endpoints (API Token Authenticated)

| Method | Endpoint | Scope Required | Description |
|--------|----------|----------------|-------------|
| GET | `/api/v1/customers/{id}/points` | `points:read` | Get customer's point balance |
| GET | `/api/v1/customers/{id}/transactions` | `transactions:read` | Get customer's transactions |
| POST | `/api/v1/customers/{id}/points/award` | `points:award` | Award points to customer |
| POST | `/api/v1/customers/{id}/points/deduct` | `points:deduct` | Deduct points from customer |

### Response Formats

**Point Balance Response**:
```json
{
  "data": {
    "customer_id": 123,
    "points_balance": 1500,
    "tier": "gold",
    "last_transaction_at": "2025-12-19T10:30:00Z"
  }
}
```

**Transaction List Response**:
```json
{
  "data": [
    {
      "id": 456,
      "type": "earn",
      "points": 100,
      "balance_after": 1500,
      "description": "Purchase #ORD-123",
      "created_at": "2025-12-19T10:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 50
  }
}
```

**Error Response**:
```json
{
  "message": "Insufficient points balance",
  "errors": {
    "points": ["Cannot redeem more points than available balance"]
  }
}
```

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Customers can view their point balance within 1 second of page load.
- **SC-002**: All point transactions are recorded with 100% accuracy (no missing or duplicate records).
- **SC-003**: Third-party API endpoints respond within 200ms for 95% of requests.
- **SC-004**: API authentication correctly rejects 100% of invalid/expired tokens.
- **SC-005**: Point balance calculations are always accurate (balance_after matches sum of transactions).
- **SC-006**: All API endpoints have comprehensive Pest test coverage (>90%).
- **SC-007**: System handles at least 100 concurrent API requests without degradation.
- **SC-008**: All code passes Laravel Pint style checks.

### Definition of Done

- [ ] All P1 user stories implemented and tested
- [ ] All API endpoints documented with examples
- [ ] Pest feature tests cover all acceptance scenarios
- [ ] Database migrations and seeders created
- [ ] API Resources created for consistent JSON responses
- [ ] Form Requests created for all validation
- [ ] Code passes `vendor/bin/pint --dirty`
- [ ] All tests pass with `php artisan test`
