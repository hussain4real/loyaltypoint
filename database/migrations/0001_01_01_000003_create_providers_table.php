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
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('trade_name')->nullable();
            $table->string('slug')->unique();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('official_logo')->nullable();
            $table->string('web_link')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('exchange_rate_base', 8, 4)->default(1.0000);
            $table->decimal('exchange_fee_percent', 5, 2)->default(0.00);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
