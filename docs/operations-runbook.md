# Integration Operations runbook

This runbook covers the provider-neutral durable runtime. Provider credentials,
remote semantics, and business workflow decisions remain in the provider SDK
and consuming application. Queue state is never the source of truth; PostgreSQL
operation state is authoritative.

## Safety rules

- Always provide the exact `provider` and `connection` scope. Never inspect or
  resolve an operation by ID alone.
- Never retry a remote mutation manually. Use the registered reconciliation
  strategy or an audited terminal decision.
- Never edit lifecycle, attempt, payload, result, or transition tables by hand.
- Do not include payloads, remote identities, credentials, or document data in
  tickets, command output, metrics labels, or logs.
- A missing handler or unsupported persisted definition is a deployment
  incompatibility. Keep the operation unchanged and restore compatible code.

## Deployment preflight

Run migrations through the consuming application's normal migration workflow,
then run:

```console
php artisan integration-operations:doctor
php artisan integration-operations:heartbeat
```

The doctor must pass before enabling an accepting writer. The heartbeat must be
scheduled by exactly one logical scheduler path and must not overlap a previous
run. Verify database connectivity, registry compatibility, queue routing,
scheduler freshness, encryption/HMAC key availability, and provider doctor
results before changing owner fences.

## Scoped diagnosis

List one bounded scope and then inspect a single operation:

```console
php artisan integration-operations:list --provider=fakturownia --connection=sales --status=manual_review --limit=50
php artisan integration-operations:show 01J00000000000000000000000 --provider=fakturownia --connection=sales
```

Use safe lifecycle metadata and transition timestamps to distinguish:

- `pending` backlog: verify scheduler heartbeat, queue availability, and writer
  ownership;
- `uncertain`: run registered reconciliation; do not send again;
- `manual_review`: obtain provider-specific evidence before an audited decision;
- `poll_wait` or `polling`: verify provider deadline, next-attempt time, and
  provider health;
- stale processing lease: let the recovery loop classify it; do not clear the
  lease directly.

## Reconciliation and manual review

Reconciliation is allowed only for an uncertain operation with a registered
provider strategy:

```console
php artisan integration-operations:reconcile 01J00000000000000000000000 --provider=fakturownia --connection=sales
```

If evidence remains inconclusive, leave the operation in manual review. Apply a
decision only after recording the evidence in the incident system. Reasons and
failure text must be machine-readable, bounded, and free of customer data:

```console
php artisan integration-operations:resolve 01J00000000000000000000000 reconcile --provider=fakturownia --connection=sales --reason=provider_evidence_available --actor-reference=operator-ticket-123
php artisan integration-operations:resolve 01J00000000000000000000000 fail-permanently --provider=fakturownia --connection=sales --reason=provider_rejected --failure-code=provider_rejected --failure-summary=Provider_rejected_request --actor-reference=operator-ticket-123
```

`cancel` is valid only where the frozen operation contract permits cancellation.
There is intentionally no generic force-success or force-retry command.

## Incident matrix

| Signal | Immediate action | Forbidden action | Recovery evidence |
| --- | --- | --- | --- |
| Scheduler heartbeat stale | Keep new writers closed; restore one scheduler | Starting multiple competing schedulers | Fresh heartbeat and decreasing due backlog |
| Queue outage | Preserve durable operations; restore the allowlisted queue | Recreating operations from application data | Pending age decreases with stable operation IDs |
| Database outage | Stop accepts and execution; restore the authoritative database | Falling back to queue or local files as truth | Doctor passes and leases recover normally |
| Provider failure spike | Keep operations durable; inspect scoped breaker state | Clearing circuit/rate state or blind retry | Provider probe and normal error rate |
| `uncertain` growth | Pause the affected writer scope; reconcile | A second mutation attempt | Exact provider evidence or audited manual review |
| `manual_review` growth | Assign an operator and provider-specific runbook | Bulk terminal decisions | One audited decision per operation |
| Definition mismatch | Restore N or N-1 compatible application code | Marking operations failed | Doctor passes for every persisted active version |

## N/N-1 rolling deployment

The new release must understand every active persisted definition version from
both release N and N-1. A deployment must not change the meaning of an existing
version; add a new version instead.

1. Record the release manifest, package commits/tags, Composer lock hash,
   migration IDs, registry versions, encryption/HMAC key versions, and current
   writer fences.
2. Run package gates and application preflight. Confirm no unsupported active
   operation version and no unresolved schema drift.
3. Apply additive migrations before starting N workers. Do not remove columns,
   indexes, keys, handlers, or codecs used by N-1.
4. Deploy N workers gradually while N-1 remains available. Keep the same
   provider/connection owner generation until both versions pass doctor.
5. Observe pending age, lease recovery, uncertain/manual-review counts, failure
   rate, and provider-specific deadlines through at least one maximum lease and
   remote-call window.
6. Change a writer fence only in a separate, audited cutover step. Operations
   already accepted retain their frozen generation and owner mode.
7. Roll back by restoring the signed N-1 application release and its compatible
   broker/provider package. Never roll back additive database state or mutate
   terminal records.
8. Remove N-1 code, schema, or key support only after its maximum operation,
   audit, and tombstone retention windows have elapsed and a scoped audit finds
   no dependent records.

If N cannot decode or execute an active N-1 operation, stop the rollout and
restore N-1. Do not convert the incompatibility into a business failure.

## Tabletop drill acceptance

A release is operationally ready only when a recorded drill demonstrates:

- queue and scheduler outages preserve durable operations;
- an expired lease recovers without duplicate provider effect;
- a lost response reaches reconciliation/manual review without blind retry;
- a projector rollback leaves no terminal result or duplicate event;
- N and N-1 coexist with active operations and rollback without schema repair;
- dashboards and alerts use bounded provider/connection/type/status dimensions,
  never operation IDs, remote IDs, or correlation IDs as metric labels.

Retention is a separate destructive capability. Until its command and policy
are released and approved, this package does not authorize manual pruning.
