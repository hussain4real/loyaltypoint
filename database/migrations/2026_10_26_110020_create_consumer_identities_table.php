<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('reference')->unique();
            $table->char('contact_hash', 64)->unique();
            $table->dateTime('contact_verified_at')->nullable();
            $table->string('terms_version', 180);
            $table->string('privacy_notice_version', 180);
            $table->dateTime('notices_accepted_at');
            $table->string('kyc_state', 40)->default('not_started');
            $table->string('restriction', 40)->default('none');
            $table->unsignedInteger('verification_revision')->default(0);
            $table->string('policy_reference', 180)->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_identities');
    }
};
