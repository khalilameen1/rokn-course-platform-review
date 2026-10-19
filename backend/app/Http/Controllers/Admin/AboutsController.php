<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublicContentRequest;
use App\Services\AdminPublicContentAuthoringService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AboutsController extends Controller
{
    public function __construct(private readonly AdminPublicContentAuthoringService $content)
    {
        $this->middleware('auth');
    }

    public function privacy(): View
    {
        return view('admin.abouts.privacy', $this->content->editorState());
    }

    public function policy(): View
    {
        return view('admin.abouts.policy', $this->content->editorState());
    }

    public function about(): View
    {
        return view('admin.abouts.about', $this->content->editorState());
    }

    public function update(PublicContentRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        $this->content->save($validated, $editorVersion);

        return redirect()->back()->with('success', 'تم تحديث النص المنشور');
    }
}
