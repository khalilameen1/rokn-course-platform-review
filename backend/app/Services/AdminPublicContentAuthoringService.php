<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\About;
use App\Support\AdminEditorVersion;
use App\Support\AdminSingletonLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The single About/Privacy/Terms document the dashboard edits and
 * ManagedPublicContentService publishes. Same optimistic editor version and
 * singleton lock as the design and app settings documents.
 */
final readonly class AdminPublicContentAuthoringService
{
    private const FIELDS = ['about_ar', 'about_en', 'privacy_ar', 'privacy_en', 'policy_ar', 'policy_en'];

    /** @return array{about: About, editorVersion: string} */
    public function editorState(): array
    {
        $about = About::query()->first() ?? new About;

        return ['about' => $about, 'editorVersion' => $this->editorVersion($about)];
    }

    /**
     * @param  array<string, ?string>  $validated  Only the six text fields.
     *
     * @throws ValidationException when the text changed since the editor opened
     */
    public function save(array $validated, string $editorVersion): About
    {
        return DB::transaction(function () use ($validated, $editorVersion): About {
            AdminSingletonLock::acquire('abouts');
            $about = About::query()->lockForUpdate()->first();
            $snapshot = $about ?? new About;
            if (! hash_equals($this->editorVersion($snapshot), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّر النص المنشور منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                ]);
            }
            $about ??= About::query()->create(array_fill_keys(self::FIELDS, ''));
            $about->update($validated);

            return $about;
        }, 3);
    }

    private function editorVersion(About $about): string
    {
        return AdminEditorVersion::for($about, self::FIELDS);
    }
}
