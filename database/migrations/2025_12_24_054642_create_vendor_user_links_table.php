<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vendor_user_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('provider_id')->constrained()->onDelete('cascade');
            $table->string('vendor_email');
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamps();

            // One vendor email can only link to one platform user per provider
            $table->unique(['vendor_email', 'provider_id']);

            // Index for looking up user's links
            $table->index(['user_id', 'provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendor_user_links');
    }
};
