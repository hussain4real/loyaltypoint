<?php

declare(strict_types=1);

use App\Models\ConsumerChallenge;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerSession;
use App\Services\ConsumerSecurityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('consumes a login challenge once across independent concurrent processes', function (): void {
    $original = config('database.default');
    $sqliteFile = tempnam(sys_get_temp_dir(), 'consumer-security-');
    $connection = config('database.connections.sqlite');
    $connection['database'] = $sqliteFile;
    $connection['busy_timeout'] = 5000;
    $connection['journal_mode'] = 'WAL';
    $driver = getenv('FOUNDATION_CONCURRENCY_DRIVER') ?: 'sqlite';
    if ($driver === 'mysql') {
        $connection = config('database.connections.mysql');
        $database = getenv('FOUNDATION_CONCURRENCY_DATABASE');
        if (! is_string($database) || preg_match('/\Apull2spend_test[a-z0-9_]*\z/', $database) !== 1) {
            throw new RuntimeException('A dedicated pull2spend_test database is required.');
        }
        $connection['database'] = $database;
    }
    config(['database.default' => 'consumer_concurrency', 'database.connections.consumer_concurrency' => $connection,
        'pull2spend.consumer.enabled' => true]);
    try {
        Artisan::call('migrate:fresh', ['--database' => 'consumer_concurrency', '--force' => true]);
        $identity = ConsumerIdentity::factory()->contactVerified()->create();
        $challenge = app(ConsumerSecurityService::class)->startLogin(['kind' => 'email', 'value' => $identity->user->email]);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false',
            'DB_URL' => '', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'] ?? '',
            'DB_PORT' => (string) ($connection['port'] ?? ''), 'DB_USERNAME' => $connection['username'] ?? '', 'DB_PASSWORD' => $connection['password'] ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array',
            'PULL2SPEND_NAMESPACE' => 'testing', 'PULL2SPEND_CONSUMER_IDENTITY_ENABLED' => 'true'];
        $bootstrap = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ';
        $authenticate = $bootstrap.'try { $app->make(App\\Services\\ConsumerSecurityService::class)->authenticate('.var_export($challenge->id, true).', "password", "password"); exit(0); } catch (App\\Services\\ConsumerIdentityFailure $error) { if ($error->errorCode !== "challenge_unavailable") { exit(30); } exit(10); }';
        $children = [new Process([PHP_BINARY, '-r', $authenticate], base_path(), $environment), new Process([PHP_BINARY, '-r', $authenticate], base_path(), $environment)];
        foreach ($children as $child) {
            $child->setTimeout(30)->start();
        }
        $results = [];
        foreach ($children as $child) {
            $exitCode = $child->wait();
            expect(in_array($exitCode, [0, 10], true))->toBeTrue(substr($child->getOutput(), 0, 1600));
            $results[] = $exitCode;
            expect($child->getErrorOutput())->toBe('');
        }
        sort($results);
        expect($results)->toBe([0, 10]);
        expect(ConsumerSession::query()->where('consumer_identity_id', $identity->id)->count())->toBe(1);
        expect(ConsumerChallenge::findOrFail($challenge->id)->consumed_at)->not->toBeNull();
        $this->assertDatabaseCount('personal_access_tokens', 1);
    } finally {
        DB::purge('consumer_concurrency');
        config(['database.default' => $original]);
        foreach ([$sqliteFile, $sqliteFile.'-wal', $sqliteFile.'-shm'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
});
