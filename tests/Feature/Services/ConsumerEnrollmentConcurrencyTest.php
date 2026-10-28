<?php

declare(strict_types=1);

use App\Enums\AccountRestriction;
use App\Enums\KycState;
use App\Enums\StaffRole;
use App\Models\ConsumerEnrollmentIntent;
use App\Models\ConsumerIdentity;
use App\Models\ConsumerReviewDecision;
use App\Models\ConsumerVerificationReceipt;
use App\Models\ConsumerVerificationRevision;
use App\Models\StaffMembership;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

function withKycConcurrencyDatabase(Closure $test): void
{
    $original = config('database.default');
    $sqliteFile = tempnam(sys_get_temp_dir(), 'pull2spend-kyc-');
    $connection = config('database.connections.sqlite');
    $connection['database'] = $sqliteFile;
    $connection['busy_timeout'] = 5000;
    $connection['journal_mode'] = 'WAL';
    $driver = getenv('FOUNDATION_CONCURRENCY_DRIVER') ?: 'sqlite';
    if ($driver === 'mysql') {
        $connection = config('database.connections.mysql');
        $database = getenv('FOUNDATION_CONCURRENCY_DATABASE');
        if (! is_string($database) || preg_match('/\Apull2spend_test[a-z0-9_]*\z/', $database) !== 1) {
            throw new RuntimeException('Concurrent identity tests require a dedicated pull2spend_test database.');
        }
        $connection['database'] = $database;
    }
    config(['database.default' => 'kyc_concurrency', 'database.connections.kyc_concurrency' => $connection]);
    try {
        Artisan::call('migrate:fresh', ['--database' => 'kyc_concurrency', '--force' => true]);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false',
            'DB_URL' => '', 'DB_CONNECTION' => $driver, 'DB_DATABASE' => $connection['database'], 'DB_HOST' => $connection['host'] ?? '',
            'DB_PORT' => (string) ($connection['port'] ?? ''), 'DB_USERNAME' => $connection['username'] ?? '', 'DB_PASSWORD' => $connection['password'] ?? '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'database', 'SESSION_DRIVER' => 'array'];
        $bootstrap = 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); config(["pull2spend.consumer.enabled"=>true,"pull2spend.consumer.verification_mode"=>"fixture","pull2spend.consumer.verification_validity_seconds"=>3600,"pull2spend.consumer.policy_reference"=>"fixture-policy-v1"]); ';
        $test($environment, $bootstrap);
    } finally {
        DB::purge('kyc_concurrency');
        config(['database.default' => $original]);
        foreach ([$sqliteFile, $sqliteFile.'-wal', $sqliteFile.'-shm'] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}

function kycConcurrentDecisionCode(ConsumerVerificationRevision $revision, string $event): string
{
    $payload = ['event_reference' => $event, 'revision_reference' => $revision->reference, 'operation_reference' => $revision->operation_reference,
        'checks' => array_fill_keys(['document', 'liveness', 'identity', 'sanctions', 'pep', 'adverse_media'], 'clear'),
        'occurred_at' => $revision->created_at->toIso8601String(), 'evidence_reference' => 'fixture:provider-evidence'];

    return '$app->make(App\\Services\\ConsumerIdentityService::class)->applyProviderDecision(new App\\Services\\ConsumerVerificationDecision("local-fixture", '.var_export($payload, true).'));';
}

function kycConcurrentStaffContext(StaffMembership $staff): string
{
    return '$staff = App\\Models\\StaffMembership::findOrFail('.$staff->id.'); $session = $app->make("session.store"); $session->start(); $binding = hash("sha256", $session->getId()); $staff->forceFill(["session_binding"=>$binding])->save(); $session->put(["staff_mfa_user"=>$staff->user_id,"staff_mfa_at"=>now()->timestamp,"staff_mfa_binding"=>$binding]); $request = Illuminate\\Http\\Request::create("/backoffice"); $request->setLaravelSession($session); $app->instance("request",$request); $request->setUserResolver(fn()=>$staff->user); config(["pull2spend.backoffice_enabled"=>true]); ';
}

it('commits one initial intent across concurrent independent checkers and a replay after lost acknowledgement', function (): void {
    withKycConcurrencyDatabase(function (array $environment, string $bootstrap): void {
        $revision = ConsumerVerificationRevision::factory()->create();
        $children = [new Process([PHP_BINARY, '-r', $bootstrap.kycConcurrentDecisionCode($revision, 'event-1')], base_path(), $environment),
            new Process([PHP_BINARY, '-r', $bootstrap.kycConcurrentDecisionCode($revision, 'event-2')], base_path(), $environment)];
        foreach ($children as $child) {
            $child->setTimeout(30)->start();
        }
        foreach ($children as $child) {
            expect($child->wait())->toBe(0, $child->getErrorOutput().$child->getOutput());
        }
        expect(ConsumerVerificationReceipt::count())->toBe(2);
        expect(ConsumerEnrollmentIntent::count())->toBe(0);
        $examiner = StaffMembership::factory()->enrolled()->create(['role' => StaffRole::Compliance, 'partner_ids' => null, 'customer_ids' => null]);
        $examine = kycConcurrentStaffContext($examiner).'$app->make(App\\Services\\ConsumerIdentityService::class)->recommendApproval($staff->user, '.var_export($revision->reference, true).', 1, "checks_examined", "review:examiner", [], "standard", '.var_export(now()->addYears(2)->toDateString(), true).');';
        $examination = new Process([PHP_BINARY, '-r', $bootstrap.$examine], base_path(), $environment);
        expect($examination->run())->toBe(0, $examination->getErrorOutput().$examination->getOutput());
        $recommendationReference = ConsumerReviewDecision::where('decision', 'recommend_verified')->sole()->reference;
        $approvers = [];
        foreach ([1, 2] as $index) {
            $checker = StaffMembership::factory()->enrolled()->create(['role' => StaffRole::Compliance, 'partner_ids' => null, 'customer_ids' => null]);
            $approve = kycConcurrentStaffContext($checker).'try { $app->make(App\\Services\\ConsumerIdentityService::class)->review($staff->user, '.var_export($revision->reference, true).', 1, App\\Enums\\KycState::Verified, "checks_approved", "review:checker-'.$index.'", [], '.var_export($recommendationReference, true).'); echo "approved"; } catch (App\\Services\\ConsumerIdentityFailure $failure) { if ($failure->errorCode !== "identity_review_conflict") { throw $failure; } echo "conflict"; }';
            $approver = new Process([PHP_BINARY, '-r', $bootstrap.$approve], base_path(), $environment);
            $approver->setTimeout(30)->start();
            $approvers[] = $approver;
        }
        $outcomes = [];
        foreach ($approvers as $approver) {
            expect($approver->wait())->toBe(0, $approver->getErrorOutput().$approver->getOutput());
            $outcomes[] = $approver->getOutput();
        }
        sort($outcomes);
        expect($outcomes)->toBe(['approved', 'conflict']);
        expect(ConsumerEnrollmentIntent::count())->toBe(1);
        expect(ConsumerReviewDecision::count())->toBe(2);
        $ackLost = new Process([PHP_BINARY, '-r', $bootstrap.kycConcurrentDecisionCode($revision, 'event-1').' exit(17);'], base_path(), $environment);
        expect($ackLost->run())->toBe(17);
        $replayed = new Process([PHP_BINARY, '-r', $bootstrap.kycConcurrentDecisionCode($revision, 'event-1')], base_path(), $environment);
        expect($replayed->run())->toBe(0, $replayed->getErrorOutput().$replayed->getOutput());
        expect(ConsumerVerificationReceipt::count())->toBe(2);
        expect(ConsumerEnrollmentIntent::count())->toBe(1);
    });
});

it('serializes suspension against provider approval without letting the callback clear account restrictions', function (): void {
    withKycConcurrencyDatabase(function (array $environment, string $bootstrap): void {
        $revision = ConsumerVerificationRevision::factory()->create();
        $staff = StaffMembership::factory()->enrolled()->create(['role' => StaffRole::Compliance, 'partner_ids' => null, 'customer_ids' => null]);
        $staffContext = '$staff = App\\Models\\StaffMembership::findOrFail('.$staff->id.'); $session = $app->make("session.store"); $session->start(); $binding = hash("sha256", $session->getId()); $staff->forceFill(["session_binding"=>$binding])->save(); $session->put(["staff_mfa_user"=>$staff->user_id,"staff_mfa_at"=>now()->timestamp,"staff_mfa_binding"=>$binding]); $request = Illuminate\\Http\\Request::create("/backoffice"); $request->setLaravelSession($session); $app->instance("request",$request); $request->setUserResolver(fn()=>$staff->user); config(["pull2spend.backoffice_enabled"=>true]); ';
        $restrict = $staffContext.'$app->make(App\\Services\\ConsumerIdentityService::class)->restrict($staff->user, '.var_export(ConsumerIdentity::find($revision->consumer_identity_id)->reference, true).', App\\Enums\\AccountRestriction::Suspended,"risk_suspended","review:restriction");';
        $children = [new Process([PHP_BINARY, '-r', $bootstrap.kycConcurrentDecisionCode($revision, 'event-1')], base_path(), $environment),
            new Process([PHP_BINARY, '-r', $bootstrap.$restrict], base_path(), $environment)];
        foreach ($children as $child) {
            $child->setTimeout(30)->start();
        }
        foreach ($children as $child) {
            expect($child->wait())->toBe(0, $child->getErrorOutput().$child->getOutput());
        }
        $identity = ConsumerIdentity::findOrFail($revision->consumer_identity_id);
        expect($identity)->restriction->toBe(AccountRestriction::Suspended)->kyc_state->toBe(KycState::UnderReview);
        expect(ConsumerEnrollmentIntent::count())->toBeLessThanOrEqual(1);
        $later = new Process([PHP_BINARY, '-r', $bootstrap.kycConcurrentDecisionCode($revision, 'event-2')], base_path(), $environment);
        expect($later->run())->toBe(0, $later->getErrorOutput().$later->getOutput());
        expect($identity->fresh()->restriction)->toBe(AccountRestriction::Suspended);
        expect(ConsumerEnrollmentIntent::count())->toBeLessThanOrEqual(1);
    });
});
