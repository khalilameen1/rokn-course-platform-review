<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->dropExisting('courses', [
            'ai_model_type',
            'chat_ai_prompt',
            'temperature',
            'tokens_number',
        ]);
        $this->dropExisting('projects', [
            'ai_prompt',
            'ai_model_type',
            'temperature',
            'tokens_number',
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('courses')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->string('ai_model_type')->nullable();
                $table->text('chat_ai_prompt')->nullable();
                $table->float('temperature')->nullable();
                $table->unsignedInteger('tokens_number')->nullable();
            });
        }
        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->text('ai_prompt')->nullable();
                $table->string('ai_model_type')->nullable();
                $table->float('temperature')->nullable();
                $table->unsignedInteger('tokens_number')->nullable();
            });
        }
    }

    /** @param list<string> $columns */
    private function dropExisting(string $tableName, array $columns): void
    {
        if (!Schema::hasTable($tableName)) {
            return;
        }

        $columns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($tableName, $column)
        ));
        if ($columns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns): void {
            $table->dropColumn($columns);
        });
    }
};
