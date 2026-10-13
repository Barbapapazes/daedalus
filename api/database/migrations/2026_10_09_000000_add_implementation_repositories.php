<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('implementation_requests', function (Blueprint $table): void {
            // Preserve historical single-repository payloads and their replay hashes.
            $table->json('repositories')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('implementation_requests', function (Blueprint $table): void {
            $table->dropColumn('repositories');
        });
    }
};
