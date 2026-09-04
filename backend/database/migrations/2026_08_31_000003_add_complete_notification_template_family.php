<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('admin_notifications') || !Schema::hasColumn('admin_notifications', 'system_key')) {
            return;
        }

        $now = now();
        $templates = [
            $this->template('course_enrolled', 'transactional', 'الكورس جاهز', 'Course unlocked', "{course}\nابدأ أول مقطع الآن", 'Start learning {course}', 'ابدأ الآن', 'Start now', 10),
            $this->template('institutional_grant', 'transactional', 'تم تفعيل منحتك', 'Your grant is active', "{course}\nابدأ عندما يناسبك", 'Your course grant is ready', 'ابدأ الآن', 'Start now', 11),
            $this->template('package_purchased', 'transactional', 'تم شحن رصيدك', 'Balance topped up', "أضفنا {coins} عملة إلى محفظتك\nالرصيد جاهز للاستخدام", '{coins} coins were added to your wallet', 'افتح المحفظة', 'View balance', 12),
            $this->template('coins_claimed', 'transactional', 'وصلت مكافأتك', 'Reward received', "أضفنا {coins} عملة إلى محفظتك\nافتح المحفظة لمعرفة التفاصيل", '{coins} coins were added to your wallet', 'افتح المحفظة', 'View balance', 13),
            $this->template('whatsapp_connected', 'transactional', 'تم ربط واتساب', 'WhatsApp connected', "أضفنا {coins} عملة ركن إلى رصيدك\nافتح المحفظة لمعرفة التفاصيل", '{coins} Rokn coins were added to your wallet', 'افتح المحفظة', 'View balance', 14),
            $this->template('course_completed', 'transactional', 'أكملت الكورس', 'Course completed', "أكملت {course}\nشهادتك جاهزة", 'You completed {course}. Your certificate is ready', 'افتح الشهادة', 'View certificate', 15),
            $this->template('certificate_ready', 'transactional', 'شهادتك جاهزة', 'Certificate ready', '{course}', '{course}', 'افتح الشهادة', 'View certificate', 16),
            $this->template('project_update', 'transactional', 'نتيجة مشروعك جاهزة', 'Project result ready', '{course}', '{course}', 'افتح النتيجة', 'View result', 17),
            $this->template('new_course_lesson', 'announcement', 'مقطع جديد', 'New lesson', "{lesson}\n{course}", "{lesson}\n{course}", 'شاهد الآن', 'Watch now', 40),
            $this->template('course_update', 'announcement', 'جديد في كورسك', 'Course update', '{course}', '{course}', 'افتح الكورس', 'View course', 42),
            $this->template('course_promotion', 'announcement', 'كورس يناسبك', 'A course for you', '{course}', '{course}', 'تفاصيل الكورس', 'View course', 43),
            $this->template('continue_course', 'retention', 'أكمل من مكانك', 'Continue learning', '{course}', '{course}', 'أكمل الآن', 'Continue', 51),
        ];

        foreach ($templates as $template) {
            DB::table('admin_notifications')->insertOrIgnore([
                ...$template,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Keep authored notification copy when rolling back unrelated code.
    }

    /** @return array<string, mixed> */
    private function template(
        string $key,
        string $surface,
        string $titleAr,
        string $titleEn,
        string $descriptionAr,
        string $descriptionEn,
        string $actionAr,
        string $actionEn,
        int $priority
    ): array {
        return [
            'system_key' => $key,
            'surface' => $surface,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'description_ar' => $descriptionAr,
            'description_en' => $descriptionEn,
            'action_label_ar' => $actionAr,
            'action_label_en' => $actionEn,
            'secondary_action_label_ar' => 'لاحقًا',
            'secondary_action_label_en' => 'Later',
            'link' => null,
            'is_active' => true,
            'is_dismissible' => true,
            'priority' => $priority,
            'cooldown_hours' => $surface === 'retention' ? 24 : 0,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
};
