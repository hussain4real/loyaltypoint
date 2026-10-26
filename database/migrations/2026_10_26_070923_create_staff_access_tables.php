<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('role', 32);
            $table->json('partner_ids')->nullable();
            $table->json('customer_ids')->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('mfa_secret')->nullable();
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->unsignedBigInteger('last_totp_counter')->nullable();
            $table->string('enrollment_token_hash', 64)->nullable();
            $table->string('session_binding', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('staff_elevations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_membership_id')->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->string('permission', 64);
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('session_binding', 64);
            $table->string('reason', 64);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['staff_membership_id', 'permission', 'expires_at'], 'staff_elevations_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_elevations');
        Schema::dropIfExists('staff_memberships');
    }
};
