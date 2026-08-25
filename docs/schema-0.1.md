# Kernel Schema Specification 0.1

Status: normative blueprint for later migrations. This document does not authorize DDL during package boot.

## General invariants

- The kernel is the sole owner of `integration_*` tables.
- Provider packages own their own tables and reference an operation ID logically, without cross-package foreign keys.
- The application migration pipeline is the only migration executor. Package boot only makes migration files discoverable once they exist.
- IDs are ULIDs stored as 26-character values. Timestamps are UTC with microseconds. Statuses are portable strings, not database enums.
- Lifecycle rows remain narrow. Encrypted payload/context revisions, terminal results, attempts, and transitions live in separate tables.
- Plain SHA-256 protects ciphertext or artifact integrity. Versioned HMAC-SHA-256 protects intent, payload fingerprint, context, and correlation lookup material.
- JSON is decoded only by an allowlisted versioned codec. PHP serialization and executable class names are forbidden.

## Logical tables

### `integration_operation_intents`

One stable effectively-once intent across immutable generations.

Required fields: `id`, `provider`, `connection_key`, `operation_type`, `resource_type`, `semantic_slot`, `current_generation`, `current_operation_id`, active HMAC key version, timestamps, and optional local-reference metadata represented through an allowlisted morph alias.

Constraints and indexes:

- unique active lookup alias for each `(hmac_key_version, intent_key_hmac)`;
- unique `(intent_id, generation)` in operations;
- indexes start with provider and connection for tenant isolation;
- no foreign key to application or provider tables.

### `integration_operation_lookup_keys`

Immutable active/previous HMAC aliases used during key rotation. An old alias remains unique until all writers using that key version are fenced out and backfill/verification succeeds.

Required fields: `intent_id`, `hmac_key_version`, `intent_key_hmac`, `created_at`, and optional retirement timestamp. The digest is never logged.

### `integration_operations`

Hot lifecycle row scanned by dispatchers.

Required fields include `id`, `intent_id`, `generation`, `provider`, `connection_key`, `operation_type`, `status`, `disposition`, `effect_state`, payload/handler/result schema versions, `row_version`, priority, `next_attempt_at`, lease fields, attempt counters, writer generation/owner/cohort fence, request-start marker, terminal timestamp, superseded operation ID, and timestamps.

The row MUST NOT contain payload ciphertext, provider credentials, application models, PHP class names, full errors, or terminal result ciphertext.

Constraints:

- unique `(intent_id, generation)` and unique operation ULID;
- `max_remote_writes` is 0 or 1 for SPI 0.1;
- terminal statuses are exactly `succeeded`, `failed`, and `cancelled`;
- a terminal row cannot return to an active status;
- `failed` requires a proven absent effect (`not_started` or `not_applied`);
- `cancelled` requires `not_started`;
- `possibly_applied` cannot be terminal;
- a succeeded single-effect operation requires `applied`;
- optimistic updates compare `row_version` or hold the row lock.

Dispatcher indexes are provider-scoped partial/composite equivalents for:

- pending/retry candidates ordered by priority, due time, and ID;
- expired processing/reconciliation leases;
- uncertain operations due for reconciliation;
- terminal tombstones by retention deadline.

### `integration_operation_payloads`

Immutable command and context revisions, loaded only after a successful claim.

Required fields: `id`, `operation_id`, `payload_revision`, `payload_ciphertext`, ciphertext SHA-256, payload fingerprint HMAC, HMAC key version, payload schema version, context ciphertext/schema version, optional context/correlation HMAC lookups, actor, and timestamp.

Constraints: unique `(operation_id, payload_revision)`; revisions are append-only; a replacement is allowed only before the first possible effect and must be explicitly audited.

### `integration_operation_results`

At most one immutable terminal result per operation.

Required fields: `operation_id`, `result_type`, `result_ciphertext`, result schema version, ciphertext SHA-256, and `created_at`. The row is written in the same transaction as the terminal transition and provider outcome projection.

No codec/decode problem mutates this stored historical outcome. Query maps it to explicit result availability.

### `integration_operation_attempts`

Append-only evidence for dispatch, execution, transport, reconciliation, and recovery.

Required fields include attempt ULID, operation ID, attempt number/type, safe outcome category, effect state before/after, started/finished timestamps, retry/reconciliation scheduling data, bounded redacted metadata, and actor/worker identity.

Metadata MUST NOT contain credentials, full payloads, full URLs/query strings, response bodies, unredacted headers, customer data, or arbitrary exception messages.

### `integration_operation_transitions`

Append-only lifecycle audit, including transitions which are not HTTP attempts (accept, cancel, operator resolution, supersede, and recovery).

Required fields: transition ULID, operation ID, monotonic sequence, from/to status and disposition, from/to effect state, reason code, actor category/reference, expected/resulting row version, and UTC timestamp.

Constraints: unique `(operation_id, sequence)` and no update/delete in normal runtime.

## Lock order and transaction boundaries

The canonical lock order is intent lookup alias → intent → current operation → related payload/result rows. Code MUST NOT acquire these locks in reverse order.

Accept, claim, transition, terminal projection, and operator resolution use short database transactions. HTTP, filesystem, broker, sleeps, and provider polling occur outside database transactions.

The receipt becomes durable only when the caller's outermost transaction commits. Dispatch and durable-accepted events are after-commit optimizations; the operations table remains the durable dispatch source.

Before a possible write, the executor opens the kernel effect boundary in a short transaction and records `request_started_at` plus `possibly_applied`. A successful provider projection, immutable result, terminal transition, and lifecycle row update share one later transaction.

## Query shapes

- Accept resolves all active HMAC aliases, locks the intent/current generation, and returns the existing operation for an identical fingerprint.
- Dispatcher selects due rows using bounded fair budgets per provider and connection, then claims by row version/lock.
- Recovery selects expired leases without decrypting payloads. Before request start it may return to pending; after request start it enters uncertain.
- Reconciliation selects due uncertain rows and never schedules a write. `absent_conclusive` terminalizes the current operation as `failed/not_applied`; any subsequent write requires an explicit new generation and operation ID.
- Public `find`/`findMany` always require allowed `(provider, connection_key)` scopes and return an explicit missing-ID set.
- Pruning removes raw envelopes/audit according to policy but retains a minimal terminal tombstone for at least the supported orchestrator recovery window.

## Retention and portability

Concrete retention defaults remain deployment configuration, not schema constants. Destructive pruning must be batchable, observable, and preserve intent uniqueness plus the terminal tombstone.

PostgreSQL is the blocking database target. Index names, filtered-index syntax, JSON/blob types, and check constraints will be finalized in RT-2 migration rehearsals. MySQL portability may influence types and indexes; SQLite is never the sole conformance gate.
