<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_evaluations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_id');
            $table->string('reservation_type'); // flight, hotel, car, bus
            $table->json('reservation_data');
            $table->decimal('budget', 10, 2);
            $table->decimal('reservation_price', 10, 2)->nullable();
            $table->enum('decision', ['approved', 'rejected', 'needs_review']);
            $table->text('reason');
            $table->json('alternative')->nullable();
            $table->decimal('savings', 10, 2)->nullable();
            $table->decimal('savings_percentage', 5, 2)->nullable();
            $table->boolean('api_fallback')->default(false);
            $table->timestamps();

            $table->index(['decision', 'created_at']);
            $table->index('reservation_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_evaluations');
    }
};
