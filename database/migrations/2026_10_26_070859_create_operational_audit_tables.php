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
            throw new RuntimeException('Operational audit immutability is supported only on SQLite and MySQL/MariaDB.');
        }

        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 100);
            $table->string('target_reference', 180);
            $table->string('reason', 100);
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->json('context');
            $table->timestamp('created_at');
            $table->index(['partner_id', 'customer_id', 'id']);
        });

        Schema::create('operational_cases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('reference')->unique();
            $table->string('category', 40);
            $table->string('source_reference', 180);
            $table->string('identity_hash', 64)->unique();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('owner_team', 40)->default('operations');
            $table->string('state', 40)->default('open');
            $table->timestamp('due_at');
            $table->string('resolution_code', 100)->nullable();
            $table->string('evidence_reference', 180)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'customer_id', 'state', 'due_at']);
        });

        Schema::create('case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('from_state', 40)->nullable();
            $table->string('to_state', 40);
            $table->string('reason', 100);
            $table->string('evidence_reference', 180)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        foreach (['audit_events', 'case_events'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $trigger = $table.'_immutable_'.strtolower($operation);
                $body = $driver === 'sqlite'
                    ? "BEGIN SELECT RAISE(ABORT, 'Append-only records cannot be changed'); END"
                    : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Append-only records cannot be changed'";
                DB::unprepared("CREATE TRIGGER {$trigger} BEFORE {$operation} ON {$table} FOR EACH ROW {$body}");
            }
        }
    }

    public function down(): void
    {
        foreach (['audit_events', 'case_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$table}_immutable_{$operation}");
            }
        }
        Schema::dropIfExists('case_events');
        Schema::dropIfExists('operational_cases');
        Schema::dropIfExists('audit_events');
    }
};
