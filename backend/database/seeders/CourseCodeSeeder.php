<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\CourseCode;
use App\Models\Lesson;
use Database\Seeders\Concerns\GuardsDevelopmentFixtures;
use Illuminate\Database\Seeder;

class CourseCodeSeeder extends Seeder
{
    use GuardsDevelopmentFixtures;

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->guardDevelopmentFixtures();

        // Get first course and lesson for testing
        $course = Course::first();
        $lesson = Lesson::first();

        if ($course) {
            CourseCode::create([
                'code' => 'COURSE123',
                'name' => 'كود تجريبي للدورة',
                'type' => 'course',
                'course_id' => $course->id,
                'start_date' => now(),
                'expiry_date' => now()->addMonths(3),
                'max_uses' => 10,
                'description' => 'كود تجريبي للوصول إلى الدورة',
            ]);

            // Create a lesson code
            if ($lesson) {
                CourseCode::create([
                    'code' => 'LESSON456',
                    'name' => 'كود تجريبي للدرس',
                    'type' => 'lesson',
                    'lesson_id' => $lesson->id,
                    'start_date' => now(),
                    'expiry_date' => now()->addMonths(1),
                    'max_uses' => 5,
                    'description' => 'كود تجريبي للوصول إلى درس محدد',
                ]);

                // Create a multiple lessons code
                CourseCode::create([
                    'code' => 'MULTI789',
                    'name' => 'كود تجريبي لعدة دروس',
                    'type' => 'multiple_lessons',
                    'course_id' => $course->id,
                    'lesson_ids' => [$lesson->id],
                    'start_date' => now(),
                    'expiry_date' => now()->addMonths(2),
                    'max_uses' => 3,
                    'description' => 'كود تجريبي للوصول إلى عدة دروس',
                ]);
            }

            // Create an expired code
            CourseCode::create([
                'code' => 'EXPIRED',
                'name' => 'كود منتهي الصلاحية',
                'type' => 'course',
                'course_id' => $course->id,
                'start_date' => now()->subMonths(2),
                'expiry_date' => now()->subMonth(),
                'max_uses' => 1,
                'description' => 'كود منتهي الصلاحية للاختبار',
            ]);

            // Create a future code
            CourseCode::create([
                'code' => 'FUTURE',
                'name' => 'كود لم يبدأ بعد',
                'type' => 'course',
                'course_id' => $course->id,
                'start_date' => now()->addMonth(),
                'expiry_date' => now()->addMonths(3),
                'max_uses' => 1,
                'description' => 'كود لم يبدأ بعد للاختبار',
            ]);
        }
    }
}
