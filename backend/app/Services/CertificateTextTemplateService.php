<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Support\UnicodeText;

/**
 * Owns the editorial contract shared by course authoring, publishing,
 * preview and certificate issuance.
 */
final class CertificateTextTemplateService
{
    /**
     * @return array<string,array{label:string,description:string,text:string,qr_destination:string}>
     */
    public function catalogue(): array
    {
        $catalogue = [];
        foreach ((array) config('certificate.text_templates', []) as $key => $template) {
            $key = trim((string) $key);
            if ($key === '' || !is_array($template)) {
                continue;
            }

            $text = UnicodeText::limit(
                UnicodeText::clean($template['text'] ?? null, false),
                255
            );
            if ($text === '') {
                continue;
            }

            $label = UnicodeText::limit(
                UnicodeText::clean($template['label'] ?? $key, false),
                80
            );
            $description = UnicodeText::limit(
                UnicodeText::clean($template['description'] ?? '', false),
                160
            );
            $catalogue[$key] = [
                'label' => $label !== '' ? $label : $key,
                'description' => $description,
                'text' => $text,
                'qr_destination' => ($template['qr_destination'] ?? null) === 'portfolio'
                    ? 'portfolio'
                    : 'certificate',
            ];
        }

        return $catalogue;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->catalogue());
    }

    /** @return array{key:string,text:string}|null */
    public function resolve(string $key): ?array
    {
        $key = trim($key);
        $template = $this->catalogue()[$key] ?? null;

        return $template === null
            ? null
            : ['key' => $key, 'text' => $template['text']];
    }

    /** @return array{key:string,text:string}|null */
    public function forCourse(Course $course): ?array
    {
        // Callers may pass a staged/preview course whose selected template has
        // not been persisted yet. Issuance already supplies a freshly locked
        // model, so the current model attribute is the one shared contract for
        // authoring preview, publish validation and immutable snapshotting.
        return $this->resolve((string) $course->certificate_text_template_key);
    }

    public function qrDestination(string $key): string
    {
        return ($this->catalogue()[trim($key)]['qr_destination'] ?? null) === 'portfolio'
            ? 'portfolio'
            : 'certificate';
    }
}
