<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('settings') && Schema::hasColumn('settings', 'ai_daily_user_limit')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->dropColumn('ai_daily_user_limit');
            });
        }

        if (!Schema::hasTable('course_chat_turns')) {
            return;
        }

        $columns = collect([
            'admission_minute_key',
            'admission_daily_key',
            'admission_quota_consumed_at',
            'admission_quota_released_at',
        ])->filter(fn (string $column): bool => Schema::hasColumn('course_chat_turns', $column))->all();

        if ($columns !== []) {
            Schema::table('course_chat_turns', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings') && !Schema::hasColumn('settings', 'ai_daily_user_limit')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->unsignedInteger('ai_daily_user_limit')->nullable();
            });
        }

        if (!Schema::hasTable('course_chat_turns')) {
            return;
        }

        Schema::table('course_chat_turns', function (Blueprint $table): void {
            if (!Schema::hasColumn('course_chat_turns', 'admission_minute_key')) {
                $table->string('admission_minute_key', 190)->nullable();
            }
            if (!Schema::hasColumn('course_chat_turns', 'admission_daily_key')) {
                $table->string('admission_daily_key', 190)->nullable();
            }
            if (!Schema::hasColumn('course_chat_turns', 'admission_quota_consumed_at')) {
                $table->timestamp('admission_quota_consumed_at')->nullable();
            }
            if (!Schema::hasColumn('course_chat_turns', 'admission_quota_released_at')) {
                $table->timestamp('admission_quota_released_at')->nullable();
            }
        });
    }
};
