<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStudentNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('student_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('notification_type'); // e.g., 'new_course_lesson', 'new_quiz', 'course_update'
            $table->string('notifiable_type')->nullable(); // polymorphic type (e.g., 'App\Models\Lesson')
            $table->unsignedBigInteger('notifiable_id')->nullable(); // polymorphic id
            $table->string('title_ar');
            $table->string('title_en');
            $table->text('message_ar');
            $table->text('message_en');
            $table->string('link')->nullable(); // deep link to the resource
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->unsignedBigInteger('tenant_id');
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');

            // Indexes for performance
            $table->index('user_id');
            $table->index('is_read');
            $table->index('tenant_id');
            $table->index('notification_type');
            $table->index('created_at');
            $table->index(['user_id', 'is_read']); // Composite index for common query
            $table->index(['notifiable_type', 'notifiable_id']); // Polymorphic index
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('student_notifications');
    }
}

