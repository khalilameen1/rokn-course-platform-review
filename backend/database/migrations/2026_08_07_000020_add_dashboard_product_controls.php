<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('courses', 'ai_chat_enabled')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->boolean('ai_chat_enabled')->default(true)->after('tokens_number');
            });
        }

        $columns = [
            'ai_daily_user_limit' => fn (Blueprint $table) => $table->unsignedInteger('ai_daily_user_limit')->nullable(),
            'ai_global_daily_request_limit' => fn (Blueprint $table) => $table->unsignedInteger('ai_global_daily_request_limit')->nullable(),
            'ai_global_daily_token_budget' => fn (Blueprint $table) => $table->unsignedBigInteger('ai_global_daily_token_budget')->nullable(),
            'ai_global_monthly_token_budget' => fn (Blueprint $table) => $table->unsignedBigInteger('ai_global_monthly_token_budget')->nullable(),
            'ai_answer_cache_minutes' => fn (Blueprint $table) => $table->unsignedSmallInteger('ai_answer_cache_minutes')->nullable(),
        ];
        foreach ($columns as $name => $definition) {
            if (!Schema::hasColumn('settings', $name)) {
                Schema::table('settings', $definition);
            }
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            'ai_daily_user_limit',
            'ai_global_daily_request_limit',
            'ai_global_daily_token_budget',
            'ai_global_monthly_token_budget',
            'ai_answer_cache_minutes',
        ], fn (string $column): bool => Schema::hasColumn('settings', $column)));
        if ($columns !== []) {
            Schema::table('settings', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }

        if (Schema::hasColumn('courses', 'ai_chat_enabled')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->dropColumn('ai_chat_enabled');
            });
        }
    }
};
