<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 128)->unique();
            $table->string('description', 500);
            $table->text('context');
            $table->string('artifacts_namespace')->default('daedalus');
            $table->string('artifacts_name', 128)->unique();
            $table->string('artifacts_id')->nullable()->unique();
            $table->string('default_branch')->default('main');
            $table->string('initial_commit', 40)->nullable();
            $table->string('status')->default('provisioning')->index();
            $table->string('payload_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
