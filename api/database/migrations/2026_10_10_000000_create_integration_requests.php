<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('implementation_request_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('assignment');
            $table->string('status')->default('queued');
            $table->timestamp('dispatched_at')->nullable();
            $table->text('dispatch_error')->nullable();
            $table->timestamps();
        });
        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('integration_request_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->unsignedInteger('sequence');
            $table->string('type');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->unique(['integration_request_id', 'event_id']);
            $table->unique(['integration_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integration_requests');
    }
};
