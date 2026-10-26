<?php

return [
    'backoffice_enabled' => env('PULL2SPEND_BACKOFFICE_ENABLED', false),
    'callbacks_enabled' => env('PULL2SPEND_CALLBACKS_ENABLED', false),
    'simulators_enabled' => env('PULL2SPEND_SIMULATORS_ENABLED', false),
    'namespace' => env('PULL2SPEND_NAMESPACE', ''),
    'secret_store_reference' => env('PULL2SPEND_SECRET_STORE_REFERENCE', ''),
    'backup_evidence_reference' => env('PULL2SPEND_BACKUP_EVIDENCE_REFERENCE', ''),
    'alert_owner_reference' => env('PULL2SPEND_ALERT_OWNER_REFERENCE', ''),
    'staff_mfa_ttl_seconds' => 1800,
    'staff_password_ttl_seconds' => 600,
    'staff_elevation_max_minutes' => 30,
    'cases' => ['sla_hours' => 24],
    'event_attempts' => 5,
    'lease_seconds' => 60,
    'retry_seconds' => [5, 30, 120, 600],
    'callback_skew_seconds' => 300,
    'callback_max_bytes' => 16384,
    // This is the foundation simulator protocol. Real adapters require their own verified contracts.
    'callback_sources' => [
        'sandbox-partner' => [
            'secret' => env('PULL2SPEND_SANDBOX_CALLBACK_KEY'),
            'secret_reference' => env('PULL2SPEND_SANDBOX_CALLBACK_KEY_REFERENCE'),
            'mode' => 'simulator',
        ],
    ],
];
