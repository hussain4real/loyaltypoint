# Tasks: Loyalty Point System

**Input**: Design documents from `/specs/001-loyalty-system/`  
**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ ✅

**Tests**: MANDATORY per Constitution Principle II (Comprehensive Testing with Pest)

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

Based on plan.md, this is a Laravel 12 API-only application using standard directory structure.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Project initialization and Sanctum middleware configuration

- [X] T001 Register Sanctum ability middleware aliases in bootstrap/app.php
- [X] T002 [P] Create TransactionType enum in app/Enums/TransactionType.php

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T003 Create point_transactions migration in database/migrations/2025_12_19_200000_create_point_transactions_table.php
- [X] T004 Run migration to create point_transactions table
- [X] T005 [P] Create PointTransaction model in app/Models/PointTransaction.php
- [X] T006 [P] Create PointTransactionFactory in database/factories/PointTransactionFactory.php
- [X] T007 Extend User model with pointTransactions relationship and accessors in app/Models/User.php
- [X] T008 [P] Create PointService for business logic in app/Services/PointService.php
- [X] T009 [P] Create PointBalanceResource in app/Http/Resources/Api/V1/PointBalanceResource.php
- [X] T010 [P] Create PointTransactionResource in app/Http/Resources/Api/V1/PointTransactionResource.php

**Checkpoint**: Foundation ready - user story implementation can now begin

---

## Phase 3: User Story 1 - Customer Earns Points (Priority: P1) 🎯 MVP

**Goal**: Customers can earn points from purchases, creating transaction records

**Independent Test**: Award points via PointService and verify transaction is created with correct balance_after

### Tests for User Story 1 (MANDATORY) ⚠️

- [X] T011 [P] [US1] Create PointServiceTest for earning points in tests/Feature/Services/PointServiceTest.php

### Implementation for User Story 1

- [X] T012 [US1] Implement awardPoints() method in PointService in app/Services/PointService.php
- [X] T013 [US1] Add transaction locking for concurrent safety in PointService

**Checkpoint**: User Story 1 complete - points can be earned and recorded

---

## Phase 4: User Story 2 - Customer Views Point Balance (Priority: P1) 🎯 MVP

**Goal**: Customers can view their current point balance via API

**Independent Test**: Authenticate as customer, call balance endpoint, verify response structure

### Tests for User Story 2 (MANDATORY) ⚠️

- [X] T014 [P] [US2] Create PointControllerTest for balance endpoint in tests/Feature/Api/V1/PointControllerTest.php

### Implementation for User Story 2

- [X] T015 [US2] Create PointController in app/Http/Controllers/Api/V1/PointController.php
- [X] T016 [US2] Implement balance() action in PointController
- [X] T017 [US2] Add customer point routes to routes/api.php (GET /v1/points/balance)

**Checkpoint**: User Story 2 complete - customers can view their balance

---

## Phase 5: User Story 3 - Third-Party Retrieves Points (Priority: P1) 🎯 MVP

**Goal**: Third-party apps can retrieve customer point balance via scoped API token

**Independent Test**: Create token with points:read scope, call customer points endpoint, verify response

### Tests for User Story 3 (MANDATORY) ⚠️

- [X] T018 [P] [US3] Create CustomerPointControllerTest in tests/Feature/Api/V1/CustomerPointControllerTest.php
- [X] T019 [P] [US3] Test 401 for unauthenticated requests
- [X] T020 [P] [US3] Test 403 for token without points:read scope
- [X] T021 [P] [US3] Test 404 for non-existent customer

### Implementation for User Story 3

- [X] T022 [US3] Create CustomerPointController in app/Http/Controllers/Api/V1/CustomerPointController.php
- [X] T023 [US3] Implement show() action for customer balance
- [X] T024 [US3] Add third-party routes with ability middleware to routes/api.php (GET /v1/customers/{customer}/points)

**Checkpoint**: User Story 3 complete - third-party apps can read customer points

---

## Phase 6: User Story 4 - Customer Redeems Points (Priority: P2)

**Goal**: Customers can redeem points with balance validation

**Independent Test**: Award points, then deduct, verify balance decreases and negative balance is rejected

### Tests for User Story 4 (MANDATORY) ⚠️

- [X] T025 [P] [US4] Add redemption tests to PointServiceTest in tests/Feature/Services/PointServiceTest.php
- [X] T026 [P] [US4] Test insufficient balance rejection

### Implementation for User Story 4

- [X] T027 [US4] Implement deductPoints() method in PointService in app/Services/PointService.php
- [X] T028 [US4] Add balance validation to prevent negative balance

**Checkpoint**: User Story 4 complete - points can be redeemed with validation

---

## Phase 7: User Story 5 - Customer Views Transaction History (Priority: P2)

**Goal**: Customers can view paginated transaction history

**Independent Test**: Create multiple transactions, call transactions endpoint, verify pagination

### Tests for User Story 5 (MANDATORY) ⚠️

- [X] T029 [P] [US5] Add transaction history tests to PointControllerTest in tests/Feature/Api/V1/PointControllerTest.php
- [X] T030 [P] [US5] Test pagination and date filtering

### Implementation for User Story 5

- [X] T031 [US5] Implement transactions() action in PointController in app/Http/Controllers/Api/V1/PointController.php
- [X] T032 [US5] Add transactions route to routes/api.php (GET /v1/points/transactions)
- [X] T033 [US5] Add date filtering query parameters support

**Checkpoint**: User Story 5 complete - customers can view transaction history

---

## Phase 8: User Story 6 - Third-Party Retrieves Transactions (Priority: P2)

**Goal**: Third-party apps can retrieve customer transaction history via API

**Independent Test**: Create token with transactions:read scope, call transactions endpoint, verify paginated response

### Tests for User Story 6 (MANDATORY) ⚠️

- [X] T034 [P] [US6] Add transaction history tests to CustomerPointControllerTest in tests/Feature/Api/V1/CustomerPointControllerTest.php
- [X] T035 [P] [US6] Test 403 for token without transactions:read scope

### Implementation for User Story 6

- [X] T036 [US6] Implement transactions() action in CustomerPointController in app/Http/Controllers/Api/V1/CustomerPointController.php
- [X] T037 [US6] Add transactions route with ability middleware to routes/api.php (GET /v1/customers/{customer}/transactions)

**Checkpoint**: User Story 6 complete - third-party apps can read transaction history

---

## Phase 9: User Story 7 - Admin Awards Bonus Points (Priority: P3)

**Goal**: Admins/API can award bonus points to customers

**Independent Test**: Award bonus points via endpoint, verify transaction type is 'bonus' and balance increases

### Tests for User Story 7 (MANDATORY) ⚠️

- [X] T038 [P] [US7] Add bonus award tests to CustomerPointControllerTest in tests/Feature/Api/V1/CustomerPointControllerTest.php

### Implementation for User Story 7

- [X] T039 [US7] Implement awardBonusPoints() in PointService for bonus type transactions in app/Services/PointService.php

**Checkpoint**: User Story 7 complete - bonus points can be awarded

---

## Phase 10: User Story 8 - Third-Party Awards Points (Priority: P3)

**Goal**: Third-party apps can award points to customers via API with points:award scope

**Independent Test**: Create token with points:award scope, POST to award endpoint, verify points credited

### Tests for User Story 8 (MANDATORY) ⚠️

- [X] T040 [P] [US8] Add award endpoint tests to CustomerPointControllerTest in tests/Feature/Api/V1/CustomerPointControllerTest.php
- [X] T041 [P] [US8] Test 403 for token without points:award scope
- [X] T042 [P] [US8] Test validation errors for invalid input

### Implementation for User Story 8

- [X] T043 [P] [US8] Create AwardPointsRequest in app/Http/Requests/Api/V1/AwardPointsRequest.php
- [X] T044 [P] [US8] Create DeductPointsRequest in app/Http/Requests/Api/V1/DeductPointsRequest.php
- [X] T045 [US8] Implement award() action in CustomerPointController in app/Http/Controllers/Api/V1/CustomerPointController.php
- [X] T046 [US8] Implement deduct() action in CustomerPointController in app/Http/Controllers/Api/V1/CustomerPointController.php
- [X] T047 [US8] Add award/deduct routes with ability middleware to routes/api.php

**Checkpoint**: User Story 8 complete - third-party apps can award and deduct points

---

## Phase 11: Point Expiration (Future Enhancement)

**Purpose**: Handle point expiration logic for earned points with expires_at date

**Note**: This phase is DEFERRED. The expires_at column exists in the migration but expiration filtering is not implemented in MVP. Points do not expire in the initial release.

- [ ] T052 [P] [DEFERRED] Add expiration filtering to getPointBalanceAttribute() in User model
- [ ] T053 [P] [DEFERRED] Create points:expire Artisan command for cleanup of expired points
- [ ] T054 [P] [DEFERRED] Add expiration tests to PointServiceTest

---

## Phase 12: Polish & Cross-Cutting Concerns

**Purpose**: Code quality, documentation, and final validation

- [X] T048 [P] Create PointTransactionSeeder in database/seeders/PointTransactionSeeder.php
- [X] T049 Run vendor/bin/pint to fix code style
- [X] T050 Run php artisan test to verify all tests pass
- [X] T051 Validate API against quickstart.md examples

---

## Dependencies & Execution Order

### Phase Dependencies

```
Phase 1 (Setup) ──────────────────────────────────────────────────┐
                                                                   │
Phase 2 (Foundational) ←─────────────────────────────────────────┘
           │
           ├──→ Phase 3 (US1: Earn Points) ─────────────────────┐
           │                                                      │
           ├──→ Phase 4 (US2: View Balance) ←───────────────────┤ MVP
           │                                                      │
           └──→ Phase 5 (US3: Third-Party Read) ←───────────────┘
                      │
                      ├──→ Phase 6 (US4: Redeem Points)
                      │
                      ├──→ Phase 7 (US5: View History)
                      │
                      └──→ Phase 8 (US6: Third-Party History)
                                 │
                                 ├──→ Phase 9 (US7: Admin Bonus)
                                 │
                                 └──→ Phase 10 (US8: Third-Party Award)
                                            │
                                            └──→ Phase 11 (Polish)
```

### User Story Dependencies

| Story | Depends On | Can Parallel With |
|-------|------------|-------------------|
| US1 (Earn) | Foundational | US2, US3 |
| US2 (View Balance) | Foundational | US1, US3 |
| US3 (Third-Party Read) | Foundational | US1, US2 |
| US4 (Redeem) | US1 | US5, US6 |
| US5 (View History) | US1 | US4, US6 |
| US6 (Third-Party History) | US3 | US4, US5 |
| US7 (Admin Bonus) | US1 | US8 |
| US8 (Third-Party Award) | US3, US7 | - |

### Parallel Opportunities

```bash
# Phase 2 parallel tasks:
T005, T006, T008, T009, T010 can run in parallel

# Phase 3-5 (MVP) can start in parallel after Phase 2:
US1, US2, US3 tests and implementation can proceed in parallel

# Phase 6-8 can start in parallel:
US4, US5, US6 can proceed in parallel

# Phase 9-10 can start in parallel:
US7, US8 can proceed in parallel
```

---

## Implementation Strategy

### MVP First (P1 Stories Only)

1. Complete Phase 1: Setup (T001-T002)
2. Complete Phase 2: Foundational (T003-T010)
3. Complete Phase 3: User Story 1 - Earn Points (T011-T013)
4. Complete Phase 4: User Story 2 - View Balance (T014-T017)
5. Complete Phase 5: User Story 3 - Third-Party Read (T018-T024)
6. **STOP and VALIDATE**: All P1 stories complete, MVP ready
7. Run `php artisan test` and `vendor/bin/pint`

### Incremental Delivery

1. **MVP (P1)**: Earn → View Balance → Third-Party Read
2. **P2 Enhancement**: Redeem → View History → Third-Party History
3. **P3 Enhancement**: Admin Bonus → Third-Party Award
4. Each story adds value without breaking previous stories

---

## Notes

- [P] tasks = different files, no dependencies, can run simultaneously
- [Story] label maps task to specific user story for traceability
- Tests MUST be written to FAIL before implementation (TDD)
- All tests use Pest v4 with `RefreshDatabase` and factories
- Commit after each task or logical group
- Run `vendor/bin/pint` before final commit
