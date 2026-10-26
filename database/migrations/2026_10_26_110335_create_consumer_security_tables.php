<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_identity_id')->constrained()->restrictOnDelete();
            $table->text('public_key')->nullable();
            $table->char('key_fingerprint', 64)->nullable()->unique();
            $table->string('assurance', 32)->default('password');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('consumer_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('consumer_identity_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained('consumer_devices')->restrictOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('auth_method', 32);
            $table->timestamp('authenticated_at');
            $table->timestamp('last_active_at');
            $table->timestamp('expires_at');
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'revoked_at']);
        });
        Schema::create('consumer_step_ups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('consumer_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained('consumer_devices')->restrictOnDelete();
            $table->uuid('challenge_reference')->unique();
            $table->char('action_digest', 64);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['consumer_session_id', 'action_digest', 'expires_at'], 'consumer_step_up_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_step_ups');
        Schema::dropIfExists('consumer_sessions');
        Schema::dropIfExists('consumer_devices');
    }
};
