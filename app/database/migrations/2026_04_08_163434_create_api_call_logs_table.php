<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service')->default('onfly'); // which external service
            $table->string('method', 10);                // GET, POST, etc.
            $table->string('endpoint');                  // e.g. /bff/quote/create
            $table->integer('status_code')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_body')->nullable();
            $table->string('response_raw')->nullable();  // raw text when not JSON
            $table->boolean('success');
            $table->integer('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['service', 'endpoint', 'created_at']);
            $table->index('success');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_call_logs');
    }
};
