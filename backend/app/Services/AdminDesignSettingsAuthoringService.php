<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DesignSetting;
use App\Support\AdminSingletonLock;
use App\Support\DesignSettingsEditorVersion;
use App\Support\PublicDiskUrl;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One identity document and its artwork references, separate from HTTP and byte cleanup. */
final readonly class AdminDesignSettingsAuthoringService
{
    public function __construct(
        private PublicAppSettingsService $publicSettings,
        private StoredFileUploadService $uploads,
        private StoredFileDeletionService $cleanup
    ) {
    }

    /** @param array<string,mixed> $validated
     *  @param array<string,UploadedFile> $files Validated files, keyed by their editor input.
     *  @param Closure(DesignSetting):void $complete Receipt completion inside the same transaction.
     */
    public function save(array $validated, array $files, string $editorVersion, Closure $complete): DesignSetting
    {
        $data = $this->fields($validated);
        $newFiles = [];
        try {
            foreach ($this->uploadFields() as $input => [$attribute, $directory]) {
                if (!isset($files[$input])) continue;
                $path = $this->uploads->storeTrackedUpload($files[$input], $directory);
                $newFiles[$attribute] = $path;
                $data[$attribute] = PublicDiskUrl::from($path);
            }

            return DB::transaction(function () use ($data, $newFiles, $editorVersion, $complete): DesignSetting {
                AdminSingletonLock::acquire('design_settings');
                $locked = DesignSetting::query()->lockForUpdate()->first();
                $current = $locked ?? DesignSetting::getDefaultSettings();
                if (!hash_equals(DesignSettingsEditorVersion::for($current), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => ["عدّل شخص آخر إعدادات التصميم\nأعد تحميل الصفحة قبل الحفظ"],
                    ]);
                }
                $oldPaths = [];
                foreach (array_keys($newFiles) as $attribute) {
                    $oldPath = PublicDiskUrl::pathFrom($current->{$attribute});
                    if ($oldPath !== null) $oldPaths[] = $oldPath;
                }
                if ($locked) {
                    $locked->update($data);
                    $settings = $locked;
                } else {
                    $settings = DesignSetting::query()->create($data);
                }
                // Record released objects in the same transaction that drops
                // their references; actual deletion remains with the worker.
                foreach (array_unique($oldPaths) as $path) {
                    $this->cleanup->deleteOrQueue('public', $path);
                }
                $complete($settings);

                return $settings;
            });
        } catch (\Throwable $exception) {
            foreach ($newFiles as $path) $this->cleanup->deleteOrQueue('public', $path);
            throw $exception;
        }
    }

    /** @return array<string,array{0:string,1:string}> */
    private function uploadFields(): array
    {
        $fields = [
            'logo_file' => ['logo_url', 'design-settings/logos'],
            'icon_file' => ['icon_url', 'design-settings/icons'],
            'home_background_file' => ['home_background_url', 'design-settings/home-backgrounds'],
        ];
        foreach (array_keys(AppArtworkService::ASSETS) as $key) {
            $fields[$key.'_image_file'] = [$key.'_image_url', 'design-settings/artwork'];
        }

        return $fields;
    }

    private function fields(array $validated): array
    {
        $data = Arr::only($validated, [
            'name_ar', 'name_en', 'slogan_1_ar', 'slogan_1_en', 'slogan_2_ar', 'slogan_2_en',
            'slogan_3_ar', 'slogan_3_en', 'color_1', 'color_2', 'color_3', 'color_4',
            'how_platform_works_title_ar', 'how_platform_works_title_en', 'how_platform_works_video_link',
        ]);
        $data['show_how_platform_works'] = (bool) ($validated['show_how_platform_works'] ?? false);
        if (!empty($data['how_platform_works_video_link'])) {
            $data['how_platform_works_video_link'] = $this->publicSettings->embedVideoUrl($data['how_platform_works_video_link']);
            if ($data['how_platform_works_video_link'] === null) {
                throw ValidationException::withMessages([
                    'how_platform_works_video_link' => ['استخدم رابط فيديو من YouTube أو Vimeo'],
                ]);
            }
        }
        if ($data['show_how_platform_works'] && empty($data['how_platform_works_video_link'])) {
            throw ValidationException::withMessages([
                'how_platform_works_video_link' => ['أضف رابط الفيديو قبل إظهار هذا القسم'],
            ]);
        }

        return $data;
    }
}
