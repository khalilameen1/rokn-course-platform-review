<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminPublicContentAuthoringService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'about_ar' => 'nullable|string|max:100000',
            'about_en' => 'nullable|string|max:100000',
            'privacy_ar' => 'nullable|string|max:100000',
            'privacy_en' => 'nullable|string|max:100000',
            'policy_ar' => 'nullable|string|max:100000',
            'policy_en' => 'nullable|string|max:100000',
            'editor_version' => 'required|string|size:64',
        ]);
        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        $this->content->save($validated, $editorVersion);

        return redirect()->back()->with('success', 'تم تحديث النص المنشور');
    }
}
