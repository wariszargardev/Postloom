<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publish_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_target_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16);
            $table->boolean('retryable')->default(false);
            $table->text('error_message')->nullable();
            $table->string('error_class')->nullable();
            $table->string('external_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['post_target_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publish_attempts');
    }
};
