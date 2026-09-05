<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Models\DesignSetting;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LevelController extends Controller
{
    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $levels = Level::ordered()->get();
        $designSettings = $this->getDesignSettings();
        return view('admin.levels.index', compact('levels', 'designSettings'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $designSettings = $this->getDesignSettings();
        return view('admin.levels.create', compact('designSettings'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'badge_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'order' => 'nullable|integer|min:1|max:1000',
            'authoring_request_id' => 'required|uuid',
        ]);

        unset($validated['badge_image']);
        $requestId = (string) $validated['authoring_request_id'];
        $imagePath = $request->hasFile('badge_image')
            ? app(StoredFileDeletionService::class)
                ->storeTrackedUpload(
                    $request->file('badge_image'),
                    'levels',
                    'public',
                    60,
                    'admin-level|'.strtolower($requestId).'|'.hash_file('sha256', $request->file('badge_image')->getRealPath())
                )
            : null;
        if ($request->hasFile('badge_image') && (!is_string($imagePath) || $imagePath === '')) {
            throw new \RuntimeException('Level badge storage failed');
        }
        try {
            DB::transaction(function () use ($request, $validated, $imagePath, $requestId, $createIntents): void {
                $level = Level::query()->where('authoring_request_id', $requestId)
                    ->lockForUpdate()->first();
                if (!$level) {
                    $level = Level::create($validated);
                }
                if ($imagePath) {
                    $level->allPhotos()->firstOrCreate(['path' => $imagePath, 'type' => 'featured']);
                }
                $createIntents->completeRedirect(
                    $request,
                    route('admin.levels.index'),
                    302,
                    Level::class,
                    $level->id
                );
            }, 3);
        } catch (\Throwable $exception) {
            if ($imagePath) app(StoredFileDeletionService::class)->deleteOrQueue('public', $imagePath);
            throw $exception;
        }

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم إضافة المستوى بنجاح');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function edit(Level $level)
    {
        $designSettings = $this->getDesignSettings();
        $editorVersion = $this->editorVersion($level);
        return view('admin.levels.edit', compact('level', 'designSettings', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Level $level)
    {
        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:1000',
            'description_en' => 'nullable|string|max:1000',
            'badge_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'order' => 'nullable|integer|min:1|max:1000',
            'editor_version' => 'required|string|size:64',
        ]);

        $editorVersion = (string) $validated['editor_version'];
        unset($validated['badge_image']);
        unset($validated['editor_version']);
        $newImagePath = $request->hasFile('badge_image')
            ? app(StoredFileDeletionService::class)
                ->storeTrackedUpload($request->file('badge_image'), 'levels')
            : null;
        if ($request->hasFile('badge_image') && (!is_string($newImagePath) || $newImagePath === '')) {
            throw new \RuntimeException('Level badge storage failed');
        }
        $legacyImagePath = null;
        try {
            DB::transaction(function () use (
                $level,
                $validated,
                $editorVersion,
                $newImagePath,
                &$legacyImagePath
            ): void {
                $locked = Level::query()->whereKey($level->id)->lockForUpdate()->firstOrFail();
                if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => "عدّل شخص آخر هذا المستوى\nأعد تحميل الصفحة قبل الحفظ",
                    ]);
                }
                $locked->update($validated);
                if ($newImagePath) {
                    $legacyImagePath = (string) ($locked->badge_image ?? '');
                    $locked->forceFill(['badge_image' => null])->save();
                    $oldPhotos = $locked->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $locked->allPhotos()->create(['path' => $newImagePath, 'type' => 'featured']);
                    $oldPhotos->each->delete();
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($newImagePath) app(StoredFileDeletionService::class)->deleteOrQueue('public', $newImagePath);
            throw $exception;
        }
        $legacyImagePath = ltrim(trim((string) $legacyImagePath), '/');
        if (
            $legacyImagePath !== ''
            && !filter_var($legacyImagePath, FILTER_VALIDATE_URL)
            && !str_starts_with($legacyImagePath, 'assets/')
        ) {
            app(StoredFileDeletionService::class)->deleteOrQueue('public', $legacyImagePath);
        }

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم تحديث المستوى بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Level  $level
     * @return \Illuminate\Http\Response
     */
    public function destroy(Level $level)
    {
        $blocked = DB::transaction(function () use ($level): bool {
            $locked = Level::query()->whereKey($level->id)->lockForUpdate()->firstOrFail();
            if ($locked->courses()->exists() || $locked->users()->exists()) return true;
            $locked->delete();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->route('admin.levels.index')
                ->with('error', 'لا يمكن حذف مستوى مرتبط بكورسات أو طلاب');
        }

        return redirect()->route('admin.levels.index')
            ->with('success', 'تم حذف المستوى بنجاح');
    }

    private function editorVersion(Level $level): string
    {
        return hash('sha256', json_encode([
            $level->name_ar,
            $level->name_en,
            $level->description_ar,
            $level->description_en,
            $level->order,
            $level->badge_image,
            $level->photo?->path,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
