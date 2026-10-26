<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foundation_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 64);
            $table->string('external_event_id', 128);
            $table->string('payload_hash', 64);
            $table->string('identity_hash', 64)->unique();
            $table->text('payload');
            $table->string('state')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
            $table->index(['state', 'lease_expires_at']);
        });
        Schema::create('foundation_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('reference', 128);
            $table->string('identity_hash', 64)->unique();
            $table->string('state');
            $table->string('observed_at', 35);
            $table->uuid('last_event_id');
            $table->timestamps();
        });
        Schema::create('foundation_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('deduplication_key', 64)->unique();
            $table->text('payload');
            $table->string('state')->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('last_error_code')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
            $table->index(['state', 'lease_expires_at']);
        });
        Schema::create('foundation_delivery_receipts', function (Blueprint $table): void {
            $table->uuid('delivery_id')->primary();
            $table->string('reference')->unique();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_delivery_receipts');
        Schema::dropIfExists('foundation_deliveries');
        Schema::dropIfExists('foundation_operations');
        Schema::dropIfExists('foundation_events');
    }
};
