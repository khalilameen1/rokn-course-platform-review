<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminModeratorAuthoringService;
use App\Support\ModeratorEditorVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ModeratorController extends Controller
{
    public function __construct(private readonly AdminModeratorAuthoringService $authoring)
    {
    }

    public function index(): View
    {
        $moderators = User::query()->where('role', 'moderator')->latest('id')->paginate(25);

        return view('admin.moderators.index', compact('moderators'));
    }

    public function create(): View
    {
        return view('admin.moderators.create');
    }

    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $this->authoring->create($this->validated($request), function (User $moderator) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request, route('admin.moderators.index'), 302, User::class, $moderator->id
            );
        });

        return redirect()->route('admin.moderators.index')
            ->with('success', 'تم إنشاء حساب مسؤول المحتوى. سيُطلب منه إعداد التحقق بخطوتين عند الدخول.');
    }

    public function edit(User $moderator): View
    {
        $this->assertModerator($moderator);
        $editorVersion = ModeratorEditorVersion::for($moderator);

        return view('admin.moderators.edit', compact('moderator', 'editorVersion'));
    }

    public function update(Request $request, User $moderator): RedirectResponse
    {
        $this->assertModerator($moderator);
        $data = $this->validated($request, $moderator);
        $this->authoring->update((int) $moderator->id, $data, (string) $data['editor_version']);

        return redirect()->route('admin.moderators.index')->with('success', 'تم تحديث حساب مسؤول المحتوى.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $moderator = null): array
    {
        $isEdit = $moderator !== null;
        $manageCredentials = !$isEdit || $request->boolean('manage_credentials');

        return $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'manage_credentials' => $isEdit ? ['nullable', 'boolean'] : ['prohibited'],
            'email' => $manageCredentials
                ? ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($moderator?->id)]
                : ['exclude'],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users', 'phone')->ignore($moderator?->id),
            ],
            'password' => $manageCredentials
                ? [$isEdit ? 'nullable' : 'required', 'string', 'min:10', 'confirmed']
                : ['exclude'],
            'password_confirmation' => $manageCredentials
                ? [$isEdit ? 'nullable' : 'required', 'same:password']
                : ['exclude'],
            'active' => ['nullable', 'boolean'],
            'authoring_request_id' => [$moderator ? 'nullable' : 'required', 'uuid'],
            'editor_version' => [$moderator ? 'required' : 'nullable', 'string', 'size:64'],
        ]);
    }

    private function assertModerator(User $user): void
    {
        abort_unless(strtolower((string) $user->role) === 'moderator', 404);
    }
}
