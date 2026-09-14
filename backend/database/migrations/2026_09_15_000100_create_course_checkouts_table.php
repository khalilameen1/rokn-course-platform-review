<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('course_checkouts')) return;
        Schema::create('course_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('course_id')->index();
            $table->string('status', 32)->default('quoted');
            $table->string('channel', 16);
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('funding_order_id')->nullable()->unique();
            $table->unsignedBigInteger('course_order_id')->nullable()->unique();
            $table->json('terms');
            $table->string('terms_hash', 64);
            $table->string('error_code', 100)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('course_checkouts'); }
};
