<?php

declare(strict_types=1);

namespace App\Services;

final class FoundationEnvironment
{
    /** @return list<string> Safe configuration problem codes; never values or secrets. */
    public function problems(): array
    {
        $problems = [];
        $namespace = config('pull2spend.namespace');
        if (! is_string($namespace) || preg_match('/\A[a-z][a-z0-9_-]{2,40}\z/', $namespace) !== 1) {
            $problems[] = 'environment_namespace_missing';
        }
        if (! app()->environment('local', 'testing')) {
            if (config('app.debug')) {
                $problems[] = 'debug_enabled';
            }
            if (config('pull2spend.simulators_enabled') || config('pull2spend.callbacks_enabled')) {
                $problems[] = 'sandbox_adapter_outside_development';
            }
            if (! config('session.secure') || ! config('session.encrypt')) {
                $problems[] = 'session_protection_missing';
            }
            foreach (['secret_store_reference', 'alert_owner_reference', 'backup_evidence_reference'] as $key) {
                if (! config('pull2spend.'.$key)) {
                    $problems[] = $key.'_missing';
                }
            }
            foreach (['cache.prefix', 'session.cookie', 'queue.connections.database.queue'] as $key) {
                if (! is_string(config($key)) || ! str_contains(config($key), (string) $namespace)) {
                    $problems[] = str_replace('.', '_', $key).'_not_namespaced';
                }
            }
            if (config('queue.default') !== 'database') {
                $problems[] = 'durable_queue_missing';
            }
        }
        if ((int) config('pull2spend.lease_seconds') <= 30 || (int) config('queue.connections.database.retry_after') <= (int) config('pull2spend.lease_seconds')) {
            $problems[] = 'unsafe_worker_timeout_order';
        }

        $attempts = config('pull2spend.event_attempts');
        $delays = config('pull2spend.retry_seconds');
        if (! is_int($attempts) || $attempts < 1 || $attempts > 20 || ! is_array($delays) || ! array_is_list($delays) || $delays === []
            || count(array_filter($delays, fn (mixed $delay): bool => is_int($delay) && $delay > 0 && $delay <= 86400)) !== count($delays)) {
            $problems[] = 'invalid_retry_policy';
        }
        foreach (['callback_skew_seconds' => 3600, 'callback_max_bytes' => 1048576] as $key => $maximum) {
            if (! is_int(config('pull2spend.'.$key)) || config('pull2spend.'.$key) < 1 || config('pull2spend.'.$key) > $maximum) {
                $problems[] = 'invalid_'.$key;
            }
        }

        return $problems;
    }
}
