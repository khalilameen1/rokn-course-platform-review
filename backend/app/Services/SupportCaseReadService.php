<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\SupportCaseMessage;
use Illuminate\Support\Facades\URL;

/** Customer-visible timeline and attachment links; no message or case writes. */
final class SupportCaseReadService
{
    public function customerPayload(FeedbackReport $report): array
    {
        $report->load([
            'course:id,name_ar,name_en',
            'attachments' => fn ($query) => $query
                ->whereNull('support_case_message_id')
                ->where('scan_status', 'sanitized')
                ->orderBy('id'),
            'messages' => fn ($query) => $query
            ->where('visibility', SupportCaseMessage::VISIBILITY_CUSTOMER)
            ->with(['attachments' => fn ($attachments) => $attachments
                ->where('scan_status', 'sanitized')
                ->orderBy('id')])
            ->orderBy('id'),
        ]);

        return [
            'public_id' => $report->public_id,
            'case_number' => strtoupper(substr((string) $report->public_id, -8)),
            'category' => $report->category,
            'status' => $this->customerStatus((string) $report->status),
            'message' => $report->message,
            'course' => $report->course ? ['id' => (int) $report->course->id, 'title' => $report->course->title] : null,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
            'attachments' => $report->attachments
                ->map(fn (FeedbackAttachment $attachment): array => $this->customerAttachment(
                    $report,
                    $attachment
                ))->values()->all(),
            'messages' => $report->messages->map(fn (SupportCaseMessage $message): array => [
                'public_id' => $message->public_id,
                'author' => $message->author_type === SupportCaseMessage::AUTHOR_LEARNER ? 'learner' : 'support',
                'text' => $message->body,
                'has_attachment' => $message->attachments->isNotEmpty(),
                'attachments' => $message->attachments
                    ->map(fn (FeedbackAttachment $attachment): array => $this->customerAttachment(
                        $report,
                        $attachment
                    ))->values()->all(),
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array{id:string,name:string,mime:string,size:int,width:?int,height:?int,url:string,expires_at:string} */
    private function customerAttachment(
        FeedbackReport $report,
        FeedbackAttachment $attachment
    ): array {
        $expiresAt = now()->addMinutes(15);
        return [
            'id' => (string) $attachment->id,
            'name' => 'support-' . strtoupper(substr((string) $report->public_id, -8))
                . '-' . $attachment->id . '.jpg',
            'mime' => (string) ($attachment->mime_type ?: 'image/jpeg'),
            'size' => max(0, (int) $attachment->size_bytes),
            'width' => $attachment->width ? (int) $attachment->width : null,
            'height' => $attachment->height ? (int) $attachment->height : null,
            'url' => URL::temporarySignedRoute(
                'api.feedback.attachment',
                $expiresAt,
                ['publicId' => $report->public_id, 'attachment' => $attachment->id]
            ),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    private function customerStatus(string $status): string
    {
        return match ($status) {
            'new' => 'received',
            'reviewing' => 'in_progress',
            'waiting_for_user' => 'waiting_for_you',
            'resolved' => 'resolved',
            'closed', 'dismissed' => 'closed',
            default => 'in_progress',
        };
    }
}
