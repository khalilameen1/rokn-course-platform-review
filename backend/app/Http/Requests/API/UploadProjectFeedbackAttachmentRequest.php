<?php
declare(strict_types=1);
namespace App\Http\Requests\API;
use App\Services\AiInputAttachmentService;
use Illuminate\Foundation\Http\FormRequest;
final class UploadProjectFeedbackAttachmentRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(AiInputAttachmentService $attachments): array { return ['client_upload_id'=>'required|uuid','attachment'=>['required','file','max:'.min((int)config('projects.maximum_file_kilobytes',25600),(int)floor((int)config('openrouter.attachment_provider_max_bytes',8388608)/1024)),'mimetypes:'.implode(',',[...$attachments->allowedMimeTypes(),'application/zip','application/x-zip-compressed','application/octet-stream'])]]; }
}
