<?php

namespace App\Http\Resources;

use App\Services\StudentNotificationPresentationService;
use App\Support\RoknLocale;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentNotificationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request)
    {
        $locale = RoknLocale::fromRequest($request);
        $isArabic = $locale === RoknLocale::ARABIC;

        $presentationService = app(StudentNotificationPresentationService::class);
        $titleAr = $presentationService->learnerArabicText($this->title_ar, 'إشعار من ركن');
        $titleEn = $presentationService->learnerText($this->title_en, 'Rokn notification');
        $messageAr = $presentationService->learnerArabicText($this->message_ar, 'لديك إشعار جديد');
        $messageEn = $presentationService->learnerText(
            $this->message_en,
            'You have a new notification'
        );
        $presentation = $presentationService->for($this->resource);

        return [
            'id' => $this->id,
            'campaign_id' => $this->delivery_key,
            'notification_type' => $this->notification_type,
            'title' => $isArabic ? $titleAr : $titleEn,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'message' => $isArabic ? $messageAr : $messageEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
            'link' => $presentation['link'],
            'course_id' => $presentation['course_id'],
            'image_url' => $presentation['image_url'],
            'action_label_ar' => $presentationService->learnerArabicText(
                $presentation['action_label_ar'],
                'افتح ركن'
            ),
            'action_label_en' => $presentation['action_label_en'],
            'is_read' => $this->is_read,
            'read_at' => $this->read_at ? $this->read_at->toIso8601String() : null,
            'created_at' => $this->created_at->toIso8601String(),
            'notifiable_type' => $this->notifiable_type,
            'notifiable_id' => $this->notifiable_id,
        ];
    }

}

