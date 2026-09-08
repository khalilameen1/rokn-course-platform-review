<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_pdfs', function (Blueprint $table): void {
            $table->string('source_type', 16)->default('upload');
            $table->string('platform', 16)->default('mobile');
            $table->text('external_url')->nullable();
            $table->string('mime_type', 120)->nullable()->default('application/pdf');
            $table->string('file_extension', 16)->nullable()->default('pdf');
        });
    }

    public function down(): void
    {
        Schema::table('course_pdfs', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'platform', 'external_url', 'mime_type', 'file_extension']);
        });
    }
};
