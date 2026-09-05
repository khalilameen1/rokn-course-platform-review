<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\About;
use App\Support\AdminEditorVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\AdminSingletonLock;

class AboutsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    private function aboutForDisplay(): About
    {
        return About::query()->first() ?? new About();
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function privacy()
    {
        $about = $this->aboutForDisplay();
        return view('admin.abouts.privacy', [
            'about' => $about,
            'editorVersion' => $this->editorVersion($about),
        ]);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function policy()
    {
        $about = $this->aboutForDisplay();
        return view('admin.abouts.policy', [
            'about' => $about,
            'editorVersion' => $this->editorVersion($about),
        ]);
    }

    public function about()
    {
        $about = $this->aboutForDisplay();
        return view('admin.abouts.about', [
            'about' => $about,
            'editorVersion' => $this->editorVersion($about),
        ]);
    }
    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request)
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
        DB::transaction(function () use ($validated, $editorVersion): void {
            AdminSingletonLock::acquire('abouts');
            $about = About::query()->lockForUpdate()->first();
            $snapshot = $about ?? new About();
            if (!hash_equals($this->editorVersion($snapshot), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّر النص المنشور منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                ]);
            }
            $about ??= About::query()->create([
                'about_ar' => '',
                'about_en' => '',
                'privacy_ar' => '',
                'privacy_en' => '',
                'policy_ar' => '',
                'policy_en' => '',
            ]);
            $about->update($validated);
        }, 3);

        return redirect()->back()->with('success', 'تم تحديث النص المنشور');
    }

    private function editorVersion(About $about): string
    {
        return AdminEditorVersion::for($about, [
            'about_ar', 'about_en', 'privacy_ar', 'privacy_en', 'policy_ar', 'policy_en',
        ]);
    }
}
