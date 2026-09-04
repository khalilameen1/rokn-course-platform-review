<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_submission_review_decisions');
    }

    public function down(): void
    {
        if (Schema::hasTable('project_submission_review_decisions')) {
            return;
        }

        Schema::create('project_submission_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('decision_id')->unique();
            $table->foreignId('submission_id')->constrained('project_submissions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 30);
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('feedback');
            $table->string('source', 40);
            $table->unsignedBigInteger('reviewer_id')->nullable()->index();
            $table->string('reviewer_role', 24)->nullable();
            $table->timestamp('decided_at');
            $table->json('decision_metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['submission_id', 'sequence'], 'project_review_decision_sequence');
        });
    }
};
