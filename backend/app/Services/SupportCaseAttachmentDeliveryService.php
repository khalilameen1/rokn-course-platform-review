<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackAttachment;
use Illuminate\Support\Facades\Storage;

/** Shared byte-integrity gate after the caller authorizes its case/attachment. */
final class SupportCaseAttachmentDeliveryService
{
    public function bytes(FeedbackAttachment $attachment): string
    {
        abort_unless($attachment->scan_status === 'sanitized', 404);
        $storage = Storage::disk((string) $attachment->disk);
        abort_unless($storage->exists((string) $attachment->path), 410);
        $bytes = $storage->get((string) $attachment->path);
        if ($attachment->sha256 && !hash_equals((string) $attachment->sha256, hash('sha256', $bytes))) {
            $attachment->update(['scan_status' => 'corrupt']);
            abort(410);
        }

        return $bytes;
    }
}
