<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('consumer_identity_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 40);
            $table->string('method', 40);
            $table->char('contact_hash', 64)->nullable();
            $table->uuid('device_reference')->nullable();
            $table->uuid('session_reference')->nullable();
            $table->char('action_digest', 64)->nullable();
            $table->string('proof_hash')->nullable();
            $table->text('payload')->nullable();
            $table->dateTime('expires_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['contact_hash', 'purpose', 'created_at']);
            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_challenges');
    }
};
