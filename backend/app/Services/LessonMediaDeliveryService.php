<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;

/** Learner preview projection of already-published media state; never reconstructs it. */
final class LessonMediaDeliveryService
{
    public function __construct(private readonly BunnyDeliveryService $delivery)
    {
    }

    /**
     * Get video data for a lesson, including signed URLs if using Bunny
     *
     * @param Lesson $lesson
     * @return array
     */
    public function forLesson(Lesson $lesson): array
    {
        $data = [
            'video_source_type' => 'bunny',
            'video_link' => null,
            'bunny_video_url' => null,
            'bunny_video_expires_at' => null,
        ];

        if ($lesson->video_source_type === 'bunny' && !empty($lesson->bunny_video_id)) {
            $state = $lesson->relationLoaded('mediaState')
                ? $lesson->mediaState
                : $lesson->mediaState()->first();
            // Public previews used to bypass the playback control plane and
            // receive a signed URL while Bunny was still processing, missing,
            // or quarantined. The player then surfaced Bunny's raw domain
            // error. Only a coherently reconciled generation is playable.
            if (
                !$state
                || (string) $state->provider_media_id !== (string) $lesson->bunny_video_id
                || $state->status !== 'ready'
                || $state->last_reconciled_at === null
                || $state->integrity_status === 'quarantined'
            ) {
                return $data;
            }
            // Reuse the same signed HLS delivery contract as the authenticated player.
            $signedUrl = $this->delivery->videoPlayback($lesson->bunny_video_id);
            if ($signedUrl) {
                $data['bunny_video_url'] = $signedUrl['url'];
                $data['bunny_video_expires_at'] = $signedUrl['expires_at'];
            }
        }

        return $data;
    }
}
