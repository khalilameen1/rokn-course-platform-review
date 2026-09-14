<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('course_enrollments', 'completed_with_projects')) {
            return;
        }
        Schema::table('course_enrollments', function (Blueprint $table): void {
            // Every previously earned curriculum required the original full path.
            $table->boolean('completed_with_projects')->default(true);
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('course_enrollments', 'completed_with_projects')) {
            return;
        }
        Schema::table('course_enrollments', function (Blueprint $table): void {
            $table->dropColumn('completed_with_projects');
        });
    }
};
