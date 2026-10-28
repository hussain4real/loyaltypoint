# Pull2Spend Phase 1 foundations

| Document control | Value |
| --- | --- |
| Version | 0.6 |
| Date | 26 October 2026 |
| Status | Phase 1 complete. Erastus's acceptance reported by the user. Hosting deferred |
| Accountable security/operations reviewer | Erastus, designated by the user on 26 October 2026. Outcome: accepted, as reported by the user that day |
| Scope | P01-T01 through P01-T09 and P01-A01 through P01-A04 |
| Functional baseline | [Pull2Spend FDD](PULL2SPEND_FDD.md), version 0.3 |
| Delivery baseline | [Phased implementation plan](PULL2SPEND_PHASED_IMPLEMENTATION_PLAN.md), version 0.6 |
| Business source | [Original Pull2Spend BRD](Pull2Spend_BRD_2.docx), unchanged |

## 1. Delivery boundary and evidence

Phase 1 provides contracts, access controls, audit, cases, durable event handling, and operational controls for later financial features. This document separates implemented foundations from future logical design and external operating evidence.

No Phase 1 operation credits a monetary wallet, debits a real partner account, issues a card, or settles funds. The existing V1 exchange remains a legacy local-point service. Its ratio-based implementation is not the valuation model for the new product.

The point owner supplies points quantity and equivalent monetary value. Pull2Spend records that value, currency, references, and validity where supplied. Later fee and incentive calculations use the supplied amount. Pull2Spend does not calculate or configure the partner's point-to-money rules, and this design introduces no FX behavior.

The BRS review supplied on 26 October 2026 permits the FDD to progress with this valuation clarification. It does not approve outstanding licensing, funding, issuer, commercial, or operating decisions.

On 26 October 2026, the user directed that hosting be left out because a different hosting destination will be selected later. The Phase 1 engineering scope includes local/CI validation, provider-neutral configuration contracts, encrypted data handling, diagnostics and operational design. H-01 carries hosted infrastructure, managed-secret integration, deployed alerts, restore evidence and operational activation. Hosting is outside Phase 1 closure. The accountable phase acceptance is recorded in section 11.2.

### 1.1 Recorded prerequisite evidence

- [PR #26](https://github.com/hussain4real/loyaltypoint/pull/26) adds CI for pull requests targeting `dev` and `stage`.
- [PR #27](https://github.com/hussain4real/loyaltypoint/pull/27) adds the immutable `PartnerValueOffer` and its unit tests.
- Both prerequisite PRs are merged into `dev`. The recorded baseline is commit `d263ec5`.
- [Post-merge CI run 40283516262](https://github.com/hussain4real/loyaltypoint/actions/runs/40283516262) passed.
- The prerequisite local suite passed 269 tests with 873 assertions. This count excludes the rest of Phase 1.

The final Phase 1 review must attach its own commit, test results, CI run, security review, and operational evidence. These prerequisite results do not prove that the complete phase passes.

## 2. Installed versions and legacy reuse

The following inventory was read from the current checkout on 26 October 2026. `composer show --direct`, `php --version`, `node --version`, and installed package manifests reproduce it.

| Runtime or direct package | Installed version |
| --- | --- |
| PHP | 8.5.12 |
| Laravel | 13.34.0 |
| Sanctum | 4.3.3 |
| Tinker | 3.0.2 |
| Boost | 2.10.1 |
| Laravel MCP | 1.0.1 |
| Pail | 1.2.7 |
| Pint | 1.32.1 |
| Sail | 1.68.0 |
| Pest | 4.7.8 |
| Pest Laravel plugin | 4.1.0 |
| Faker | 1.24.1 |
| Mockery | 1.6.15 |
| Collision | 8.9.5 |
| Node.js | 24.22.1 |
| npm | 11.19.1 |
| Vite | 7.3.6 |
| Tailwind CSS | 4.3.3 |
| Axios | 1.20.0 |
| Concurrently | 9.2.4 |

The PHP requirement remains `^8.5`, with Composer platform PHP `8.5.0`. Phase 1 does not add a dependency or change infrastructure names.

### 2.1 Reuse decisions

| Existing component | Reuse | Required boundary |
| --- | --- | --- |
| `User` and password hashing | User identity and the first staff authentication factor | Existing consumer registration cannot assign staff roles, scopes, or MFA state |
| Sanctum | Existing V1 consumer and vendor API authentication | A token ability is not a recent staff MFA session or new consumer eligibility |
| `Provider` | Legacy provider catalogue and provider references | Legacy conversion ratios do not enter the new partner offer contract |
| `VendorUserLink` | Historical vendor-to-user associations | A vendor-supplied customer email does not authorize the new consumer process |
| `UserProviderBalance` and `PointTransaction` | Existing local-point balances and histories | No automatic monetary opening balance or point-to-cash migration |
| `PointService`, `PointExchangeService`, and `VendorExchangeService` | Compatibility-preserved V1 behavior | New monetary workflows do not call their conversion calculations |
| Existing OTP email flow | Legacy behavior only | Staff MFA uses its own encrypted TOTP factor, replay protection, and verified session |
| Database queue and failed-job tables | Queue transport | Durable foundation records and database claims own recovery and deduplication |
| Laravel encryption, sessions, and CSRF protection | Encrypted sensitive fields and protected staff forms | Environment-specific keys and secure session settings remain operational prerequisites |
| `PartnerValueOffer` | Exact supplied quantity, amount, currency, and evidence | A value object alone does not establish source authentication, consumer approval, or funding |

The legacy services contain concurrency patterns that require separate remediation before reuse for financial work. Their compatibility tests preserve current behavior, not a new financial safety guarantee. New foundation workers fetch rows through locked queries and use atomic database claims.

### 2.2 Compatibility baseline

`routes/api.php` retains the existing `/api/v1` route groups, authentication endpoints, provider catalogue, customer point operations, exchange preview and execution, transaction history, and vendor operations. Existing feature tests exercise these routes. The Phase 1 callback route occupies `/api/v2/foundation` and does not replace a V1 route.

The compatibility evidence consists of the retained V1 tests, an unchanged legacy route contract, and the complete suite on the final Phase 1 commit. A route list alone does not prove behavior. The authoritative route inventory is `php artisan route:list --path=api/v1 --except-vendor`.

## 3. Architecture decisions

| Decision | Chosen design | Reason and constraint |
| --- | --- | --- |
| New API version | `/api/v2` | Keeps externally verified monetary contracts separate from legacy local-point exchange |
| Valuation authority | Partner-supplied immutable offer | Preserves the business clarification without introducing conversion rules or FX |
| State ownership | Dedicated services for staff access, audit, cases, events, notifications, and approvals | Keeps each invariant with one writer and avoids a generic financial workflow engine |
| Durable work | Persisted event and delivery rows, stable identities, atomic claims, and recovery scheduling | Queue delivery can repeat or stop after a crash |
| Local effects | Handler database changes, audit, and processed state commit in one transaction | A crash before commit leaves no committed partial local effect |
| External effects | Stable operation key, status lookup, and explicit unknown outcome | A timeout does not prove success or failure |
| Audit immutability | Model mutation guards plus SQL triggers | Bulk database writes bypass model events |
| Staff authentication | Password plus RFC 6238 TOTP and session binding | Consumer bearer tokens cannot substitute for staff MFA |
| Evidence storage | Exact terms and opaque evidence references | Raw card data, identity documents, secrets, and arbitrary partner payloads are excluded from audit |
| Active schemas | Only implemented foundation storage | Wallet, card, commercial, and settlement schemas below remain logical designs for later phases |

Two design candidates were compared. Candidate A used dedicated operational models and services. Candidate B used one operation aggregate, typed evidence, and shared work storage. The review scored B 23 of 25 and A 22 of 25, with B stronger on explicit database immutability and worker claims. The synthesis retains A's separate event, notification, case, and access ownership, and adopts B's atomic claims, stale-worker protection, and database enforcement. No generalized event-sourcing framework or arbitrary JSON state machine is introduced.

## 4. Logical data dictionary

This dictionary covers every entity in FDD section 14. Rows marked **future design** are contracts for later implementation, not claims that those tables or workflows exist. Final financial schemas depend on the decisions in section 11.

All monetary amounts cross external boundaries as exact decimal strings with an explicit currency. Floating-point amounts are rejected by canonical idempotency hashing. A later ledger must use an approved precision and rounding policy. No amount changes currency implicitly.

### 4.1 Identity and partner ownership

| Entity and delivery state | Fields and relationships | Keys and invariants | Owner and protection |
| --- | --- | --- | --- |
| User, existing | Contact, password hash, contact verification, linked devices, cases, connections, and one future wallet | Stable internal ID. Client-supplied identity never overrides the authenticated actor | Identity service. Contacts and credentials do not enter operational audit metadata |
| Device, future design | User ID, device-key reference, status, attestation reference, last verification, revoked time | User has many devices. Device binding and revocation are checked before protected actions | Identity and security. Key material remains in managed stores or device secure storage |
| KYC case, future design | User, screening provider, operation reference, risk tier, evidence reference, state, reviewer, decision time | Provider event identity is unique per source. An approved result is linked to authenticated evidence and current eligibility | Compliance. Raw identity documents remain outside audit and normal views |
| Loyalty Partner, future design | Connector identity, environment, supported operations, secret references, supplied-value mappings, limits, settlement terms, status | One partner has many connections, rules, redemption legs, and settlement runs. Unsupported operations fail explicitly | Partnerships and integration engineering. No point-conversion formula or rate field |
| Partner connection, future design; `PartnerConnectionContext` in memory | User ID, partner, connection reference, scoped source, active state, eligibility, wallet currency, token-vault reference | A connection belongs to one authenticated consumer. Membership uniqueness follows OD-13. Partner and account scope remain part of all offer comparisons | Identity and connector services. Tokens are referenced, not exposed |
| Consent, future design | Connection, purpose, scopes, terms version, grant time, revoke time, evidence | A connection has consent history. Revocation prevents new use but preserves existing obligations | Consent service. A current active connection does not erase historical consent evidence |
| Balance snapshot, `PartnerBalanceSnapshot` in memory | Scoped source, nonnegative points, observed time, optional supplied monetary amount and currency, observation reference, distinct quote and transaction references, validity start and end, optional future expiry buckets | Connection has many observations. Zero balance differs from refresh failure. Missing value remains unavailable. Supplied validity bounds must be ordered; their presence does not establish freshness or approval | Connector service. No estimate is derived from points |

### 4.2 Offers, approvals, and redemption

| Entity and delivery state | Fields and relationships | Keys and invariants | Owner and protection |
| --- | --- | --- | --- |
| Partner value offer, `PartnerValueOffer` in memory | Positive integer points, exact nonnegative amount string, currency, scoped source, observation time, optional quote reference, transaction reference, validity start and end | Immutable after construction. Inclusive validity start and exclusive end. Missing validity is unspecified, not proof of freshness | Trusted connector adapter. Source authentication is a separate obligation |
| Authoritative confirmation, `ConfirmedPartnerOffer` in memory | Connection context, immutable offer, confirmation reference, confirmed time, optional confirmation expiry | Connection source matches the offer. Confirmation cannot precede observation. Consumer JSON cannot supply trusted confirmation | Authenticated server adapter. Typed evidence is an adapter assertion, not cryptographic proof by itself |
| Consumer approval, `ConsumerOfferApproval` in memory | Confirmed offer and approval time | Approval binds the authenticated owner, connection, supplied economic terms, references, and validity. Changed accepted terms require renewed approval | Consumer approval service. No acceptance endpoint or persistence is claimed in Phase 1 |
| Redemption quote, future design | User, ordered offer references and snapshots, commercial rule versions, fees, wallet-credit total, currency, expiry, approval reference | User has many quotes. Immutable displayed terms and a stable quote identity bind approval. Currency mismatch blocks execution | Redemption service. Fee model and fee bearer follow approved commercial rules |
| Redemption, future design | User, quote and approval, operation key digest, request fingerprint, state, timestamps, funding and posting references | Unique authenticated user plus operation kind plus client operation key. Reuse with changed request conflicts. Unknown outcomes retain recovery ownership | Redemption orchestration. A success callback alone cannot create spendable value |
| Redemption leg, future design | Parent redemption, partner connection, accepted offer, supplied points and amount, confirmation and approval links, reserve, debit, reversal, and status references | Parent has many legs. External operation references are unique within source. All-or-nothing spendable credit requires confirmed completion or compensation | Connector orchestration. A failed leg never silently discards another leg's obligation |

`OfferApprovalService::approve()` and `assertReady()` exercise ownership, current eligibility, active connection, explicit wallet currency, supplied validity, confirmation timing, and changed-term approval rules. They do not check funding, reserve points, post a wallet credit, or establish KYC policy. Zero monetary values remain representable supplied data but fail readiness with `value_unavailable`. Any further executable minimum remains a separate approved policy.

### 4.3 Accounting, cards, commercial rules, and settlement

| Entity, all future design | Fields and relationships | Keys and accounting invariants | Owner and protection |
| --- | --- | --- | --- |
| Wallet | User, currency, status, opening reference, authoritative balance and hold policy | One wallet per user under the current FDD. No unfunded migration from legacy points. Availability follows approved funding and hold authority | Wallet and ledger services. OD-02 and OD-11 gate implementation |
| Ledger account | Account owner, currency, account class, financial purpose, and status | Ledger entries belong to an account. Consumer liability, partner funding, clearing, fee revenue, and incentive obligations remain distinct | Finance and ledger service. Account definitions require the approved funding model |
| Ledger transaction | Financial reference, type, source obligation, currency, posting time, idempotency key, adjustment reference | One financial event has one balanced posting set. Replayed work cannot post again. Corrections use linked adjustments | Finance and ledger service. Posted transactions are immutable |
| Ledger entry | Transaction, account, debit or credit, exact amount, currency, partner, user, leg reference | Debits equal credits within a transaction and currency. Cross-currency netting requires an explicit future approved design | Ledger service. Derived reporting is not another source of balances |
| Card | Wallet, tokenized issuer reference, lifecycle state, issue operation, replacement history | Wallet has card history. Duplicate issue callbacks cannot create duplicate active cards | Issuer service. PAN and CVV never enter application persistence or audit |
| Authorization hold | Card, issuer authorization reference, amount, currency, state, expiry, capture links | Concurrent holds share one authoritative balance rule. Capture releases the corresponding hold without double debit | Issuer and ledger services. Late capture rules require OD-11 |
| Card event | Issuer source and event ID, tokenized card reference, type, occurred time, merchant reference, operation and posting links | Unique issuer event identity. Reordered and duplicate events preserve financial correctness | Issuer event processing. Only safe normalized fields enter operational views |
| Commercial rule | Rule kind, partner, model, fee bearer, basis, tiers, caps, effective period, currency, rounding policy, version | Platform fee and Merchant Revenue Share are independent rules. Active versions are immutable snapshots | Commercial configuration under finance control |
| Commercial approval | Rule version, maker, independent checker, decision, reason, timestamps | Maker cannot approve their own monetary change. Staff session elevation is not commercial approval | Finance and compliance. Thresholds require OD-15 |
| Revenue allocation | Redemption leg, rule snapshots, supplied amount, platform share, wallet credit, partner incentive, retained revenue | Incentive is distinct from point-funding settlement. Reversals retain original allocations and linked adjustments | Finance and ledger services |
| Settlement run | Partner, timezone, cutoff, period, schema version, status, immutable membership | A transaction cannot belong to overlapping settled membership twice. Funding direction is explicit | Settlement service under approved partner terms |
| Settlement file | Run, version, content reference, checksum, counts and totals, delivery and acknowledgement references | Finalized contents match membership and ledger totals. Delivery success is distinct from payment | Settlement service. Secure storage and delivery references only |
| Settlement line | Run and file, partner leg or adjustment, points, supplied value, fee, wallet credit, separately itemized incentive | Immutable economic membership. Gross and net directions remain explicit | Finance. Point-funding obligations are not reduced to incentive payout |
| Payment evidence | Settlement, bank or partner reference, direction, amount, currency, evidence time | Acknowledgement alone does not settle a receivable or payable. Evidence must reconcile to the obligation | Finance. Bank credentials and raw statements stay outside audit |
| Reconciliation report | Source feed, period, checksum, counts and totals, result, reviewer | Source receipt is reproducible and replay-safe. Daily card reconciliation is a later implementation gate | Finance and operations |
| Reconciliation item | Report, issuer or partner reference, matching ledger entries, difference, timing tolerance, case, adjustment | Every unexplained break has an owned case. Closure requires evidence or an approved linked adjustment | Reconciliation service. No silent balance rewrite |

The worked commercial example remains **10,000 points with a supplied monetary value of QAR 350.00**. At 12%, platform share is QAR 42.00 and deducted-fee wallet credit is QAR 308.00. The incentive is QAR 10.50 at 25% of platform share, leaving QAR 31.50 retained revenue. Actual point-funding settlement obligations remain separate. This example is not approval of funding direction, fee bearer, currency policy, or commercial terms.

### 4.4 Implemented operational records

| Record | Actual storage and invariants |
| --- | --- |
| Staff membership | Unique user ID, role, enabled state, partner and customer scope lists, encrypted TOTP secret, enrollment-token hash, replay counter, MFA confirmation, session binding |
| Staff elevation | Target membership, independent grantor, permission, exact scope, reason, expiry, revocation, and target session binding. Only audit export uses this elevated permission |
| Audit event | Unique ULID reference, nullable system actor or user actor, coded action, target reference, coded reason, partner and customer scope, allowlisted context, creation time. No update timestamp |
| Operational case | Unique category and source reference, public ULID, partner and customer scope, named owner or operations queue, state, due time, resolution code, evidence reference, resolution and closure times |
| Case event | Case ID, actor, prior and next state, coded reason, evidence, owner, creation time. Append-only transition history |
| Foundation event | UUID, unique source plus external event ID, canonical payload hash, encrypted normalized payload, state, attempt count, availability, lease token and expiry, completion, safe error code |
| Foundation operation | Unique source plus external operation reference, observed state and time, last accepted event. This is an observed provider status, not a wallet or financial ledger |
| Foundation delivery | UUID, recipient user, unique deduplication digest, encrypted template and reference, state, attempts, availability, lease, provider receipt, completion, safe error code |
| Simulator delivery receipt | Unique delivery ID and receipt. This proves the local simulator contract only |

`AuditEvent` and `CaseEvent` reject model updates and deletes. SQL triggers reject direct `UPDATE` and `DELETE` on SQLite and MySQL or MariaDB. Other drivers fail migration explicitly. MySQL `TRUNCATE`, trigger removal, and schema changes require restricted application database privileges. Database administrators retain separate controlled maintenance authority.

Audit context accepts only operation, event, case, approval, elevation, evidence, and scope references, state codes, role, permission, owner ID, attempt count, and error code. Raw request bodies, arbitrary before-and-after objects, credentials, contact details, card data, and identity-document payloads are rejected.

## 5. API contracts and ownership

At the Phase 1 baseline, the machine-readable [OpenAPI 3.1 contract](pull2spend-v2.openapi.yaml), version 0.1.0, defined 53 operations: 52 planned consumer operations and the one implemented local/testing callback. Phase 2 updates the same contract; its per-operation status and the phased plan's section 5 record current identity delivery. This document retains the accepted Phase 1 scope. Schema validation does not make planned routes executable or approve policy. Staff HTML forms and internal services are documented in section 7.

### 5.1 Implemented foundation callback

`POST /api/v2/foundation/callbacks/{source}` is a development callback protocol. The source path accepts lowercase letters, digits, and hyphens. The route is rate-limited. Admission is disabled by default.

The current implementation accepts configured simulator sources only when the application environment is `local` or `testing`. It does not claim to implement an unknown partner's signature scheme. A real adapter requires an agreed source contract and separate authenticated verifier before enablement.

The protocol uses these fields:

| Element | Contract |
| --- | --- |
| `X-Callback-Timestamp` | Unix seconds as a decimal string |
| `X-Callback-Signature` | Lowercase hexadecimal HMAC-SHA256 of `timestamp + "." + exact request body`, using the configured source key |
| Key | At least 32 bytes, supplied through protected runtime configuration. The source key is never returned or audited |
| Time window | Absolute timestamp skew at most 300 seconds by default |
| Body limit | 16,384 bytes by default |
| JSON schema | Exactly `event_id`, `operation_reference`, `status`, and `occurred_at` |
| References | One to 128 ASCII letters, digits, underscores, periods, colons, or hyphens |
| Status | `pending`, `unknown`, `succeeded`, `failed`, or `reversed` |
| Event time | ISO 8601 seconds with `Z` or numeric timezone. Invalid dates and excessive future timestamps fail |
| Success | HTTP 202 after durable persistence, independently of worker dispatch |

Example body:

```json
{
	"event_id": "event-123",
	"operation_reference": "operation-456",
	"status": "pending",
	"occurred_at": "2026-10-26T10:00:00Z"
}
```

Example response:

```json
{
	"reference": "7c797758-3b3c-4d7f-921a-13c3ea29d80e",
	"status": "accepted"
}
```

The accepted response acknowledges a persisted event, not financial completion. Repeated source and event ID with the same canonical payload return the original event reference. A changed payload under that identity returns HTTP 409 and appends a safe conflict audit record. JSON object key order does not change the canonical fingerprint.

Actual callback errors use this shape:

```json
{
	"error": {
		"code": "idempotency_conflict",
		"next_action": "retain_original_event"
	}
}
```

| HTTP status | Code | Meaning |
| --- | --- | --- |
| 401 | `callback_rejected` | Key, signature, timestamp, or size validation fails |
| 409 | `idempotency_conflict` | Existing source event identity has different supplied content |
| 422 | `invalid_callback` | Verified body fails the strict schema |
| 503 | `capability_disabled` | New callback admission is disabled |
| 503 | `callback_source_unavailable` | Source or environment has no enabled supported adapter |

A fresh timestamp and matching signature are required even for a duplicate. Event identity supplies durable replay protection inside the accepted time window.

### 5.2 Consumer API design for later delivery

The following paths define the new version boundary. They are **future design** and are not registered money routes in Phase 1.

| Operation | Proposed contract | Ownership and result |
| --- | --- | --- |
| Connection list and lookup | `GET /api/v2/connections` and `GET /api/v2/connections/{reference}` | Authenticated consumer's connections only. Foreign references return safe not-found responses |
| Connect or revoke | `POST /api/v2/connections` and `POST /api/v2/connections/{reference}/revoke` | Actor comes from authentication. Partner-hosted authorization and verified ownership remain required |
| Refresh | `POST /api/v2/connections/{reference}/refresh` | Current consent, connection scope, partner limits, and safe pending outcomes |
| Quote | `POST /api/v2/redemption-quotes` | Owned connection references and selected points. Response includes exact supplied amounts, currencies, validity, commercial versions, and confirmation evidence |
| Accept quote | `POST /api/v2/redemption-quotes/{reference}/approval` | Authenticated consumer accepts displayed immutable terms. Changed terms require a new displayed quote and approval |
| Confirm and query redemption | `POST /api/v2/redemptions` and `GET /api/v2/redemptions/{reference}` | Stable client operation key, current eligibility and confirmation, explicit pending or unknown state |
| Wallet and card | Versioned wallet statement, card lifecycle, and provisioning operations | Funding, issuer, KYC, current ownership, step-up, and stable mutation keys are later gates |
| Consumer case | `POST /api/v2/cases` and `GET /api/v2/cases/{reference}` | Actor owns the linked transaction or support case. Internal notes and partner scope remain protected |

A proposed quote request identifies selected points, not a calculated rate:

```json
{
	"connections": [{"reference": "connection-123", "points": 10000}]
}
```

The server obtains the authoritative partner response. Displayed terms include `points: 10000`, `amount: "350.00"`, `currency: "QAR"`, scoped source, observation time, and references and validity where supplied. The client cannot send `confirmed: true`, a different user ID, a partner conversion rate, or a wallet-credit amount as authority.

Consumer mutations use a stable key scoped to authenticated actor and operation kind. `FoundationIdempotency::key()` accepts eight to 128 ASCII letters, digits, underscores, periods, colons, or hyphens, and returns a digest rather than a raw key. `fingerprint()` canonicalizes object key order and rejects floats. Persisted request deduplication for future consumer mutations remains part of the route's implementation, not a claim made by this helper alone.

The full future error contract distinguishes authentication, forbidden access, safe not-found, KYC or eligibility required, revoked connection, unavailable supplied value, expired quote, unconfirmed offer, changed terms, currency mismatch, insufficient points or funds, limit exceeded, idempotency conflict, unavailable partner, and pending or unknown outcome. Each response includes a safe code, public operation reference when one exists, and next action. Internal exceptions and raw partner responses remain private.

## 6. Integration contracts and approval binding

| Boundary | Actual code contract | Recovery and enablement requirements |
| --- | --- | --- |
| Loyalty Partner | `LoyaltyConnector`: capabilities, authorize, revoke, read balance, request offer, confirm terms, reserve, confirm debit, release, reverse, operation status | Stable operation keys and source-scoped references. Reserve, debit, status, and guaranteed reversal semantics need partner evidence before redemption enablement |
| Issuer | `IssuerGateway`: enroll, issue, reveal, change card state, provision device wallet, fund, balance, card event, dispute, daily feed, operation status | Tokenized references only. Funding and hold authority, late capture, refund, dispute, and certification rules need issuer agreement |
| Screening | `ScreeningGateway`: submit, screening status, reverify, operation status | Authenticated evidence for KYC, sanctions, PEP, and adverse-media review. No raw identity documents in normal contract results |
| Settlement | `SettlementGateway`: deliver immutable file reference and checksum, acknowledgement, payment evidence, operation status | Explicit currency and payment direction. Delivery and acknowledgement never substitute for funding or payment evidence |
| Notification | `NotificationGateway`: stable delivery identity, send, status, idempotency capability | Unknown acknowledgement stays unknown. A provider without idempotency cannot be blindly retried after an uncertain send |

`IntegrationCapabilities` contains explicit `IntegrationOperation` enum values. Its separate `guaranteedReversal` flag defaults to false and requires reversal and authoritative status operations when true. Endpoint availability alone does not establish a guaranteed reversal. A missing capability throws `UnsupportedIntegrationOperation`; the simulator does not fabricate success. `IntegrationResult` holds a stable operation reference, status, and optional typed offer, confirmation, money, evidence, and artifact references. Its statuses are `Pending`, `Unknown`, `Succeeded`, and `Rejected`. Callback observation states are a separate contract and also include reversal.

`SimulatedPartnerConnector` and `SimulatedIntegrationGateway` read explicit typed fixtures through `IntegrationFixtureStore`. Fixture keys are operation name plus subject reference. Repeating the same scoped operation key and terms returns the original result. Changed terms conflict. Unknown status stays unknown. These fixtures are in-memory contract tests and cannot supply production recovery or certified partner capability.

`PartnerConnectionContext` comes from trusted current ownership and eligibility data, not consumer request fields. `OfferApprovalService` rejects a changed owner or connection, revoked connection, missing eligibility, zero supplied monetary value, missing wallet-currency policy, currency mismatch, future observation or confirmation, expired supplied terms, expired confirmation, and invalid approval timing. Changed quantity, amount, currency, source, quote reference, transaction reference, or validity requires renewed approval.

The relevant safe domain codes are `ownership_mismatch`, `connection_revoked`, `eligibility_required`, `value_unavailable`, `currency_policy_required`, `currency_mismatch`, `offer_unconfirmed`, `offer_expired`, `approval_invalid`, and `value_changed_reapproval_required`. These are service contracts. They are not proof that the future consumer HTTP endpoints exist.

For every real adapter, certification evidence must identify the vendor and environment, callback authentication, ownership binding, operation-key scope, pending and unknown states, query authority, reversal guarantees, time limits, rate limits, credential rotation, and escalation owner. Generic simulator HMAC is not a substitute for the vendor's actual protocol.

## 7. Staff access, audit, and cases

### 7.1 Staff authentication and permissions

The `/backoffice` shell uses web sessions, CSRF-protected forms, password authentication, and TOTP. New admissions are disabled by default. Login and challenge routes have rate limits. Headers prevent caching of protected pages and apply a restrictive content security policy.

TOTP secrets are encrypted and hidden from model serialization. Enrollment requires a password session and a one-time invitation. The database stores the invitation hash. An enrolled factor cannot be replaced through the web enrollment path. The last accepted TOTP counter prevents replay.

A successful challenge rotates the session and binds MFA to the user, current session ID, membership state, and verification time. The default MFA lifetime is 30 minutes. Password verification for factor operations expires after ten minutes. Every access service call checks the current MFA session, not only route middleware.

| Role | Ordinary permissions |
| --- | --- |
| Operations | Dashboard, scoped case read and management, scoped audit read, independent elevation management |
| Compliance | Same foundation permissions as Operations |
| Finance | Dashboard, scoped case read and management, scoped audit read |
| Support | Dashboard, scoped case read and management |
| Partner viewer | Dashboard and scoped case read only |

Partner scope and customer scope intersect. `null` represents an explicit unrestricted grant for a permitted non-partner role. An empty list grants no records. Partner viewers require an explicit partner list and cannot hold global scope. List queries apply scope before pagination. Detail and mutation services recheck the persisted target scope.

Only `audit.export` uses temporary elevation in this foundation. The grantor is an independent Operations or Compliance user. The grant matches the target session and exact partner and customer scope, expires within 30 minutes, and can be revoked. Self-grant is denied. This permission does not authorize a financial adjustment or replace maker-checker commercial approval.

### 7.2 Provisioning and factor reset

`staff:provision {email} {role}` provisions an existing user with `--partner`, `--customer`, `--global`, and `--reason` options. The command requires a new membership and valid scope. A partner-only grant covers all customers within that partner scope. A customer-only grant covers the named customers across partner scope. Supplying both lists uses their intersection. Omitting both lists grants no records unless `--global` is explicit. It emits one invitation token to the trusted operator's terminal. The application audits the coded grant and role without logging the token. Private delivery and operator terminal access are operational responsibilities.

`staff:reset-mfa {email} {operator}` requires an independent enabled, enrolled, globally scoped Operations or Compliance operator. The operator supplies a hidden password and TOTP challenge. Reset revokes the target's existing session binding and elevations. Invitation delivery, reset evidence, staff approval, and operator identity remain part of the access-management procedure.

There is no public staff registration or consumer route that grants roles. Shared staff accounts are excluded from the operating model.

### 7.3 Cases and masked operational views

Cases cover KYC, redemption, card, complaint, dispute, connector, reconciliation, security, integration, and notification categories. A repeated category and source reference reuses the same case. A conflicting partner or customer scope fails.

States are `open`, `assigned`, `in_progress`, `awaiting_external`, `resolved`, and `closed`. New system exceptions enter the operations queue. Assignment requires an enabled staff member whose management permission and scopes cover the case. Workflow progress requires a named eligible owner. Resolution and closure require evidence, and closed cases cannot silently reopen. Reopening a resolved case clears its current resolution but preserves the previous evidence in immutable history.

Every case has a due time. The current 24-hour default is a provisional technical setting, not an approved business SLA. The clock continues while a case awaits assignment or external evidence. Resolved and closed cases stop the overdue indicator.

Operational views expose public references, state, category, masked customer and partner references, ownership, due time, and safe evidence references. Audit reads, exports, case views, and permitted changes record actor, action, target, and coded reason. No raw card or identity data appears in those records.

## 8. Durable work and recovery

### 8.1 Inbound work

A verified callback is persisted before HTTP 202. Worker dispatch is an accelerator, not the record of an obligation. Source and external event identity enforce deduplication. A canonical payload digest detects conflicting reuse. SHA-256 exact-identity keys preserve case-distinct opaque event and operation references across SQLite and MySQL collations.

`FoundationClaims` atomically claims due pending work or expired processing leases, increments attempts, and writes a new lease token. A stale worker cannot complete a replacement worker's claim. Handler database changes, operation observation, audit, and processed state commit together. Duplicate acknowledgement after commit produces no second local effect.

Operation observations normalize to UTC before comparing or persisting their projected instant. The projection stores that normalized date spelling with an immutable date cast, avoiding database TIMESTAMP range and timezone conversion limits; the encrypted source payload retains the original supplied timestamp. Operation observations respect event time. Older events cannot overwrite newer state. An incompatible terminal transition creates an integration case rather than silently changing the terminal result. None of these observations posts money.

Default policy allows five attempts with delays of 5, 30, 120, and 600 seconds. Jobs have one transport attempt and a 30-second timeout. Durable rows own retry counts. The default lease is 60 seconds and queue reservation retry is 90 seconds. The required order is worker timeout shorter than lease, and lease shorter than queue reservation retry.

### 8.2 Independent notification work

`FoundationNotifications::enqueue()` persists a delivery keyed by recipient, supported template, and reference. Payloads contain the template and safe reference, not raw financial or identity data. Supported templates are `operation_status`, `security_event`, and `case_update`.

Delivery uses its own queue, attempts, state, provider reference, and exception case. Failed notification delivery cannot undo or repeat source financial work. Known unavailable delivery can retry within the configured bound. Uncertain delivery remains `unknown`; bounded recovery queries status before any resend. A provider without idempotency is never resent an uncertain delivery without authoritative no-send evidence; unresolved cases stay visible after the retry bound. Exactly-once delivery to arbitrary external mail or messaging providers is not claimed.

### 8.3 Recovery procedure

1. Check `foundation:health` for configuration failures, pending age, active leases, failed work, unknown deliveries, and open cases.
2. If admission is unsafe, disable new callback or back-office admission. Keep committed event and notification processing active.
3. Run `foundation:recover` through the approved scheduler. The command redispatches due persisted work and expired claims in bounded batches.
4. For exhausted events, inspect the linked integration case and authoritative provider status before any replay decision.
5. For unknown notification delivery, obtain the provider receipt or definitive no-send evidence. Preserve the original delivery identity.
6. Record the operator, evidence reference, case resolution, and any approved adjustment. Never edit audit history or a financial balance to make a discrepancy disappear.

The event queue is the configured `DB_QUEUE`; notification delivery uses the same name with `.notifications` appended. For the staging template, supervised worker commands are:

```sh
php artisan queue:work database --queue=pull2spend_staging_foundation --timeout=30 --tries=1
php artisan queue:work database --queue=pull2spend_staging_foundation.notifications --timeout=30 --tries=1
```

Run `php artisan schedule:run` once per minute through the environment's approved scheduler. Distinct worker processes isolate notification latency from event processing. Database row claims remain authoritative even if overlapping scheduler runs enqueue duplicates.

Recovery ignores new-admission flags. A disabled new capability therefore does not discard accepted obligations. Scheduler and worker credentials, process supervision, and alert routing require environment evidence.

## 9. Environment isolation and production resilience

### 9.1 Current controls and deferred hosting evidence

The new development and staging example environment files contain placeholders, distinct namespaces, protected sessions, private database names, durable queue settings, and disabled new adapters. The pre-existing production template and cloud resources retain their names. They contain no usable managed-store credentials or real cloud resource identity.

`FoundationEnvironment::problems()` returns safe diagnostic codes for missing namespace, unsafe timeout order, debug exposure, simulator admission outside `local` or `testing`, unprotected sessions, missing secret-store, backup or alert-owner references, unnamespaced cache, cookie or default queue, unsupported nondurable queue use outside local tests, invalid attempt/backoff settings, and unsafe callback limits. Only the database queue is currently supported outside local tests. These checks validate configuration shape. They cannot prove that two configured databases, storage locations, or secrets are actually separate.

The following evidence belongs to H-01 after hosting is selected:

| Control | Required evidence before hosted use under H-01 |
| --- | --- |
| Database isolation | Development and staging resource IDs, endpoints, separate principals, network policy, and a test proving one principal cannot read the other environment |
| Storage isolation | Distinct private bucket or root, encryption, access policy, and denied cross-environment read evidence |
| Secrets and keys | Managed-store resource references, separate application keys, least-privilege runtime access, rotation procedure, and redacted retrieval evidence |
| Queue and cache isolation | Separate backing resource or verified namespace, worker queue configuration, cache lock scope, and cross-environment exclusion test |
| Session isolation | Distinct host and cookie names, secure encrypted sessions, and no cross-environment authentication reuse |
| Monitoring | Named operations owner, approved destination, safe diagnostic output, alert delivery test, and escalation record |
| Backups | Encrypted backup policy, retention, restore evidence, key recovery authority, and environment-specific access |

The hosting destination is deferred by the user. Actual host, database, managed-store, alert-destination and operator records are required for H-01 before hosted use. Their absence does not block the current Phase 1 engineering scope. The existing templates and guards remain available for that later work.

### 9.2 Monitoring contract

`foundation:health` reports configuration problem codes, event and delivery counts by pending, processing, failed, and unknown state, oldest active work timestamps, and open case count. It exits unsuccessfully when configuration checks fail. Its output excludes secret values and raw payloads.

The production monitoring design adds worker liveness, scheduler liveness, oldest due work age, expired leases, attempt exhaustion, callback rejection rate, conflicting event identity, notification unknown state, case age, and database and backup health. Alert thresholds and destinations need operations approval. Logs carry public references and safe codes only. A metrics endpoint or command result does not establish that alerts reach an on-call owner.

### 9.3 Backup and restore design

The production design uses a managed database with approved multi-zone availability, encrypted backups, and point-in-time recovery. Private object storage retains immutable settlement and evidence artifacts under an approved retention policy. Application keys remain recoverable through controlled managed-store access separate from database backup readers.

The BRD targets of RTO at most four hours and RPO at most 15 minutes remain provisional until OD-16 review. Backup frequency, transaction-log retention, failover topology, region, residency, and retention require named approval. No restore or sustained availability result is claimed here.

The restore procedure is:

1. Record the incident, last reliable checkpoint, affected environment, and recovery authority. Disable new financial admissions when later financial features exist.
2. Restore database and object versions into an isolated environment with the correct managed keys. Keep external delivery disabled during integrity checks.
3. Verify migrations, immutable audit and case history, event identities, persisted leases, and artifact checksums. Compare counts and totals with the last trusted evidence.
4. Recover due work with its original operation identities. Query authoritative external status for uncertain effects before retrying a mutation.
5. For later financial phases, reconstruct wallet, hold, ledger, partner-funding, issuer, and settlement obligations. Reconcile them against source feeds and payment evidence.
6. Record measured data loss and elapsed recovery time, unexplained breaks, accountable reviewers, and the decision to resume service. Retain cases for unresolved outcomes.

A backup job success is not restore evidence. A restore is not complete while external obligations remain unexplained.

## 10. Verification and CI evidence

The CI workflow runs for PRs targeting `dev` and `stage`, and pushes to those branches. It uses read-only repository permissions, pinned action commits, bounded jobs, Composer validation, PHP platform checks, Pint, the test suite, the frontend build, OpenAPI 3.1 schema validation, and a MySQL 8.4 foundation job with real concurrent worker checks.

The verification contract includes:

| Concern | Required evidence |
| --- | --- |
| Partner values and approval | Exact supplied strings, large-value precision, optional evidence, validity boundaries, changed terms, current ownership, revocation, eligibility, confirmation, and currency mismatch tests |
| Staff access | Password and TOTP paths, invitation requirements, replay denial, rate limits, session expiry and binding, disabled membership, role permissions, list and target scope, independent elevation, reset revocation |
| Audit and cases | Sensitive context rejection, masking, actor and reason, direct model and SQL mutation denial, deduplicated cases, owner scope, due time, six states, and closure evidence |
| Inbound events | Invalid signature, stale timestamp, strict schema, conflicting identity, duplicate and reordered events, rollback, interrupted processing, expired lease, and bounded exhaustion |
| Notifications | Stable deduplication key, known failure, unknown acknowledgement, no blind unsafe resend, source-state isolation, and failure case |
| Concurrency | Separate processes against a shared file-backed SQLite database for duplicate admission and worker claims. Sequential duplicate calls do not replace race evidence |
| Environment controls | Fail-closed diagnostics, simulator exclusion, secure sessions, distinct namespaces, and external resource evidence in section 9 |
| Compatibility and pause | Retained V1 tests pass. Disabled admission does not prevent processing or recovery of committed work |

SQLite proves the exercised SQLite transaction and uniqueness behavior. It does not prove MySQL row locks or production topology. The added MySQL 8.4 CI job exercises the complete suite and separate-process concurrency. Its run must pass on the reviewed head before MySQL verification is recorded; local Docker was unavailable. The intended hosted database and its runtime privileges still require operating evidence. PostgreSQL is not supported by the audit-immutability migration.

Canonical checks for a final commit are `composer validate --strict`, `composer check-platform-reqs`, `vendor/bin/pint --dirty --format agent`, `php artisan test --compact`, `npm run build`, and `git diff --check`. Final evidence must name the exact tested head and CI run. Tests, independent review, merge, operating acceptance, and deployment remain separate outcomes.

## 11. Decisions and phase review register

| Decision | Proposed accountable owner | Current status and affected work |
| --- | --- | --- |
| OD-01 product name | Product owner | Pull2Spend confirmed in the revised baseline |
| OD-02 licensed program, safeguarding, issuer, and cash backing | Product owner, legal, compliance, finance, issuer | Pending external approval. No wallet or card execution |
| OD-03 operating currency and markets | Product owner and finance | Pending. Currency mismatch is rejected. No FX assumption |
| OD-04 and OD-09 pilot partner capabilities and safe recovery | Partnerships and engineering | Pending partner evidence. Simulators do not certify capabilities |
| OD-06 through OD-08 fee bearer, payout basis, tiers, rounding, and FX policy | Finance and commercial owners | Pending. Logical rules preserve supported models without choosing terms |
| OD-10 eligibility and risk policy | Compliance and product | Pending. The foundation consumes trusted current eligibility rather than implementing an invented policy |
| OD-11 and OD-12 issuer authority and mobile-wallet support | Issuer, finance, engineering, and product | Pending. Interfaces do not establish live issuer readiness |
| OD-13 membership uniqueness | Product, partners, and engineering | Pending. Future connection constraints require a recorded decision |
| OD-15 case SLAs, privileged actions, and approval thresholds | Operations, finance, and compliance | Pending. A 24-hour case setting and staff session lifetimes are provisional engineering defaults |
| OD-16 retention, residency, recovery, and service targets | Compliance, operations, and engineering | Pending. Managed-resource, backup, restore, and review evidence required |
| H-01 hosting readiness | Engineering, security, and operations after target selection | Deferred by user instruction. Required before hosted use. No cloud project selected or approved |

### 11.1 Task completion matrix

| ID | Foundation artifact | Engineering status | Acceptance and later gates |
| --- | --- | --- | --- |
| P01-T01 | Version inventory and reuse and compatibility baseline, section 2 | Engineering verified | Accepted within the Phase 1 outcome in section 11.2 |
| P01-T02 | Full logical dictionary, section 4, immutable offer and approval contracts | Engineering reviewed | Accepted within Phase 1. Dependent financial decisions remain required at the milestones in the plan |
| P01-T03 | Explicit V2 boundary, actual callback and future consumer contracts, section 5 | Engineering checks passed | Accepted within the Phase 1 outcome in section 11.2 |
| P01-T04 | Typed partner, issuer, screening, settlement, and notification contracts, section 6 | Engineering reviewed. Simulators verified | Accepted within Phase 1. Provider-specific evidence remains required before enablement |
| P01-T05 | Staff shell, MFA, roles, scopes, and elevation, section 7 | Implemented. CI passed | Accepted within Phase 1. Hosted bootstrap authority remains part of H-01 |
| P01-T06 | Append-only audit, masked views, cases, and SLA state, sections 4 and 7 | Implemented. SQLite and MySQL CI passed | Accepted within Phase 1. Live SLA and retention decisions remain OD-15/OD-16 |
| P01-T07 | Durable inbox, independent delivery, claims, and recovery, section 8 | Implemented. Failure and concurrency checks passed | Accepted within the Phase 1 outcome in section 11.2 |
| P01-T08 | Provider-neutral templates, encrypted data handling, local/CI isolation, diagnostics, monitoring and resilience/restore design, section 9 | Engineering scope verified | Accepted within Phase 1. Hosted resources and managed-store integration belong to H-01 |
| P01-T09 | CI, typed simulators, fixtures, and failure and concurrency conventions, section 10 | Engineering verified | Accepted within the Phase 1 outcome in section 11.2 |

Engineering owner for P01-T01 through P01-T09: Codex in this repository task. Independent engineering review was provided by the Codex access, event/contract and audit/case reviewers, with PR feedback resolved in code. The engineering completion date is 26 October 2026. Evidence is recorded in section 12 and PR #28. These records assign no production operating role and grant no legal or compliance approval.

On 26 October 2026, the user designated Erastus as the accountable Phase 1 security/operations reviewer and subsequently reported his outcome as "accepted". His review covers the current Phase 1 deliverables and P01-A01 through P01-A04, with hosting excluded under H-01.

### 11.2 Acceptance review

| Criterion | Evidence to inspect | Status |
| --- | --- | --- |
| P01-A01 | V2 contract examples, confirmation and approval guard tests, safe errors, and declared external capabilities | Local/CI checks passed. Accepted within Erastus's reported Phase 1 outcome |
| P01-A02 | MFA, role, scope, elevation, masking, and append-only audit tests | Local/CI checks passed. Accepted within Erastus's reported Phase 1 outcome |
| P01-A03 | Duplicate, interrupted, concurrent, and notification-failure tests plus recovery evidence | Local/CI checks passed. Accepted within Erastus's reported Phase 1 outcome |
| P01-A04 | V1 compatibility suite and admission-disabled recovery tests | Local/CI checks passed. Accepted within Erastus's reported Phase 1 outcome |

| Review record | Evidence |
| --- | --- |
| Reviewer and outcome | Erastus: accepted, as directly reported by the user |
| Record date and source | 26 October 2026. The user answered "accepted" to the Phase 1 review-outcome question in this repository chat. No separate reviewer attachment or decision date was supplied |
| Reviewed baseline | Phase 1 implementation in merged PR #28, reviewer assignment in merged PR #29, and foundations/plan v0.4. Version 0.5 records closure without changing application behavior |
| Scope and evidence | P01-T01 through P01-T09 and P01-A01 through P01-A04. Implementation, independent engineering reviews, resolved findings and passing CI are recorded in section 12 |
| Closure and remaining gates | Phase 1 complete. Hosting remains H-01. Legal, funding, issuer, commercial and live operating decisions remain at their existing later milestones. No production activation is authorized |

Phase 1 closes only when its deliverables exist, its acceptance criteria pass, relevant entry decisions have recorded outcomes, and accountable reviewers record acceptance. This document and green CI alone cannot close the phase. The phased implementation plan remains the task baseline; no task is completed merely because this reference exists.

## 12. Engineering verification record

The local repository implementation passed 521 tests with 1,677 assertions, including the retained 269-test prerequisite baseline. It also passed Pint, strict Composer validation, PHP platform checks, the frontend build, workflow lint, and OpenAPI 3.1 validation. The final CI and commit references are recorded in the implementation PR; this local record does not replace them.

Independent implementation review covered the staff module, supplied-value contracts and durable pipeline. Review identified and corrected configured queue-name bypass, case-insensitive opaque-ID collisions, zero-value readiness, snapshot metadata retention, safe case-form errors, input flashing and staff lookup denials. These are engineering reviews by Codex agents, not accountable business/security/operations acceptance.

The 26 existing application method/path/action mappings were retained. The original BRD SHA-256 remains `c012a8c7e6fc409b28cba39a56051f7920bc5e003d45493e63832cb1b570acec`.

The revised plan checks Phase 1 engineering tasks and acceptance scenarios against the implemented code, reviews and CI. The user-reported acceptance in section 11.2 closes Phase 1. Hosting records in section 9 belong to the user-deferred H-01 gate. Other implementation phases remain unchecked.

### 12.1 CI correction history and environment discovery

The initial [Phase 1 CI run 40287759095](https://github.com/hussain4real/loyaltypoint/actions/runs/40287759095) passed PHP/SQLite, frontend and API-contract checks. MySQL reported one test-fixture failure: the year-2603 RFC 6238 vector attempted to create legacy user TIMESTAMP fields outside MySQL's range. The native algorithm is now isolated in `StaffTotp`; all six RFC vectors remain covered without future-dated database records, while real session, replay and persistence checks remain in the feature suite. The corrected code head `8c112fd82097af677846d10950cb7d83fdd2fc5f` passed a separate MySQL run. The initial failure remains part of the record.

Repository environment metadata returned no configured GitHub environments. A read-only Cloud Run inventory request against the legacy project named in repository deployment documentation was denied or the project could not be found. The current CLI default belongs to another project and was left unchanged. This supplies no evidence of Pull2Spend development/staging isolation. That discovery record is historical. The subsequent user instruction defers hosting to H-01, where the selected destination and operating access will be recorded.

PR review also identified two workflow gaps. An expired password challenge now returns to sign-in instead of cycling through MFA. A resolved case may be reassigned to a newly eligible owner when its former owner loses access; current resolution fields reset while immutable evidence history remains. Both paths have regression coverage. These corrections are included in the subsequent reviewed head rather than hidden by rerunning the original failed head.

### 12.2 Verified code baseline and remaining operating evidence

Code commit [`8c112fd`](https://github.com/hussain4real/loyaltypoint/commit/8c112fd82097af677846d10950cb7d83fdd2fc5f) passed all four jobs in [CI run 40288559281](https://github.com/hussain4real/loyaltypoint/actions/runs/40288559281):

| Check | Observed result |
| --- | --- |
| PHP with SQLite | Passed |
| MySQL foundations | 521 tests passed with 1,677 assertions |
| Separate MySQL concurrent-worker test | One test passed with 15 assertions |
| Frontend build | Passed |
| OpenAPI 3.1 contracts | Passed |

Independent review of that code commit found no blocking findings. Both PR review threads are resolved. [PR #28](https://github.com/hussain4real/loyaltypoint/pull/28) merged into `dev` on 26 October 2026 as `d25c9ee08f012e43e41987a52b4124befbd1300a`. All four jobs passed in [post-merge CI run 40293078614](https://github.com/hussain4real/loyaltypoint/actions/runs/40293078614), including the full SQLite/MySQL suite and separate MySQL concurrent-worker check. These results prove the tested engineering behavior. They do not establish hosted environment isolation or operating acceptance.

The current cloud account can see an active Firebase-labelled project named `wafaa`, with ID `wafaa-68098`. Cloud Run is disabled in that project. No repository configuration identifies that project as the approved Pull2Spend target. No API was enabled and no resource was created or renamed during discovery.

The user subsequently directed that hosting be left out of the current phase. The historical project discovery does not select a target. H-01 will record the later hosting destination, access, secret-store integration and operating owners. The current Phase 1 engineering scope is verified and accepted through the user-reported outcome in section 11.2.

### 12.3 Revision history

| Version | Date | Change |
| --- | --- | --- |
| 0.1 | 26 October 2026 | Added the logical model, contracts, operational design and initial engineering evidence |
| 0.2 | 26 October 2026 | Recorded the green corrected code baseline, resolved review findings and the pending user-supplied environment decision |
| 0.3 | 26 October 2026 | Apply the user-directed hosting deferral. Verify revised P01-T08 repository deliverables and move hosted environment, managed-secret and activation evidence to H-01. Keep phase acceptance distinct from engineering verification |
| 0.4 | 26 October 2026 | Record Erastus's user-designated security/operations review ownership and the merge into dev with passing post-merge CI. Preserve the pending review outcome and hosting deferral |
| 0.5 | 26 October 2026 | Record Erastus's acceptance as directly reported by the user and close Phase 1. Preserve the hosting deferral, future decision gates and unchanged implementation |
| 0.6 | 26 October 2026 | Clarify the historical Phase 1 API baseline as Phase 2 updates the shared OpenAPI contract and implementation plan. Phase 1 acceptance and hosting deferral remain unchanged |
