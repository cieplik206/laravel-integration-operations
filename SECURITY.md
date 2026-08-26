# Security policy

## Reporting a vulnerability

Do not disclose vulnerabilities in public issues. Use GitHub private
vulnerability reporting:

https://github.com/cieplik206/laravel-integration-operations/security/advisories/new

Never include application payloads, credentials, encryption keys, or production
operation data in a report.

## Secret handling

Provider credentials must be resolved at execution time. They must never be
stored in operation definitions, payloads, contexts, queue messages, logs, or
metrics. The lookup HMAC key ring is deliberately independent from Laravel's
`APP_KEY`; use a secret manager and keys containing at least 32 random bytes.
Its public contract exposes only versioned HMAC computation, never plaintext
key extraction. Key material is held in redacted, non-serializable containers.

`IntegrationContext` rejects common secret and PII key names, limits values to
small scalar identifiers, and caps the encoded envelope at 4 KiB by default.
This validation is a safety boundary, not permission to store personal data.

`SafeOperationFailure` summaries are short, printable, single-line, redacted
texts selected by provider code. Never pass through an exception message, HTTP
response body, remote payload, credential, or provider diagnostic verbatim.

Only trusted provider code may register service references during application
boot. Never hydrate definitions or executable class names from a database,
request, queue payload, or other persisted data. The registry freezes after all
service providers have booted. Freeze inspects explicit container binding
metadata and static codec metadata without constructing provider services;
provider credentials remain execution-time only. Only unresolved exact
singleton self-bindings of final concrete classes without class-level container
hooks or custom self-building factories are trusted. The registry owns runtime
construction and instance identity; external resolution, replacement, or a
post-boot container rebind fails closed.

Encoded terminal results are self-describing by result type and schema version.
Only a codec from the frozen registry may decode them. Missing or failing codecs
must preserve the immutable encoded envelope and surface an explicit
`codec_unavailable` or `decode_failed` availability. Typed results crossing a
kernel boundary are recursively validated as final readonly graphs containing
only canonical immutable values.

## Local blocking gate

GitHub workflows are not the release authority. Every milestone must run
`composer check` locally. It validates Composer metadata, formatting, PHPStan
level 7, the complete offline Pest suite, the real PostgreSQL suite without
skips, and the installed dependency security audit. Release candidates run the
dependency and database gates on PHP 8.4 and PHP 8.5. The Testbench suite also
boots against deliberately unreachable PostgreSQL and Redis endpoints and
fails if package boot attempts database, migration, cache, queue, credential,
or outbound HTTP I/O.

## Support status

Security reports are accepted for the latest tagged 0.1.x release. Version 0.1
has no long-term-support commitment; changes to its support window will be
announced in future release notes.
