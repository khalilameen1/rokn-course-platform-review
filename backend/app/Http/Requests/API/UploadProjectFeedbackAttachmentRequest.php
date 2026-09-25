<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use App\Services\AiInputAttachmentService;
use App\Services\ProjectSubmissionFilePolicy;
use Illuminate\Foundation\Http\FormRequest;

final class UploadProjectFeedbackAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(AiInputAttachmentService $attachments): array
    {
        // Follow-up attachments are not the project deliverable: keep all
        // conversation formats, but use the same provider size cap.
        return [
            'client_upload_id' => 'required|uuid',
            'attachment' => [
                'required',
                'file',
                'max:'.ProjectSubmissionFilePolicy::maximumFileKilobytes(),
                'mimetypes:'.implode(',', [
                    ...$attachments->allowedMimeTypes(),
                    'application/zip', 'application/x-zip-compressed', 'application/octet-stream',
                ]),
            ],
        ];
    }
}
