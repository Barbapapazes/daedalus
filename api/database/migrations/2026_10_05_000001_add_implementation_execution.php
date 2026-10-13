<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('implementation_requests', function (Blueprint $table): void {
            // Nullable for historical requests that predate repository assignments.
            $table->json('repository')->nullable();
            $table->string('branch')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->text('dispatch_error')->nullable();
        });
        Schema::create('implementation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('implementation_request_id')->constrained()->cascadeOnDelete();
            $table->string('event_id');
            $table->unsignedInteger('sequence');
            $table->string('type');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->unique(['implementation_request_id', 'event_id']);
            $table->unique(['implementation_request_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('implementation_events');
        Schema::table('implementation_requests', fn (Blueprint $table) => $table->dropColumn([
            'repository', 'branch', 'dispatched_at', 'dispatch_error',
        ]));
    }
};
