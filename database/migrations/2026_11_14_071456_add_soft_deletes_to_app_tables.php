<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['users', 'workspaces', 'social_accounts', 'media', 'posts', 'post_targets', 'publish_attempts'];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->index('user_id');
            $table->dropUnique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unique(['user_id', 'name']);
            $table->dropIndex(['user_id']);
        });

        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
