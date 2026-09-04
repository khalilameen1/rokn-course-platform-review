<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Services\StoredFileDeletionService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $categories  = Category::get();

        return view('admin.categories.index', compact('categories'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.categories.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CategoryRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $requestId = (string) $request->validated('authoring_request_id');
        $imagePath = $request->hasFile('image')
            ? app(StoredFileDeletionService::class)
                ->storeTrackedUpload(
                    $request->file('image'),
                    'categories',
                    'public',
                    60,
                    'admin-category|'.strtolower($requestId).'|'.hash_file('sha256', $request->file('image')->getRealPath())
                )
            : null;
        if ($request->hasFile('image') && (!is_string($imagePath) || $imagePath === '')) {
            throw new \RuntimeException('Category image storage failed');
        }
        try {
            DB::transaction(function () use ($request, $imagePath, $requestId, $createIntents): void {
                $category = Category::query()->where('authoring_request_id', $requestId)
                    ->lockForUpdate()->first();
                if (!$category) {
                    $category = Category::create(
                        $request->safe()->except('image') + ['authoring_request_id' => $requestId]
                    );
                }
                if ($imagePath) {
                    $category->allPhotos()->firstOrCreate(['path' => $imagePath, 'type' => 'featured']);
                }
                $createIntents->completeRedirect(
                    $request,
                    route('admin.categories.index'),
                    302,
                    Category::class,
                    $category->id
                );
            }, 3);
        } catch (\Throwable $exception) {
            if ($imagePath) app(StoredFileDeletionService::class)->deleteOrQueue('public', $imagePath);
            throw $exception;
        }

        return redirect()->route('admin.categories.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function edit(Category $category)
    {
         $editorVersion = $this->editorVersion($category);
         return view('admin.categories.edit', compact('category', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function update(CategoryRequest $request, Category $category)
    {
        $request->validate(['editor_version' => 'required|string|size:64']);
        $newImagePath = $request->hasFile('image')
            ? app(StoredFileDeletionService::class)
                ->storeTrackedUpload($request->file('image'), 'categories')
            : null;
        if ($request->hasFile('image') && (!is_string($newImagePath) || $newImagePath === '')) {
            throw new \RuntimeException('Category image storage failed');
        }
        try {
            DB::transaction(function () use ($request, $category, $newImagePath): void {
                $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
                if (!hash_equals($this->editorVersion($locked), (string) $request->input('editor_version'))) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'عدّل شخص آخر هذا القسم\nأعد تحميل الصفحة قبل الحفظ',
                    ]);
                }
                $locked->update($request->safe()->except(['image', 'authoring_request_id']));
                if ($newImagePath) {
                    $oldPhotos = $locked->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $locked->allPhotos()->create(['path' => $newImagePath, 'type' => 'featured']);
                    $oldPhotos->each->delete();
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($newImagePath) app(StoredFileDeletionService::class)->deleteOrQueue('public', $newImagePath);
            throw $exception;
        }

        return redirect()->route('admin.categories.index')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Category  $category
     * @return \Illuminate\Http\Response
     */
    public function destroy(Category $category)
    {
        DB::transaction(function () use ($category): void {
            $locked = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $locked->delete();
        }, 3);

        return redirect()->route('admin.categories.index')->with('success', 'تم الحذف بنجاح ');
    }

    private function editorVersion(Category $category): string
    {
        return hash('sha256', json_encode([
            $category->name_ar,
            $category->name_en,
            $category->type,
            $category->description_ar,
            $category->description_en,
            $category->photo?->path,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
