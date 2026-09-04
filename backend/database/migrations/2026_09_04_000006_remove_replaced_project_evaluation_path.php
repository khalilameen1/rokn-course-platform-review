<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('user_project_evaluations');

        if (Schema::hasColumn('projects', 'passing_score')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->dropColumn('passing_score');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('projects', 'passing_score')) {
            Schema::table('projects', function (Blueprint $table): void {
                $table->integer('passing_score')->default(50);
            });
        }

        if (!Schema::hasTable('user_project_evaluations')) {
            Schema::create('user_project_evaluations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->integer('score')->default(0);
                $table->boolean('passed')->default(false);
                $table->json('evaluation_data')->nullable();
                $table->text('submission_text')->nullable();
                $table->string('submission_file')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'project_id']);
                $table->unique(['user_id', 'project_id']);
            });
        }
    }
};
