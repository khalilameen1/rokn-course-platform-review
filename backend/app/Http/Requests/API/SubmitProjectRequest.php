<?php

declare(strict_types=1);

namespace App\Http\Requests\API;

use App\Services\ProjectSubmissionFilePolicy;
use Illuminate\Foundation\Http\FormRequest;

final class SubmitProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->filled('client_submission_id') && $this->hasHeader('Idempotency-Key')) {
            $this->merge(['client_submission_id' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(ProjectSubmissionFilePolicy $files): array
    {
        $file = [
            'file',
            'min:1',
            'max:'.ProjectSubmissionFilePolicy::maximumFileKilobytes(),
            'mimetypes:'.implode(',', $files->requestMimeTypes()),
        ];

        return [
            'submission_text' => 'nullable|string|max:20000',
            'submission_file' => ['nullable', ...$file],
            'submission_files' => 'nullable|array|max:5',
            'submission_files.*' => $file,
            'client_submission_id' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        $message = 'اختر ملفًا بحجم '
            .ProjectSubmissionFilePolicy::maximumFileMegabytesLabel()
            .' ميجابايت أو أقل';

        return [
            'submission_file.max' => $message,
            'submission_files.*.max' => $message,
        ];
    }
}
