<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\SupportCaseMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

/** Screenshot identity, sanitization and staged attachment persistence. */
final readonly class SupportCaseScreenshotService
{
    public function __construct(private StoredFileDeletionService $deletions)
    {
    }

    /** @return array{path:string,mime_type:string,size_bytes:int,width:int,height:int,sha256:string} */
    public function stage(
        FeedbackReport $report,
        string $clientRequestId,
        UploadedFile $upload
    ): array {
        try {
            $image = Image::make($upload->getRealPath());
        } catch (\Throwable) {
            abort(422, "تعذّرت قراءة الصورة\nاختر صورة أخرى");
        }
        if (function_exists('exif_read_data')) $image->orientate();
        $image->resize(2048, 2048, static function ($constraint): void {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $encoded = (string) $image->encode('jpg', 86);
        abort_if($encoded === '', 422, "تعذّرت قراءة الصورة\nاختر صورة أخرى");
        $sha = hash('sha256', $encoded);
        $directory = ($report->created_at ?: now())->format('Y/m');
        // Message receipts handle replay. Failed admission retries need new
        // bytes that cannot be deleted by the previous attempt's orphan job.
        $path = $directory.'/'.$report->public_id.'/'.hash(
            'sha256',
            'support-message|'.$report->public_id.'|'.strtolower($clientRequestId).'|'.$sha.'|'.Str::uuid()
        ).'.jpg';
        $this->deletions->trackPotentialOrphan('feedback', $path, 60);
        abort_unless(Storage::disk('feedback')->put($path, $encoded), 503, 'تعذّر حفظ الصورة الآن');

        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($encoded),
            'width' => $image->width(),
            'height' => $image->height(),
            'sha256' => $sha,
        ];
    }

    /** @param array{path:string,mime_type:string,size_bytes:int,width:int,height:int,sha256:string} $staged */
    public function attach(
        FeedbackReport $report,
        SupportCaseMessage $message,
        array $staged
    ): FeedbackAttachment {
        return $report->attachments()->firstOrCreate([
            'support_case_message_id' => $message->id,
            'path' => $staged['path'],
        ], [
            'disk' => 'feedback',
            'mime_type' => $staged['mime_type'],
            'size_bytes' => $staged['size_bytes'],
            'width' => $staged['width'],
            'height' => $staged['height'],
            'sha256' => $staged['sha256'],
            'scan_status' => 'sanitized',
        ]);
    }

    public function fingerprint(?UploadedFile $file): ?array
    {
        if (!$file) return null;
        $hash = hash_file('sha256', $file->getRealPath());
        abort_unless($hash && $file->getSize() > 0, 422, "تعذّرت قراءة الصورة\nاختر صورة أخرى");
        return ['sha256' => $hash, 'size' => (int) $file->getSize()];
    }
}
