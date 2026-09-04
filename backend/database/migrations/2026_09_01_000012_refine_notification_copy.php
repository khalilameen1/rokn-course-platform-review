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

        $copy = [
            'guest_registration_prompt' => ['هديتك جاهزة', "سجّل الدخول\nواحصل على {coins} عملة ركن", 'تسجيل الدخول', 'المتابعة كزائر'],
            'welcome_bonus_received' => ['أضيفت هديتك', "{coins} عملة ركن في محفظتك", 'افتح المحفظة', 'إغلاق'],
            'coin_offer' => ['عملات إضافية', "{task}\nالمكافأة {coins} عملة", 'افتح المهمة', 'لاحقًا'],
            'learning_nudge' => ['أكمل من مكانك', "{course}\nمقطعك التالي جاهز", 'أكمل الآن', 'لاحقًا'],
            'course_enrolled' => ['الكورس جاهز', "{course}\nابدأ عندما يناسبك", 'ابدأ الآن', 'لاحقًا'],
            'institutional_grant' => ['تم تفعيل منحتك', '{course}', 'ابدأ الآن', 'لاحقًا'],
            'package_purchased' => ['تم شحن رصيدك', "{coins} عملة في محفظتك", 'افتح المحفظة', 'إغلاق'],
            'coins_claimed' => ['وصلت مكافأتك', "{coins} عملة في محفظتك", 'افتح المحفظة', 'إغلاق'],
            'whatsapp_connected' => ['تم ربط واتساب', "{coins} عملة في محفظتك", 'افتح المحفظة', 'إغلاق'],
            'course_completed' => ['أكملت الكورس', "{course}\nشهادتك جاهزة", 'افتح الشهادة', 'إغلاق'],
            'certificate_ready' => ['شهادتك جاهزة', '{course}', 'افتح الشهادة', 'إغلاق'],
            'project_update' => ['نتيجة مشروعك جاهزة', '{course}', 'افتح النتيجة', 'إغلاق'],
            'new_course_lesson' => ['مقطع جديد', "{lesson}\n{course}", 'شاهد الآن', 'لاحقًا'],
            'course_update' => ['جديد في كورسك', '{course}', 'افتح الكورس', 'لاحقًا'],
            'course_promotion' => ['كورس يناسبك', '{course}', 'تفاصيل الكورس', 'لاحقًا'],
            'continue_course' => ['أكمل من مكانك', '{course}', 'أكمل الآن', 'لاحقًا'],
        ];

        $seededDescriptions = [
            'guest_registration_prompt' => "سجّل الدخول واحصل على {coins} عملة ركن\nأو أكمل كزائر",
            'welcome_bonus_received' => "أضفنا {coins} عملة ركن إلى محفظتك\nاستخدمها داخل التطبيق",
            'coin_offer' => "{task}\nاحصل على {coins} عملة ركن",
            'learning_nudge' => "{course}\nأكمل بمقطع واحد اليوم",
            'course_enrolled' => "{course}\nابدأ أول مقطع الآن",
            'institutional_grant' => "{course}\nابدأ عندما يناسبك",
            'package_purchased' => "أضفنا {coins} عملة إلى محفظتك\nالرصيد جاهز للاستخدام",
            'coins_claimed' => "أضفنا {coins} عملة إلى محفظتك\nافتح المحفظة لمعرفة التفاصيل",
            'whatsapp_connected' => "أضفنا {coins} عملة ركن إلى رصيدك\nافتح المحفظة لمعرفة التفاصيل",
            'course_completed' => "أكملت {course}\nشهادتك جاهزة",
            'certificate_ready' => '{course}',
            'project_update' => '{course}',
            'new_course_lesson' => "{lesson}\n{course}",
            'course_update' => '{course}',
            'course_promotion' => '{course}',
            'continue_course' => '{course}',
        ];
        $englishCopy = [
            'guest_registration_prompt' => ['Your gift is ready', "Sign in\nReceive {coins} Rokn coins", 'Sign in', 'Continue as guest', 'Sign in to receive {coins} Rokn coins. You can also keep browsing as a guest.'],
            'welcome_bonus_received' => ['Gift received', '{coins} Rokn coins are in your wallet', 'Open wallet', 'Close', 'We added {coins} Rokn coins to your wallet. They are in-app credits, not cash.'],
            'coin_offer' => ['More coins', "{task}\nReward {coins} coins", 'Open task', 'Later', '{task} and receive {coins} Rokn coins after completion.'],
            'learning_nudge' => ['Continue where you stopped', "{course}\nYour next clip is ready", 'Continue', 'Later', 'Continue {course}. One short clip is enough to get back into it.'],
            'course_enrolled' => ['Course ready', "{course}\nStart when it suits you", 'Start now', 'Later', 'Start learning {course}'],
            'institutional_grant' => ['Grant activated', '{course}', 'Start now', 'Later', 'Your course grant is ready'],
            'package_purchased' => ['Balance topped up', '{coins} coins are in your wallet', 'Open wallet', 'Close', '{coins} coins were added to your wallet'],
            'coins_claimed' => ['Reward received', '{coins} coins are in your wallet', 'Open wallet', 'Close', '{coins} coins were added to your wallet'],
            'whatsapp_connected' => ['WhatsApp connected', '{coins} coins are in your wallet', 'Open wallet', 'Close', '{coins} Rokn coins were added to your wallet'],
            'course_completed' => ['Course completed', "{course}\nYour certificate is ready", 'Open certificate', 'Close', 'You completed {course}. Your certificate is ready'],
            'certificate_ready' => ['Certificate ready', '{course}', 'Open certificate', 'Close', '{course}'],
            'project_update' => ['Project result ready', '{course}', 'Open result', 'Close', '{course}'],
            'new_course_lesson' => ['New clip', "{lesson}\n{course}", 'Watch now', 'Later', "{lesson}\n{course}"],
            'course_update' => ['New in your course', '{course}', 'Open course', 'Later', '{course}'],
            'course_promotion' => ['A course for you', '{course}', 'View course', 'Later', '{course}'],
            'continue_course' => ['Continue where you stopped', '{course}', 'Continue', 'Later', '{course}'],
        ];

        foreach ($copy as $key => [$title, $description, $action, $secondary]) {
            DB::table('admin_notifications')
                ->where('system_key', $key)
                ->where('description_ar', $seededDescriptions[$key])
                ->update([
                    'title_ar' => $title,
                    'description_ar' => $description,
                    'action_label_ar' => $action,
                    'secondary_action_label_ar' => $secondary,
                    'updated_at' => now(),
                ]);
        }

        foreach ($englishCopy as $key => [$title, $description, $action, $secondary, $seededDescription]) {
            DB::table('admin_notifications')
                ->where('system_key', $key)
                ->where('description_en', $seededDescription)
                ->update([
                    'title_en' => $title,
                    'description_en' => $description,
                    'action_label_en' => $action,
                    'secondary_action_label_en' => $secondary,
                    'updated_at' => now(),
                ]);
        }

        DB::table('admin_notifications')
            ->where('system_key', 'welcome_bonus_received')
            ->whereIn('description_ar', [
                $seededDescriptions['welcome_bonus_received'],
                $copy['welcome_bonus_received'][1],
            ])
            ->update(['link' => '/wallet', 'updated_at' => now()]);

        foreach ([
            'new_course_lesson' => 12,
            'course_update' => 12,
            'course_promotion' => 72,
            'continue_course' => 24,
        ] as $key => $hours) {
            DB::table('admin_notifications')
                ->where('system_key', $key)
                ->where('cooldown_hours', 0)
                ->update(['cooldown_hours' => $hours, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Dashboard-authored notification copy must not be rolled back.
    }
};
