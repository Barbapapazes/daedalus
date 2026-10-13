<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('implementation_requests', function (Blueprint $table): void {
            // Argos generates this ID once and carries it through the entire handoff.
            $table->uuid('id')->primary();
            $table->string('conversation_id', 128)->index();
            $table->string('tool_call_id');
            $table->string('tool_task_id');
            $table->json('intent');
            $table->json('messages');
            $table->char('payload_hash', 64);
            $table->string('status')->default('received');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('implementation_requests');
    }
};
