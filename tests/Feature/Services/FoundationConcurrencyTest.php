<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\FoundationEvent;
use App\Services\FoundationEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** Real child PHP processes share a disposable database, not the parent's in-memory connection. */
it('persists one callback and one local effect across concurrent processes and restart', function (): void {
    $original = config('database.default');
    $sqliteFile = tempnam(sys_get_temp_dir(), 'pull2spend-concurrency-');
    $connection = config('database.connections.sqlite');
    $connection['database'] = $sqliteFile;
    $connection['busy_timeout'] = 5000;
    $connection['journal_mode'] = 'WAL';
    $driver = getenv('FOUNDATION_CONCURRENCY_DRIVER') ?: 'sqlite';
    if ($driver === 'mysql') {
        $connection = config('database.connections.mysql');
        $database = getenv('FOUNDATION_CONCURRENCY_DATABASE');
        if (! is_string($database) || preg_match('/\Apull2spend_test[a-z0-9_]*\z/', $database) !== 1) {
            throw new RuntimeException('A dedicated pull2spend_test database is required for concurrent MySQL tests.');
        }
        $connection['database'] = $database;
    }
    config(['database.default' => 'foundation_concurrency', 'database.connections.foundation_concurrency' => $connection]);
    try {
        Artisan::call('migrate:fresh', ['--database' => 'foundation_concurrency', '--force' => true]);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false',
            'DB_URL' => '', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'] ?? '',
            'DB_PORT' => (string) ($connection['port'] ?? ''), 'DB_USERNAME' => $connection['username'] ?? '', 'DB_PASSWORD' => $connection['password'] ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array',
            'PULL2SPEND_NAMESPACE' => 'testing', 'PULL2SPEND_CALLBACKS_ENABLED' => 'true', 'PULL2SPEND_SANDBOX_CALLBACK_KEY' => str_repeat('concurrency-test', 4)];
        $bootstrap = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ';
        $accept = $bootstrap.'$body = json_encode(["event_id" => "concurrent:1", "operation_reference" => "operation:concurrent", "status" => "succeeded", "occurred_at" => "2026-10-26T07:00:00Z"]); $timestamp = (string) time(); $signature = hash_hmac("sha256", $timestamp.".".$body, config("pull2spend.callback_sources.sandbox-partner.secret")); $app->make(App\\Services\\FoundationEvents::class)->accept("sandbox-partner", $body, $timestamp, $signature);';
        $children = [new Process([PHP_BINARY, '-r', $accept], base_path(), $environment), new Process([PHP_BINARY, '-r', $accept], base_path(), $environment)];
        foreach ($children as $child) {
            $child->setTimeout(30)->start();
        }
        foreach ($children as $child) {
            expect($child->wait())->toBe(0, $child->getErrorOutput().$child->getOutput());
        }
        $this->assertDatabaseCount('foundation_events', 1);
        $id = FoundationEvent::query()->sole()->id;
        $process = $bootstrap.'$app->make(App\\Services\\FoundationEvents::class)->process('.var_export($id, true).');';
        $workers = [new Process([PHP_BINARY, '-r', $process], base_path(), $environment), new Process([PHP_BINARY, '-r', $process], base_path(), $environment)];
        foreach ($workers as $worker) {
            $worker->setTimeout(30)->start();
        }
        foreach ($workers as $worker) {
            expect($worker->wait())->toBe(0, $worker->getErrorOutput().$worker->getOutput());
        }
        $this->assertDatabaseHas('foundation_events', ['id' => $id, 'state' => 'processed', 'attempts' => 1]);
        $this->assertDatabaseCount('foundation_operations', 1);
        expect(AuditEvent::query()->where('action', 'integration.observed')->count())->toBe(1);
        app(FoundationEvents::class)->process($id);
        expect(AuditEvent::query()->where('action', 'integration.observed')->count())->toBe(1);

        $abandoned = FoundationEvent::factory()->create();
        $claim = $bootstrap.'$app->make(App\\Services\\FoundationClaims::class)->acquire(new App\\Models\\FoundationEvent, '.var_export($abandoned->id, true).'); exit(17);';
        $crashed = new Process([PHP_BINARY, '-r', $claim], base_path(), $environment);
        expect($crashed->run())->toBe(17);
        $this->assertDatabaseHas('foundation_events', ['id' => $abandoned->id, 'state' => 'processing', 'attempts' => 1]);
        $this->assertDatabaseCount('foundation_operations', 1);
        $abandoned->refresh()->update(['lease_expires_at' => now()->subSecond()]);
        $restart = new Process([PHP_BINARY, '-r', $bootstrap.'$app->make(App\\Services\\FoundationEvents::class)->process('.var_export($abandoned->id, true).');'], base_path(), $environment);
        expect($restart->run())->toBe(0, $restart->getErrorOutput().$restart->getOutput());
        $this->assertDatabaseHas('foundation_events', ['id' => $abandoned->id, 'state' => 'processed', 'attempts' => 2]);
        $this->assertDatabaseCount('foundation_operations', 2);
    } finally {
        DB::purge('foundation_concurrency');
        config(['database.default' => $original]);
        foreach ([$sqliteFile, $sqliteFile.'-wal', $sqliteFile.'-shm'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});
