<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\ModeratorResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ModeratorMfaResetRequest;
use App\Http\Requests\Admin\ModeratorProfileRequest;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminMfaResetService;
use App\Services\AdminModeratorAuthoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class ModeratorController extends Controller
{
    public function __construct(private readonly AdminModeratorAuthoringService $authoring) {}

    public function index(): RedirectResponse
    {
        return redirect(ModeratorResource::getUrl());
    }

    public function create(): RedirectResponse
    {
        return redirect(ModeratorResource::getUrl('create'));
    }

    public function store(ModeratorProfileRequest $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $this->authoring->create($request->validated(), function (User $moderator) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request, route('admin.moderators.index'), 302, User::class, $moderator->id
            );
        });

        return redirect()->route('admin.moderators.index')
            ->with('success', 'تم إنشاء حساب مسؤول المحتوى. سيُطلب منه إعداد التحقق بخطوتين عند الدخول.');
    }

    public function edit(User $moderator): RedirectResponse
    {
        $this->assertModerator($moderator);

        return redirect(ModeratorResource::getUrl('edit', ['record' => $moderator->id]));
    }

    public function update(ModeratorProfileRequest $request, User $moderator): RedirectResponse
    {
        $this->assertModerator($moderator);
        $data = $request->validated();
        $this->authoring->update((int) $moderator->id, $data, (string) $data['editor_version']);

        return redirect()->route('admin.moderators.index')->with('success', 'تم تحديث حساب مسؤول المحتوى.');
    }

    /**
     * Administrator recovery for a moderator who lost the authenticator and
     * recovery codes. Destructive, so it uses GitHub's "type the name to
     * confirm" pattern: the administrator must type the moderator's email.
     */
    public function resetMfa(ModeratorMfaResetRequest $request, User $moderator, AdminMfaResetService $resets): RedirectResponse
    {
        $this->assertModerator($moderator);
        abort_if((int) $moderator->getKey() === (int) $request->user()?->getKey(), 403);
        if (! hash_equals(
            strtolower(trim((string) $moderator->email)),
            strtolower(trim((string) $request->input('confirm_email')))
        )) {
            throw ValidationException::withMessages([
                'confirm_email' => ['البريد المكتوب لا يطابق بريد هذا الحساب'],
            ]);
        }

        $resets->reset($moderator);

        return redirect()->route('admin.moderators.edit', $moderator)
            ->with('success', 'أُعيد ضبط التحقق بخطوتين. سيُطلب من مسؤول المحتوى إعداد تطبيق المصادقة عند دخوله التالي.');
    }

    private function assertModerator(User $user): void
    {
        abort_unless(strtolower((string) $user->role) === 'moderator', 404);
    }
}
