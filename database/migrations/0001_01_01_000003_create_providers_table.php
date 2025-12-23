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
            $table->decimal('points_to_value_ratio', 10, 4)->default(1.0000)->comment('Value of 1 point in currency (e.g., 0.1 means 10 points = $1)');
            $table->decimal('transfer_fee_percent', 5, 2)->default(0.00)->comment('Fee charged when transferring OUT of this provider');
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
