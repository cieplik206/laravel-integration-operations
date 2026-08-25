<?php

declare(strict_types=1);

return [
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
];
