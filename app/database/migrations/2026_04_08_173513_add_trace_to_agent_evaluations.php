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
        Schema::table('agent_evaluations', function (Blueprint $table) {
            $table->json('trace')->nullable()->after('api_fallback');
        });
    }

    public function down(): void
    {
        Schema::table('agent_evaluations', function (Blueprint $table) {
            $table->dropColumn('trace');
        });
    }
};
