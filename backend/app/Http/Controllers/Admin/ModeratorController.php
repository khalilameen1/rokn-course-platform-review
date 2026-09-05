<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Support\AdminEditorVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ModeratorController extends Controller
{
    public function index(): View
    {
        $moderators = User::query()
            ->where('role', 'moderator')
            ->latest('id')
            ->paginate(25);

        return view('admin.moderators.index', compact('moderators'));
    }

    public function create(): View
    {
        return view('admin.moderators.create');
    }

    public function store(
        Request $request,
        AdminAuthoringCreateIntentService $createIntents
    ): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data, $createIntents): void {
            $moderator = new User();
            $moderator->forceFill([
                'name_ar' => trim((string) $data['name_ar']),
                'name_en' => filled($data['name_en'] ?? null) ? trim((string) $data['name_en']) : null,
                'email' => strtolower(trim((string) $data['email'])),
                'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
                'password' => Hash::make((string) $data['password']),
                'role' => 'moderator',
                'active' => $request->boolean('active'),
                'email_verified_at' => now(),
            ])->save();
            $createIntents->completeRedirect(
                $request,
                route('admin.moderators.index'),
                302,
                User::class,
                $moderator->id
            );
        }, 3);

        return redirect()->route('admin.moderators.index')
            ->with('success', 'تم إنشاء حساب مسؤول المحتوى. سيُطلب منه إعداد التحقق بخطوتين عند الدخول.');
    }

    public function edit(User $moderator): View
    {
        $this->assertModerator($moderator);
        $editorVersion = $this->editorVersion($moderator);

        return view('admin.moderators.edit', compact('moderator', 'editorVersion'));
    }

    public function update(Request $request, User $moderator): RedirectResponse
    {
        $this->assertModerator($moderator);
        $data = $this->validated($request, $moderator);
        DB::transaction(function () use ($request, $moderator, $data): void {
            $locked = User::query()->whereKey($moderator->id)->where('role', 'moderator')
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $data['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت بيانات مسؤول المحتوى منذ فتح الصفحة\nأعد تحميلها قبل الحفظ"],
                ]);
            }

            $updates = [
                'name_ar' => trim((string) $data['name_ar']),
                'name_en' => filled($data['name_en'] ?? null) ? trim((string) $data['name_en']) : null,
                'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
                'active' => $request->boolean('active'),
                'profile_revision' => (int) $locked->profile_revision + 1,
            ];
            if (array_key_exists('email', $data)) {
                $email = strtolower(trim((string) $data['email']));
                $updates['email'] = $email;
                if (!hash_equals(strtolower(trim((string) $locked->email)), $email)) {
                    $updates['email_verified_at'] = null;
                }
            }
            if (filled($data['password'] ?? null)) {
                $updates['password'] = Hash::make((string) $data['password']);
            }
            $locked->forceFill($updates)->save();
        }, 3);

        return redirect()->route('admin.moderators.index')
            ->with('success', 'تم تحديث حساب مسؤول المحتوى.');
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

    private function editorVersion(User $moderator): string
    {
        return AdminEditorVersion::for($moderator, [
            'name_ar', 'name_en', 'email', 'phone', 'password', 'active',
            'profile_revision', 'email_verified_at',
        ]);
    }

    private function assertModerator(User $user): void
    {
        abort_unless(strtolower((string) $user->role) === 'moderator', 404);
    }
}
