<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException('Verification immutability requires SQLite or MySQL/MariaDB.');
        }
        Schema::create('consumer_verification_revisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('consumer_identity_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedInteger('revision');
            $table->string('policy_reference', 180);
            $table->string('source', 64);
            $table->string('operation_reference', 180)->nullable();
            $table->string('idempotency_hash', 64)->unique();
            $table->string('request_hash', 64);
            $table->text('evidence');
            $table->text('checks')->nullable();
            $table->text('unresolved_checks')->nullable();
            $table->string('state', 40);
            $table->boolean('manual_review_required')->default(false);
            $table->dateTime('manual_decided_at')->nullable();
            $table->string('provider_observed_at', 35)->nullable();
            $table->timestamps();
            $table->unique(['consumer_identity_id', 'revision']);
            $table->index(['customer_id', 'state']);
        });
        Schema::create('consumer_verification_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('consumer_verification_revision_id')->constrained()->restrictOnDelete();
            $table->string('source', 64);
            $table->string('event_reference', 180);
            $table->string('identity_hash', 64)->unique();
            $table->string('payload_hash', 64);
            $table->text('payload');
            $table->string('occurred_at', 35);
            $table->timestamp('created_at');
        });
        Schema::create('consumer_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('consumer_verification_revision_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 40);
            $table->string('reason', 100);
            $table->string('evidence_reference', 180);
            $table->json('resolved_checks');
            $table->timestamp('created_at');
        });
        Schema::create('consumer_enrollment_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('consumer_identity_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_verification_revision_id')->constrained()->restrictOnDelete();
            $table->string('purpose', 40);
            $table->string('policy_reference', 180);
            $table->text('payload');
            $table->timestamp('created_at');
            $table->unique(['consumer_identity_id', 'purpose']);
        });
        foreach (['consumer_verification_receipts', 'consumer_review_decisions', 'consumer_enrollment_intents'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = $table.'_no_'.strtolower($operation);
                $body = $driver === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Verification evidence is append-only'); END"
                    : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Verification evidence is append-only'";
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}");
            }
        }
    }

    public function down(): void
    {
        foreach (['consumer_enrollment_intents', 'consumer_review_decisions', 'consumer_verification_receipts'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_no_{$operation}");
            }
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('consumer_verification_revisions');
    }
};
