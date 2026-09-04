<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseChatTurn;
use Illuminate\Support\Collection;

final readonly class CourseChatAdmissionResult
{
    /** @var Collection<int, \App\Models\AiInputAttachment> */
    public Collection $attachments;

    public function __construct(
        public readonly string $state,
        public readonly ?CourseChatTurn $turn,
        ?Collection $attachments = null,
        public readonly ?string $answer = null
    ) {
        $this->attachments = $attachments ?? collect();
    }
}
