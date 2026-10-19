<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\PublicContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PublicContentRequest;
use App\Services\AdminPublicContentAuthoringService;
use Illuminate\Http\RedirectResponse;

class AboutsController extends Controller
{
    public function __construct(private readonly AdminPublicContentAuthoringService $content)
    {
        $this->middleware('auth');
    }

    public function privacy(): RedirectResponse
    {
        return redirect(PublicContent::getUrl(['tab' => 'privacy']));
    }

    public function policy(): RedirectResponse
    {
        return redirect(PublicContent::getUrl(['tab' => 'policy']));
    }

    public function about(): RedirectResponse
    {
        return redirect(PublicContent::getUrl(['tab' => 'about']));
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
