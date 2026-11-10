<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('disk', 32);
            $table->string('path', 1024);
            $table->string('original_name');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration_seconds', 10, 2)->nullable();
            $table->string('thumbnail_path', 1024)->nullable();
            $table->char('checksum', 64);
            $table->string('alt_text', 1000)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'checksum']);
            $table->index(['workspace_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
