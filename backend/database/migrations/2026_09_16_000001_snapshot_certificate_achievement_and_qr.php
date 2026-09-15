<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasColumn('certificates', 'certificate_design_version')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->string('certificate_design_version', 32)->nullable());
        }
        if (!Schema::hasColumn('certificates', 'certificate_completion_text')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->string('certificate_completion_text', 255)->nullable());
        }
        if (!Schema::hasColumn('certificates', 'certificate_curriculum_revision')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->unsignedBigInteger('certificate_curriculum_revision')->nullable());
        }
        if (!Schema::hasColumn('certificates', 'certificate_project_evidence')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->json('certificate_project_evidence')->nullable());
        }
        if (!Schema::hasColumn('certificates', 'certificate_qr_snapshot')) {
            Schema::table('certificates', fn (Blueprint $table) =>
                $table->json('certificate_qr_snapshot')->nullable());
        }
        // No historical backfill: a new claim or target cannot be inferred
        // from current course/profile data for an already printed credential.
    }

    public function down(): void
    {
        foreach ([
            'certificate_design_version',
            'certificate_completion_text',
            'certificate_curriculum_revision',
            'certificate_project_evidence',
            'certificate_qr_snapshot',
        ] as $column) {
            if (Schema::hasColumn('certificates', $column)) {
                Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
