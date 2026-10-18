<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\PaymentEvidencePath;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PaymentEvidenceController extends Controller
{
    public function show(Order $order): StreamedResponse
    {
        $path = PaymentEvidencePath::from($order->getRawOriginal('payment_screenshot'));
        abort_if($path === null, 404);

        $diskName = trim((string) config('payment_evidence.disk', 'local'));
        abort_if($diskName === '' || $diskName === 'public', 404);

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);
        abort_unless($disk->exists($path), 404);

        $mime = strtolower(trim((string) $disk->mimeType($path)));
        abort_unless(in_array($mime, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ], true), 404);

        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        };

        return $disk->response(
            $path,
            "payment-evidence-{$order->id}.{$extension}",
            [
                'Cache-Control' => 'private, no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
