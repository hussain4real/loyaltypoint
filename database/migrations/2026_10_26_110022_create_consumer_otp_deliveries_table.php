<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_otp_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('challenge_id')->unique()->constrained('consumer_challenges')->restrictOnDelete();
            $table->text('payload')->nullable();
            $table->string('state', 30)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->dateTime('expires_at');
            $table->uuid('lease_token')->nullable();
            $table->dateTime('lease_expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('last_error_code', 100)->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_otp_deliveries');
    }
};
