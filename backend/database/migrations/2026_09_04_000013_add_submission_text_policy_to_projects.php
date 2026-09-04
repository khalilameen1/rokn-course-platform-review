<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('projects', 'submission_text_enabled')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->boolean('submission_text_enabled')
                    ->default(true)
                    ->after('is_graduation_project');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('projects', 'submission_text_enabled')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->dropColumn('submission_text_enabled');
            });
        }
    }
};
