<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            if (!Schema::hasColumn($table->getTable(), 'auth_provider')) {
                $table->string('auth_provider', 24)->nullable();
            }
            if (!Schema::hasColumn($table->getTable(), 'auth_provider_user_id')) {
                $table->string('auth_provider_user_id', 191)->nullable();
            }
        });
    }

    public function down(): void
    {
        $tableName = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (!Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $columns = collect(['auth_provider', 'auth_provider_user_id'])
                ->filter(fn (string $column): bool => Schema::hasColumn($table->getTable(), $column))
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
