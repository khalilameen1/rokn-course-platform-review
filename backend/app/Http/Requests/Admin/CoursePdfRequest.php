<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\UnicodeText;
use App\Models\CoursePdf;
use App\Support\CourseAttachmentExternalUrl;
use Illuminate\Foundation\Http\FormRequest;

final class CoursePdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $pdf = $this->route('pdf');
        $existingSource = $pdf instanceof CoursePdf ? $pdf->source_type : 'upload';
        $source = $this->input('source_type', $existingSource);
        $requiresFile = $source === 'upload' && ($creating || $existingSource === 'external');
        $requiresUrl = $source === 'external' && ($creating || $existingSource !== 'external');

        return [
            'title' => $creating
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'required', 'string', 'max:255'],
            'title_en' => $creating
                ? ['nullable', 'string', 'max:255']
                : ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => $creating
                ? ['nullable', 'string', 'max:1000']
                : ['sometimes', 'nullable', 'string', 'max:1000'],
            'description_en' => $creating
                ? ['nullable', 'string', 'max:1000']
                : ['sometimes', 'nullable', 'string', 'max:1000'],
            'source_type' => ['sometimes', 'required', 'in:upload,external'],
            'platform' => ['sometimes', 'required', 'in:mobile,computer'],
            'external_url' => [
                'bail',
                $requiresUrl ? 'required' : ($source === 'external' ? 'sometimes' : 'nullable'),
                $source === 'external' ? 'required' : 'prohibited',
                'string', 'max:2000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (CourseAttachmentExternalUrl::normalize((string) $value) === null) {
                        $fail('أدخل رابط HTTPS صالحًا دون بيانات تسجيل دخول');
                    }
                },
            ],
            'pdf_file' => [
                $requiresFile ? 'required' : 'nullable',
                $source === 'external' ? 'prohibited' : 'file',
                'max:'.(int) config('course_attachments.max_upload_kilobytes', 51200),
            ],
            'order' => $creating
                ? ['nullable', 'integer', 'min:0']
                : ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => $creating
                ? ['nullable', 'boolean']
                : ['sometimes', 'nullable', 'boolean'],
            'authoring_version' => ['required', 'integer', 'min:1'],
            'authoring_request_id' => [$creating ? 'required' : 'nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['title', 'title_en'] as $field) {
            if (!$this->exists($field)) {
                continue;
            }
            $normalized[$field] = $this->filled($field)
                ? UnicodeText::clean($this->input($field), false)
                : null;
        }
        foreach (['description', 'description_en'] as $field) {
            if (!$this->exists($field)) {
                continue;
            }
            $normalized[$field] = $this->filled($field)
                ? UnicodeText::clean($this->input($field))
                : null;
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
