<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('account_type', 32);
            $table->string('provider_account_id');
            $table->string('name');
            $table->string('handle')->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->boolean('needs_reconnect')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'provider', 'provider_account_id']);
            $table->index(['provider', 'token_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
