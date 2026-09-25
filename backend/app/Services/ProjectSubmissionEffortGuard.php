<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProjectSubmission;
use App\Support\UnicodeText;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/** Screens out empty input only; relevance and grading belong to evaluation. */
final readonly class ProjectSubmissionEffortGuard
{
    public function __construct(private AiInputAttachmentService $attachments)
    {
    }

    /** @param list<UploadedFile> $files */
    public function assess(?string $text, array $files): string
    {
        $plainText = trim((string) $text);
        if ($files !== []) {
            foreach ($files as $file) {
                if ((int) $file->getSize() < (int) config('projects.minimum_file_bytes', 512)) {
                    continue;
                }
                if ($this->isMeaningfulFile($file)) {
                    return ProjectSubmission::EFFORT_VALID;
                }
            }
            if ($plainText === '') {
                return ProjectSubmission::EFFORT_INVALID;
            }
        }

        return mb_strlen($plainText) >= (int) config('projects.minimum_text_length', 10)
            && !$this->isObviousGaming($plainText)
            ? ProjectSubmission::EFFORT_VALID
            : ProjectSubmission::EFFORT_INVALID;
    }

    private function isMeaningfulFile(UploadedFile $file): bool
    {
        $mime = (string) $this->attachments->canonicalMime($file);
        if (str_starts_with($mime, 'image/')) {
            return !$this->isBlankImage($file);
        }

        $path = $file->getRealPath();
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return false;
        }

        if ($mime === 'text/plain') {
            $body = file_get_contents($path, false, null, 0, 65536);
            if (!is_string($body)) return false;
            $body = UnicodeText::clean($body);

            return UnicodeText::graphemeLength($body) >= (int) config('projects.minimum_text_length', 10)
                && !$this->isObviousGaming($body);
        }

        if ($mime === 'application/pdf') {
            $handle = fopen($path, 'rb');
            if ($handle === false) return false;
            try {
                $head = fread($handle, 262144);
                if (!is_string($head) || !str_starts_with($head, '%PDF-')) return false;
                $size = max(0, (int) filesize($path));
                if ($size > 16384) fseek($handle, -16384, SEEK_END);
                else rewind($handle);
                $tail = fread($handle, 16384);

                if (!is_string($tail) || preg_match('/%%EOF\s*\z/s', $tail) !== 1) {
                    return false;
                }

                // Page dictionaries may be stored in compressed object
                // streams, so their text is not a reliable validity test.
                // Accept the cheap visible-page case, otherwise verify the
                // required cross-reference pointer and the object it names.
                if (preg_match('/\/Type\s*\/Page\b/', $head) === 1) {
                    return true;
                }
                if (preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/s', $tail, $match) !== 1) {
                    return false;
                }

                $xrefOffset = (int) $match[1];
                if ($xrefOffset < 1 || $xrefOffset >= $size
                    || fseek($handle, $xrefOffset, SEEK_SET) !== 0) {
                    return false;
                }
                $xref = fread($handle, 2048);
                if (!is_string($xref)) {
                    return false;
                }
                $xref = ltrim($xref);

                return str_starts_with($xref, 'xref')
                    || (
                        preg_match('/^\d+\s+\d+\s+obj\b/', $xref) === 1
                        && preg_match('/\/Type\s*\/XRef\b/', $xref) === 1
                    );
            } finally {
                fclose($handle);
            }
        }

        if (in_array($mime, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ], true)) {
            if (!class_exists(ZipArchive::class)) return false;
            $archive = new ZipArchive();
            if ($archive->open($path) !== true) return false;
            try {
                $mainEntry = $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    ? 'word/document.xml'
                    : 'ppt/presentation.xml';
                if ($archive->locateName('[Content_Types].xml') === false
                    || $archive->locateName($mainEntry) === false) {
                    return false;
                }

                for ($index = 0; $index < $archive->numFiles; $index++) {
                    $name = (string) $archive->getNameIndex($index);
                    if (preg_match('#^(?:word|ppt)/media/[^/]+$#', $name) === 1) {
                        $stat = $archive->statIndex($index);
                        if ((int) ($stat['size'] ?? 0) > 0) return true;
                    }
                    if (preg_match('#^(?:word/document|ppt/slides/slide[0-9]+)\.xml$#', $name) !== 1) {
                        continue;
                    }
                    $xml = $archive->getFromIndex($index);
                    if (is_string($xml)
                        && preg_match('/<(?:w:t|a:t)(?:\s[^>]*)?>\s*[^<\s][^<]*</u', $xml) === 1) {
                        return true;
                    }
                }

                return false;
            } finally {
                $archive->close();
            }
        }

        return false;
    }

    private function isObviousGaming(string $text): bool
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
        if ($normalized === '') return true;

        $compact = str_replace(' ', '', $normalized);
        if (preg_match('/^(.)\1{5,}$/u', $compact)) return true;
        if (preg_match('/^(?:asdf|qwer|zxcv|hjkl|1234|abcd)+$/iu', $compact)) return true;

        $words = array_values(array_filter(preg_split('/\s+/u', $normalized) ?: []));
        return count($words) >= 3 && count(array_unique($words)) === 1;
    }

    private function isBlankImage(UploadedFile $file): bool
    {
        // Missing image tooling is treated forgivingly, never as a student failure.
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        // Never decode an unexpectedly large image into PHP memory. Upload
        // validation limits the file bytes, while this guard also blocks tiny
        // compressed images with pathological dimensions (decompression bombs).
        $inspectionBytes = max(1, (int) config('projects.image_inspection_max_bytes', 8388608));
        if ((int) $file->getSize() > $inspectionBytes) {
            return false;
        }

        $dimensions = @getimagesize($file->getRealPath());
        if ($dimensions === false) {
            return true;
        }
        $width = max(0, (int) ($dimensions[0] ?? 0));
        $height = max(0, (int) ($dimensions[1] ?? 0));
        $maximumPixels = max(1, (int) config('projects.image_inspection_max_pixels', 12000000));
        if ($width < 2 || $height < 2) {
            return true;
        }
        if ($width > intdiv($maximumPixels, $height)) {
            return false;
        }

        $contents = @file_get_contents($file->getRealPath());
        $image = $contents !== false ? @imagecreatefromstring($contents) : false;
        if ($image === false) {
            return true;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width < 2 || $height < 2) {
            imagedestroy($image);
            return true;
        }

        $samples = 0;
        $dark = 0;
        $white = 0;
        $minimumAlpha = 127;
        $maximumAlpha = 0;
        $minimumVisible = [
            'black' => ['red' => 255, 'green' => 255, 'blue' => 255],
            'white' => ['red' => 255, 'green' => 255, 'blue' => 255],
        ];
        $maximumVisible = [
            'black' => ['red' => 0, 'green' => 0, 'blue' => 0],
            'white' => ['red' => 0, 'green' => 0, 'blue' => 0],
        ];
        $stepX = max(1, (int) floor($width / 20));
        $stepY = max(1, (int) floor($height / 20));
        $threshold = (int) config('projects.dark_image_threshold', 12);
        $whiteThreshold = (int) config('projects.white_image_threshold', 248);

        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                // Indexed PNGs return a palette index, not packed RGB.
                // Resolve both encodings before judging the visible content.
                $colour = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $red = $colour['red'];
                $green = $colour['green'];
                $blue = $colour['blue'];
                $alpha = (int) ($colour['alpha'] ?? 0);
                $samples++;
                $minimumAlpha = min($minimumAlpha, $alpha);
                $maximumAlpha = max($maximumAlpha, $alpha);
                $opacity = (127 - $alpha) / 127;
                foreach (['black' => 0, 'white' => 255] as $surface => $background) {
                    foreach (['red' => $red, 'green' => $green, 'blue' => $blue] as $channel => $value) {
                        $visible = (int) round(($value * $opacity) + ($background * (1 - $opacity)));
                        $minimumVisible[$surface][$channel] = min(
                            $minimumVisible[$surface][$channel],
                            $visible
                        );
                        $maximumVisible[$surface][$channel] = max(
                            $maximumVisible[$surface][$channel],
                            $visible
                        );
                    }
                }
                if (max($red, $green, $blue) <= $threshold) {
                    $dark++;
                }
                if (min($red, $green, $blue) >= $whiteThreshold) {
                    $white++;
                }
            }
        }

        imagedestroy($image);

        if ($samples === 0 || $minimumAlpha === 127) {
            return true;
        }

        $uniformityTolerance = (int) config('projects.solid_image_channel_range', 3);
        $visibleColourRange = 0;
        foreach (['black', 'white'] as $surface) {
            foreach (['red', 'green', 'blue'] as $channel) {
                $visibleColourRange = max(
                    $visibleColourRange,
                    $maximumVisible[$surface][$channel] - $minimumVisible[$surface][$channel]
                );
            }
        }
        // A one-colour logo can be drawn entirely by its transparency mask.
        // Compare its rendered contrast on both light and dark surfaces so
        // barely-visible alpha or hidden RGB cannot masquerade as real work.
        if (($maximumAlpha - $minimumAlpha) > $uniformityTolerance
            && $visibleColourRange > $uniformityTolerance) {
            return false;
        }

        return ($dark / $samples) >= (float) config('projects.dark_image_ratio', 0.97)
            || ($white / $samples) >= (float) config('projects.white_image_ratio', 0.985)
            || $visibleColourRange <= $uniformityTolerance;
    }
}
