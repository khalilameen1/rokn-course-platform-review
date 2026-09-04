<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\GuardsDevelopmentFixtures;
use App\Models\CoinEarningMethod;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Grade;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Package;
use App\Models\Path;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RoknExperienceDemoSeeder extends Seeder
{
    use GuardsDevelopmentFixtures;

    public function run(): void
    {
        $this->guardDevelopmentFixtures();

        DB::transaction(function (): void {
            $grade = Grade::firstOrCreate(
                ['name_en' => 'Professional Skills'],
                [
                    'type' => 'professional',
                    'name_ar' => 'مهارات مهنية',
                    'description_ar' => 'مسار تجريبي للكورسات المهنية',
                    'description_en' => 'Demo professional course track',
                    'country' => 'EG',
                ]
            );
            $level = Level::updateOrCreate(
                ['name_en' => 'Junior'],
                [
                    'name_ar' => 'Junior',
                    'badge_image' => 'assets/img/badges/junior.png',
                    'description_ar' => 'أتم أول مستوى عملي في المسار',
                    'description_en' => 'Completed the first practical level in the track',
                    'order' => 1,
                ]
            );
            Level::updateOrCreate(
                ['name_en' => 'Mid-level'],
                [
                    'name_ar' => 'Mid-level',
                    'badge_image' => 'assets/img/badges/mid-level.png',
                    'description_ar' => 'أتم المستوى العملي المتوسط في المسار',
                    'description_en' => 'Completed the intermediate practical level in the track',
                    'order' => 2,
                ]
            );
            $path = Path::updateOrCreate(
                ['title_en' => 'Freelancing'],
                ['title_ar' => 'العمل الحر']
            );
            Level::updateOrCreate(
                ['name_en' => 'Senior'],
                [
                    'name_ar' => 'Senior',
                    'badge_image' => 'assets/img/badges/senior.png',
                    'description_ar' => 'أتم المستوى العملي المتقدم في المسار',
                    'description_en' => 'Completed the advanced practical level in the track',
                    'order' => 3,
                ]
            );
            $teacher = User::query()->where('role', 'admin')->first();
            $sourceLessons = Lesson::query()
                ->where(function ($query): void {
                    $query->whereNotNull('bunny_video_id')->orWhereNotNull('video_link');
                })
                ->orderBy('id')
                ->get();

            $course = Course::query()->firstOrNew(['name_en' => 'Rokn 30-Reel Demo Course']);
            $attributes = [
                'name_ar' => 'من الصفر لأول عميل فريلانس',
                'name_en' => 'Rokn 30-Reel Demo Course',
                'description_ar' => 'تجربة عملية قصيرة من 30 ريل: اختر خدمتك، ابنِ عرضك، وتواصل مع أول عميل بثقة.',
                'description_en' => 'A realistic 30-reel path from choosing a service to winning a first freelance client.',
                'grade_id' => $grade->id,
                'teacher_id' => $teacher?->id,
                'image' => '/images/demo-course-cover.svg',
                // The closed launch rehearsal is intentionally purchasable
                // with the 20-coin first-login gift. Public pricing remains a
                // dashboard decision and can be changed without a deployment.
                'price' => 20,
                'price_before_discount' => null,
                'currency' => 'ROKN_COINS',
                // Never invent social proof in an empty catalogue.
                'students_count' => 0,
                'course_type' => 'online',
                'is_main_course' => true,
                'is_coming_soon' => false,
                'level_id' => $level->id,
                'path_id' => $path->id,
                'awards_badge' => true,
                'badge_track' => 'freelance',
                'chat_ai_prompt' => 'كورس عملي للمبتدئ في الفريلانس يركز على اختيار خدمة محددة، بناء عرض واضح، والتواصل المهني مع العملاء دون وعود مبالغ فيها.',
                'temperature' => 0.5,
                'tokens_number' => 450,
            ];
            if (Schema::hasColumn('courses', 'tenant_id')) {
                $attributes['tenant_id'] = 1;
            }
            $course->forceFill($attributes)->save();
            app(CourseAccessPlanService::class)->createDefaults($course);

            if ($teacher && Schema::hasTable('course_teacher')) {
                $course->teachers()->syncWithoutDetaching([$teacher->id]);
            }

            $moduleDefinitions = [
                [
                    'title_ar' => 'ابنِ خدمتك',
                    'title_en' => 'Build your service',
                    'description_ar' => 'اختر مشكلة واضحة وحوّل مهارتك إلى خدمة قابلة للبيع.',
                    'project' => 'اكتب وصف خدمتك في صفحة واحدة وارفع لقطة واضحة منها.',
                ],
                [
                    'title_ar' => 'اصنع عرضًا يقنع',
                    'title_en' => 'Create a convincing offer',
                    'description_ar' => 'رتّب القيمة والنتيجة والسعر دون حشو.',
                    'project' => 'صمّم عرضًا بسيطًا لخدمتك وارفعه كصورة أو ملف.',
                ],
                [
                    'title_ar' => 'احصل على أول عميل',
                    'title_en' => 'Win your first client',
                    'description_ar' => 'اكتب رسالة افتتاحية وادخل محادثة البيع بثقة.',
                    'project' => 'اكتب رسالة تواصل حقيقية لعميل مناسب وارفع لقطة للمحاولة.',
                ],
            ];

            $reelTitles = [
                'لماذا لا يشتري العميل مهارتك؟', 'اختر مشكلة واحدة فقط', 'اعرف عميلك في دقيقة',
                'حوّل المهارة إلى نتيجة', 'صيغة الخدمة الواضحة', 'ماذا تستبعد من خدمتك؟',
                'دليل صغير يرفع الثقة', 'حدد مدة التسليم', 'سعّر البداية بذكاء', 'اختبار وضوح خدمتك',
                'النتيجة قبل التفاصيل', 'اكتب وعدًا واقعيًا', 'هيكل العرض في ثلاثة أسطر',
                'استخدم لغة العميل', 'اعرض ما سيستلمه بالضبط', 'اصنع باقة بداية',
                'تجنب الخصم العشوائي', 'أجب عن الاعتراض الأقوى', 'دعوة واضحة للخطوة التالية', 'راجع عرضك كعميل',
                'أين تجد العميل المناسب؟', 'لا ترسل رسالة جماعية', 'افتتاحية لا تبدو آلية',
                'أثبت أنك فهمت المشكلة', 'اسأل سؤالًا واحدًا جيدًا', 'متى تعرض خدمتك؟',
                'تابع دون إزعاج', 'تعامل مع الرفض', 'أغلق الخطوة التالية', 'خطة أول سبعة أيام',
            ];

            foreach ($moduleDefinitions as $moduleIndex => $definition) {
                $moduleNumber = $moduleIndex + 1;
                $module = CourseModule::updateOrCreate(
                    ['course_id' => $course->id, 'order' => $moduleNumber],
                    [
                        'title' => $definition['title_ar'],
                        'title_ar' => $definition['title_ar'],
                        'title_en' => $definition['title_en'],
                        'description' => $definition['description_ar'],
                        'description_ar' => $definition['description_ar'],
                        'description_en' => 'A focused practical module with ten short reels and one crossing project.',
                    ]
                );

                for ($position = 1; $position <= 10; $position++) {
                    $reelIndex = ($moduleIndex * 10) + $position;
                    $title = $reelTitles[$reelIndex - 1];
                    $source = $sourceLessons->isNotEmpty()
                        ? $sourceLessons[($reelIndex - 1) % $sourceLessons->count()]
                        : null;
                    // The legacy schema accepts youtube/bunny only; the app still
                    // consumes the direct HLS URL from video_link in this branch.
                    $videoType = $source?->getRawOriginal('video_source_type') ?: 'youtube';
                    $videoLink = $source?->getRawOriginal('video_link')
                        ?: env('DEMO_REEL_VIDEO_URL');

                    $lesson = Lesson::updateOrCreate(
                        ['list_id' => $course->id, 'title_en' => sprintf('Demo Reel %02d', $reelIndex)],
                        [
                            'title' => $title,
                            'title_ar' => $title,
                            'description' => 'مقطع قصير يشرح فكرة واحدة بوضوح',
                            'description_ar' => 'مقطع قصير يشرح فكرة واحدة بوضوح',
                            'description_en' => 'One focused step to apply in the module project.',
                            // Exercise the real try-before-unlock journey: two
                            // free reels, then the existing coin purchase gate.
                            'is_opened' => $reelIndex <= 2,
                            'video_source_type' => $videoType,
                            'video_link' => $videoLink,
                            'bunny_video_id' => $source?->getRawOriginal('bunny_video_id'),
                            'thumbnail_path' => $source?->getRawOriginal('thumbnail_path'),
                            'duration_minutes' => 1,
                            'priority' => $reelIndex,
                        ]
                    );

                    $globalOrder = ($moduleIndex * 11) + $position;
                    CourseSection::withTrashed()->updateOrCreate(
                        ['course_id' => $course->id, 'order' => $globalOrder],
                        [
                            'module_id' => $module->id,
                            'title' => $title,
                            'title_ar' => $title,
                            'title_en' => sprintf('Reel %02d', $reelIndex),
                            'section_type' => 'lesson',
                            'sectionable_type' => Lesson::class,
                            'sectionable_id' => $lesson->id,
                            'deleted_at' => null,
                        ]
                    );
                }

                $projectOrder = (($moduleIndex + 1) * 11);
                $projectSection = CourseSection::withTrashed()
                    ->where('course_id', $course->id)
                    ->where('order', $projectOrder)
                    ->first();
                $project = $projectSection && $projectSection->sectionable_type === Project::class
                    ? Project::find($projectSection->sectionable_id)
                    : null;
                $project = $project ?: new Project();
                $project->fill([
                    'requirements_text' => $definition['project'],
                    'requirements_text_ar' => $definition['project'],
                    'requirements_text_en' => 'Upload a clear attempt for this module project.',
                    'is_graduation_project' => $moduleIndex === 2,
                ])->save();

                CourseSection::withTrashed()->updateOrCreate(
                    ['course_id' => $course->id, 'order' => $projectOrder],
                    [
                        'module_id' => $module->id,
                        'title' => 'مشروع عبور: ' . $definition['title_ar'],
                        'title_ar' => 'مشروع عبور: ' . $definition['title_ar'],
                        'title_en' => 'Module crossing project',
                        'section_type' => 'project',
                        'sectionable_type' => Project::class,
                        'sectionable_id' => $project->id,
                        'deleted_at' => null,
                    ]
                );
            }

            CoinEarningMethod::updateOrCreate(
                ['action_key' => 'register'],
                [
                    'title_ar' => 'هدية الترحيب',
                    'title_en' => 'Welcome gift',
                    'coins_amount' => 20,
                    'action_url' => null,
                    'requires_external_visit' => false,
                    'verification_delay_seconds' => 0,
                    'is_active' => true,
                    'is_repeatable' => false,
                ]
            );

            // Reuse the legacy row so existing claim/earning records keep the
            // same method id. The replacement teaches the wallet rules inside
            // Rokn and never asks for notification permission.
            $legacyNotificationTask = CoinEarningMethod::where(
                'action_key',
                'demo_notifications'
            )->first();
            $coinGuideTaskExists = CoinEarningMethod::where(
                'action_key',
                'demo_coin_guide'
            )->exists();
            if ($legacyNotificationTask && !$coinGuideTaskExists) {
                $legacyNotificationTask->update(['action_key' => 'demo_coin_guide']);
            } elseif ($legacyNotificationTask) {
                $legacyNotificationTask->update(['is_active' => false]);
            }

            foreach ([
                ['title_ar' => 'اعرف كيف يعمل رصيد ركن', 'title_en' => 'Learn how Rokn balance works', 'coins' => 50, 'key' => 'demo_coin_guide', 'url' => null, 'external' => false],
                ['title_ar' => 'تابع ركن على Instagram', 'title_en' => 'Follow Rokn on Instagram', 'coins' => 75, 'key' => 'demo_instagram', 'url' => 'https://www.instagram.com/rokn.app', 'external' => true],
                ['title_ar' => 'تابع ركن على TikTok', 'title_en' => 'Follow Rokn on TikTok', 'coins' => 75, 'key' => 'demo_tiktok', 'url' => 'https://www.tiktok.com/@rokn.app', 'external' => true],
                ['title_ar' => 'تابع ركن على YouTube', 'title_en' => 'Follow Rokn on YouTube', 'coins' => 75, 'key' => 'demo_youtube', 'url' => 'https://www.youtube.com/@rokn', 'external' => true],
            ] as $task) {
                CoinEarningMethod::updateOrCreate(
                    ['action_key' => $task['key']],
                    [
                        'title_ar' => $task['title_ar'],
                        'title_en' => $task['title_en'],
                        'coins_amount' => $task['coins'],
                        'action_url' => $task['url'],
                        'requires_external_visit' => $task['external'],
                        'verification_delay_seconds' => $task['external'] ? 3 : 0,
                        'is_active' => true,
                        'is_repeatable' => false,
                    ]
                );
            }

            foreach ([
                // A one-pound package exercises the complete real-card flow
                // without asking launch testers to fund a placeholder course.
                ['name_ar' => '٢٠ رصيد — اختبار الدفع', 'name_en' => 'Checkout Test', 'price' => 1, 'coins' => 20],
                // Keep the stable English keys so rerunning the seeder updates
                // the historical demo rows instead of creating duplicates.
                ['name_ar' => '٤٢٠٠ رصيد', 'name_en' => 'Starter', 'price' => 249, 'coins' => 4200],
                ['name_ar' => '٨٥٠٠ رصيد', 'name_en' => 'Launch', 'price' => 479, 'coins' => 8500],
                ['name_ar' => '١٣٥٠٠ رصيد', 'name_en' => 'Pro', 'price' => 699, 'coins' => 13500],
            ] as $package) {
                Package::updateOrCreate(['name_en' => $package['name_en']], $package);
            }

            $settings = Setting::firstOrCreate([]);
            $settings->update([
                'welcome_bonus_coins' => 20,
                'max_reward_contribution_per_course' => 20,
                'enforce_course_section_order' => true,
                'how_to_use_coins_ar' => "استخدم عملات ركن لفتح الكورسات والخطط التعليمية المدفوعة داخل التطبيق.\nعند الشراء تُستخدم عملات المكافآت المؤهلة أولًا، ثم العملات المشتراة.\nلا يمكن بيع العملات أو تحويلها إلى نقد أو نقلها إلى حساب آخر.\nشراء العملات نهائي بعد تأكيد الدفع، وتبدأ مراجعة أي عطل يمنع الانتفاع عبر دعم ركن.",
                'how_to_use_coins_en' => "Use Rokn Coins to unlock paid courses and learning plans in the app.\nEligible reward coins are spent first, followed by paid coins.\nCoins cannot be sold, withdrawn as cash, or transferred to another account.\nCoin purchases are final once payment is confirmed, and any service-failure review starts with Rokn Support.",
            ]);
        });
    }
}
