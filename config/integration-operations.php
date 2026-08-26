<?php

declare(strict_types=1);

return [
    'database' => [
        'connection' => env('INTEGRATION_OPERATIONS_DB_CONNECTION'),
        'schema' => env('INTEGRATION_OPERATIONS_DB_SCHEMA', 'public'),
    ],

    'encryption' => [
        'active_version' => (int) env('INTEGRATION_OPERATIONS_ENCRYPTION_ACTIVE_VERSION', 1),
        'cipher' => env('INTEGRATION_OPERATIONS_ENCRYPTION_CIPHER', 'AES-256-GCM'),
        'keys' => [
            1 => env('INTEGRATION_OPERATIONS_ENCRYPTION_KEY_V1'),
        ],
    ],

    'context' => [
        'maximum_attributes' => 24,
        'maximum_bytes' => 4096,
        'maximum_key_bytes' => 64,
        'maximum_string_bytes' => 512,
        'maximum_correlation_id_bytes' => 255,
        'reserved_key_fragments' => [
            'token',
            'password',
            'secret',
            'credential',
            'authorization',
            'api_key',
            'email',
            'tax_id',
            'nip',
            'pesel',
            'phone',
            'address',
        ],
    ],

    'local_references' => [
        'allowed_types' => [],
    ],

    'writer_fences' => [],

    /*
     * This key ring is independent from APP_KEY. Keep retired readable keys
     * until every lookup alias has been backfilled to the active version.
     */
    'hmac' => [
        'active_version' => (int) env('INTEGRATION_OPERATIONS_HMAC_ACTIVE_VERSION', 1),
        'keys' => [
            1 => env('INTEGRATION_OPERATIONS_HMAC_KEY_V1'),
        ],
    ],

    'queues' => [
        'connection' => env('INTEGRATION_OPERATIONS_QUEUE_CONNECTION'),
        'execute' => env('INTEGRATION_OPERATIONS_QUEUE', 'integration-operations'),
        'claim_batch_per_provider' => 100,
        'claim_batch_per_connection' => 25,
        'redispatch_after_seconds' => 60,
        'routes' => [
            'default' => 'integration-operations',
        ],
        'allowlist' => [
            'integration-operations',
        ],
    ],

    'leases' => [
        'seconds' => 120,
        'heartbeat_seconds' => 30,
        'connect_timeout_seconds' => 10,
        'request_timeout_seconds' => 60,
        'safety_margin_seconds' => 15,
    ],

    'runtime' => [
        'maximum_batch_query' => 500,
        'maximum_payload_bytes' => 262144,
        'retry_delay_seconds' => 60,
        'reconciliation_delay_seconds' => 120,
    ],

    'events' => [
        'enabled' => true,
    ],

    'scheduler' => [
        'enabled' => true,
        'recovery_scan_limit' => 500,
        'scopes' => [],
    ],

    'retention' => [
        'terminal_tombstone_days' => 90,
        'raw_payload_days' => 30,
    ],
];
