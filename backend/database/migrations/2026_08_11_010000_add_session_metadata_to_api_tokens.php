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
            if (!Schema::hasColumn($table->getTable(), 'session_id')) {
                $table->uuid('session_id')->nullable()->unique();
            }
            if (!Schema::hasColumn($table->getTable(), 'platform')) {
                $table->string('platform', 16)->nullable()->index();
            }
            if (!Schema::hasColumn($table->getTable(), 'app_version')) {
                $table->string('app_version', 32)->nullable();
            }
            if (!Schema::hasColumn($table->getTable(), 'app_build')) {
                $table->string('app_build', 16)->nullable();
            }
            if (!Schema::hasColumn($table->getTable(), 'last_used_at')) {
                $table->timestamp('last_used_at')->nullable()->index();
            }
            if (!Schema::hasColumn($table->getTable(), 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->index();
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
            $columns = collect([
                'session_id', 'platform', 'app_version', 'app_build',
                'last_used_at', 'revoked_at',
            ])->filter(fn (string $column): bool => Schema::hasColumn($table->getTable(), $column))->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
