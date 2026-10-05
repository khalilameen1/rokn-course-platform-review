<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change only the shipped default; never replace an authored campaign,
        // its active schedule, image or the actual welcome reward amount.
        DB::table('admin_notifications')
            ->where('system_key', 'guest_registration_prompt')
            ->where('title_ar', 'هديتك جاهزة')
            ->whereIn('description_ar', [
                "سجّل الدخول واحصل على {coins} عملة ركن\nأو أكمل كزائر",
                "سجّل الدخول\nواحصل على {coins} عملة ركن",
            ])
            ->update([
                'title_ar' => 'حصلت على هدية ترحيبية',
                'description_ar' => '',
                'action_label_ar' => 'تسجيل الدخول',
                'secondary_action_label_ar' => 'تابع كزائر',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Editorial copy is not rolled back over later dashboard edits.
    }
};
