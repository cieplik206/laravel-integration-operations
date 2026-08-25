# Contributing

Keep changes small, focused, and coordinated with the provider SDK and
application vertical slice that exercises them.

## Local development

    composer install
    composer validate --strict --no-check-publish
    composer test
    composer analyse
    vendor/bin/pint --test src tests
    composer audit

Do not add provider-specific or application-domain behavior to this package.
Every future runtime change must include tests for its state and failure
semantics.
