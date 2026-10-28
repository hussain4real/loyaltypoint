<?php

declare(strict_types=1);

use App\Models\ConsumerIdentity;
use App\Models\ConsumerOtpDelivery;
use App\Services\ConsumerContactService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

it('creates one identity from concurrent contact proofs without losing a valid outcome', function (bool $separateIntents): void {
    $original = config('database.default');
    $sqliteFile = tempnam(sys_get_temp_dir(), 'consumer-contact-');
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
    config(['database.default' => 'contact_concurrency', 'database.connections.contact_concurrency' => $connection,
        'pull2spend.consumer.enabled' => true, 'pull2spend.consumer.terms_version' => 'terms-1', 'pull2spend.consumer.privacy_notice_version' => 'privacy-1']);
    try {
        Artisan::call('migrate:fresh', ['--database' => 'contact_concurrency', '--force' => true]);
        $input = ['display_name' => 'Concurrent Consumer', 'contact' => ['kind' => 'email', 'value' => 'parallel@example.invalid'],
            'password' => 'ConcurrentPassword123!', 'password_confirmation' => 'ConcurrentPassword123!', 'terms_version' => 'terms-1', 'privacy_notice_version' => 'privacy-1'];
        $contacts = app(ConsumerContactService::class);
        $first = $contacts->register($input);
        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $second = $separateIntents ? $contacts->register($input) : $first;
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false',
            'DB_URL' => '', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'] ?? '',
            'DB_PORT' => (string) ($connection['port'] ?? ''), 'DB_USERNAME' => $connection['username'] ?? '', 'DB_PASSWORD' => $connection['password'] ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array', 'PULL2SPEND_NAMESPACE' => 'testing',
            'PULL2SPEND_CONSUMER_IDENTITY_ENABLED' => 'true', 'PULL2SPEND_TERMS_VERSION' => 'terms-1', 'PULL2SPEND_PRIVACY_NOTICE_VERSION' => 'privacy-1'];
        $bootstrap = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ';
        $children = [];
        foreach ([$first, $second] as $challenge) {
            $code = ConsumerOtpDelivery::where('challenge_id', $challenge->id)->sole()->payload['code'];
            $verify = $bootstrap.'try { $app->make(App\\Services\\ConsumerContactService::class)->verify('.var_export($challenge->id, true).', '.var_export($code, true).'); exit(0); } catch (App\\Services\\ConsumerIdentityFailure $error) { if (!in_array($error->errorCode,["challenge_replayed","challenge_invalid"],true)) { exit(30); } exit(10); }';
            $children[] = new Process([PHP_BINARY, '-r', $verify], base_path(), $environment);
        }
        foreach ($children as $child) {
            $child->setTimeout(30)->start();
        }
        $results = [];
        foreach ($children as $child) {
            $exit = $child->wait();
            expect(in_array($exit, [0, 10], true))->toBeTrue(substr($child->getOutput().$child->getErrorOutput(), 0, 1200));
            $results[] = $exit;
        }
        sort($results);
        expect($results)->toBe([0, 10]);
        expect(ConsumerIdentity::count())->toBe(1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    } finally {
        DB::purge('contact_concurrency');
        config(['database.default' => $original]);
        foreach ([$sqliteFile, $sqliteFile.'-wal', $sqliteFile.'-shm'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
})->with(['same challenge' => false, 'separate intents for one contact' => true]);
