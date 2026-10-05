<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('courses', 'last_published_authoring_version')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->unsignedBigInteger('last_published_authoring_version')->nullable()->index();
            });
        }

        if (!Schema::hasColumn('courses', 'published_at')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->timestamp('published_at')->nullable();
            });
        }
        DB::table('courses')->where('is_coming_soon', false)->update([
            'last_published_authoring_version' => DB::raw('authoring_version'),
            'published_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('courses', 'published_at')) {
            Schema::table('courses', function (Blueprint $table): void {
                $table->dropColumn('published_at');
            });
        }

        if (Schema::hasColumn('courses', 'last_published_authoring_version')) {
            Schema::table('courses', function (Blueprint $table): void {
                if (Schema::hasIndex('courses', 'courses_last_published_authoring_version_index')) {
                    $table->dropIndex('courses_last_published_authoring_version_index');
                }
                $table->dropColumn('last_published_authoring_version');
            });
        }
    }
};
