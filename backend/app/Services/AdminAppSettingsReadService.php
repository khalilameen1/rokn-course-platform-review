<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DesignSetting;
use App\Models\Setting;

final class AdminAppSettingsReadService
{
    /** @return array{settings: Setting, design: DesignSetting} */
    public function snapshot(): array
    {
        return ['settings' => Setting::query()->first() ?? new Setting, 'design' => DesignSetting::getDefaultSettings()];
    }

    public function defaultAiPolicy(): array
    {
        return [
            'basic' => ['chat_enabled' => false, 'chat_message_limit' => 0, 'chat_attachments_enabled' => false, 'project_feedback_level' => 'pass_only', 'project_followup_message_limit' => 0],
            'guided' => ['chat_enabled' => true, 'chat_message_limit' => (int) config('course_plans.ai_tiers.guided.chat_message_limit', 50), 'chat_attachments_enabled' => true, 'project_feedback_level' => 'report', 'project_followup_message_limit' => 0],
            'mentor' => ['chat_enabled' => true, 'chat_message_limit' => (int) config('course_plans.ai_tiers.mentor.chat_message_limit', 150), 'chat_attachments_enabled' => true, 'project_feedback_level' => 'enhanced', 'project_followup_message_limit' => (int) config('course_plans.ai_tiers.mentor.project_followup_message_limit', 50)],
        ];
    }
}
