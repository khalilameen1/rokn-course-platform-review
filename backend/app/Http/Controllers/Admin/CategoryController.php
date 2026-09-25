<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryRequest;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCategoryAuthoringService;
use App\Support\CategoryEditorVersion;

class CategoryController extends Controller
{
    public function __construct(private readonly AdminCategoryAuthoringService $authoring)
    {
    }

    public function index()
    {
        $categories = Category::get();

        return view('admin.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('admin.categories.create');
    }

    public function store(CategoryRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $this->authoring->create(
            $request->validated(), (string) $request->validated('authoring_request_id'), $request->file('image'),
            function (Category $category) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request, route('admin.categories.index'), 302, Category::class, $category->id
                );
            }
        );

        return redirect()->route('admin.categories.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    public function edit(Category $category)
    {
        $editorVersion = CategoryEditorVersion::for($category);

        return view('admin.categories.edit', compact('category', 'editorVersion'));
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $version = $request->validate(['editor_version' => 'required|string|size:64']);
        $this->authoring->update(
            (int) $category->id, $request->validated(), (string) $version['editor_version'], $request->file('image')
        );

        return redirect()->route('admin.categories.index')->with('success', 'تم التعديل بنجاح');
    }

    public function destroy(Category $category)
    {
        $this->authoring->delete((int) $category->id);

        return redirect()->route('admin.categories.index')->with('success', 'تم الحذف بنجاح ');
    }
}
