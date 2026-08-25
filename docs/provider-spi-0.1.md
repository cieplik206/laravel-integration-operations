# Provider SPI 0.1

Status: frozen 0.1 contract. RT-1 supplies the installable value-object, registry, cryptography, context, and conformance foundation; durable execution remains deferred to RT-2.

The key words MUST, MUST NOT, REQUIRED, SHOULD, SHOULD NOT, and MAY are to be interpreted as normative requirements.

## Boundary

Provider SPI 0.1 describes how a provider package declares one durable operation to the integration-operations kernel. It is a conformance contract, not executable configuration. A manifest MUST NOT be loaded from a database row or user input and MUST NOT name an executable PHP class.

The kernel owns operation identity, lifecycle, leases, dispatch, retry scheduling, reconciliation scheduling, terminality, and generic queries. A provider owns payload meaning, handler behavior, failure classification, retry safety, reconciliation matching, result codecs, and a required trusted outcome projector. A definition with no local projection supplies an explicit no-op projector.

Provider packages MUST NOT import application models. The kernel MUST NOT import provider or application domain types. Boot and package discovery MUST NOT execute HTTP, database queries, DDL, queue jobs, or migrations.

## Versioning and identity

Every manifest conforms to `contracts/provider-spi-0.1.schema.json` and declares:

- `contract`: `cieplik206.integration-operations.provider-spi`;
- `spi_version`: `0.1`;
- a lowercase provider key;
- one or more provider-qualified operation definitions.

An operation type MUST start with the provider key followed by a dot. The tuple `(provider, operation_type, handler_version)` MUST be unique. Persisted operations identify behavior using provider, operation type, payload schema version, handler version, and result schema version. PHP class names are never persisted.

During a rolling deployment, a worker MUST skip an unsupported definition version without changing the operation to `failed`. The doctor/runtime added in later milestones will surface that incompatibility. Providers SHOULD support the current and immediately previous persisted contract versions while active operations or readable terminal tombstones exist.

## Operation definition

Each definition declares three positive integer versions:

- `payload_schema`: canonical provider payload and its upcast window;
- `handler`: allowlisted boot-time implementation version;
- `result_schema`: encoded terminal result and its decode window.

The required extension points are `operation_handler`, `failure_classifier`, `retry_policy`, `result_codec`, and `outcome_projector`. A single-effect operation also requires `reconciliation_strategy`. The boot-only registry accepts only an explicit self-binding for the referenced final, instantiable concrete class; aliases, custom factories, abstract services, subclasses, and closure metadata are rejected. The frozen registry records the exact class-and-contract tuple. Runtime code MUST compare the resolved object's exact class with that frozen tuple before invoking it or opening an effect boundary, so a container rebind after boot fails closed. The manifest deliberately carries no class or service locator supplied by persistent data.

An outcome projector participates in the short terminal database transaction. It MUST be deterministic and idempotent, use the kernel database connection, and perform no HTTP, queue dispatch, event dispatch, filesystem access, or reads of application models.

## Effect boundary

SPI 0.1 permits only two operation shapes:

| Shape | `max_remote_writes` | Effect boundary | Reconciliation |
| --- | ---: | --- | --- |
| Read-only | 0 | forbidden | none |
| Single-effect | 1 | required immediately before the possible effect | required |

A remote write is one logical, non-transactional effect outside the kernel database. A local outcome projection does not consume this budget. A workflow requiring a second write MUST register another child operation with its own operation identity and terminal result.

For a single-effect operation, the kernel records `possibly_applied` before handing control to the transport. After the boundary is consumed, a second effect-boundary opening or transport attempt under the same operation ID is a contract violation. An ambiguous response MUST enter reconciliation or manual review and MUST NOT cause a blind retry. Only a pre-boundary failure with durable `request_not_started` evidence may return the same operation to pending; no transport has occurred in that case. `absent_conclusive` proves `not_applied` and terminates that operation as failed; any later business-authorized write requires a new generation and operation ID.

## Reconciliation result vocabulary

- `found_exact`: one resource matches the provider locator and stable fingerprint; project and succeed.
- `absent_conclusive`: the visibility window and required observations prove absence; terminalize as `failed` with `not_applied`, without another transport attempt under that operation ID.
- `inconclusive`: observe again without another write.
- `ambiguous_matches`: pause for manual review without another write.

Amount, date, or a display name alone MUST NOT establish `found_exact`.

## Terminal contracts

Kernel dispositions visible to an orchestrator are `in_progress`, `requires_manual_review`, `succeeded`, `failed`, and `cancelled`. Only the last three are terminal. `manual_review` pauses automation but is not terminal.

Every operation definition declares all three terminal contracts:

| Status | Disposition | Effect invariant | Result availability |
| --- | --- | --- | --- |
| `succeeded` | `succeeded` | read-only: `not_started`; single-effect: `applied` | `available` |
| `failed` | `failed` | effect is proven absent: `not_started` or `not_applied` | `not_applicable` |
| `cancelled` | `cancelled` | cancellation happened before the effect: `not_started` | `not_applicable` |

`possibly_applied` is never terminal. A missing result codec or a decode failure changes result availability to `codec_unavailable` or `decode_failed`; it MUST NOT rewrite the historical status or prove that a remote effect is absent.

Terminal status, result envelope, transition audit, and any provider outcome projection are committed atomically. A terminal operation ID never returns to an active state. A corrected intent creates a new generation and operation ID only after a terminal `failed` outcome with proven absence.

## Security

Credentials are resolved only at execution time and MUST NOT appear in manifests, payload fixtures, operation rows, queue jobs, attempt metadata, logs, or metrics. Payloads, contexts, and provider results are versioned JSON envelopes; PHP serialization and executable class names are forbidden. Typed provider results crossing an extension boundary MUST be final readonly value objects whose complete nested object graph is also final readonly and contains only canonical scalar, array, enum, or explicitly immutable time values.

Provider and connection scope isolate every accept and query. Lookup material with low entropy uses a versioned HMAC; plain SHA-256 is reserved for ciphertext or artifact integrity. Metrics use bounded provider/type/status labels and never connection keys, operation IDs, remote IDs, or correlation IDs.

## Conformance fixtures

The repository carries two provider-neutral definitions:

- `fixture_catalog.record.fetch`: zero writes and no reconciliation;
- `fixture_dispatch.message.deliver`: one effect boundary plus reconciliation.

They prove that both permitted shapes can be described without importing Fakturownia, PMS, HTTP clients, database models, or provider runtime code. The fixtures are inputs for contract tests only; they do not register handlers and do not perform I/O.

## Forward constraint for KSeF ownership

SPI 0.1 deliberately does not model a single operation type which sometimes performs the effect and sometimes only observes an effect initiated by the provider. Therefore a ProviderAutoSend KSeF definition MUST NOT be registered against the 0.1 single-effect shape. Before RT-5, a new versioned generic contract must add an explicit success-effect policy equivalent to `must_be_applied_by_operation`, `may_be_observed_externally`, and `read_only`, while retaining the per-operation remote-write budget and fail-closed boundary rules. This is a declared 0.2 extension point, not permission to relax 0.1 at runtime.

## Deferred implementation

SPI 0.1 and the RT-1 foundation do not implement persistence, migrations, dispatch, leases, state transitions, or durable execution. Those components are introduced by RT-2 against this frozen vocabulary. Provider extensions and result codecs are registered and validated at boot, but are instantiated only by the later runtime worker.
