<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminEconomyReadService;
use App\Services\AdminPaymentOperationsReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\AdminEditorVersion;

class PackageController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(AdminEconomyReadService $economy)
    {
        $packages = $economy->packages();
        $editorVersions = $packages->getCollection()->mapWithKeys(fn (Package $package): array => [
            $package->id => $this->editorVersion($package),
        ]);
        return view('admin.packages.index', compact('packages', 'editorVersions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('admin.packages.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $this->validated($request);

        DB::transaction(function () use ($request, $validated, $createIntents): void {
            $package = Package::create($validated);
            $createIntents->completeRedirect(
                $request,
                route('admin.packages.index'),
                302,
                Package::class,
                $package->id
            );
        }, 3);

        return redirect()->route('admin.packages.index')->with('success', 'تم إضافة الباقة بنجاح');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Package $package = null): array
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
            'direct_enabled' => $request->boolean('direct_enabled'),
            'google_enabled' => $request->boolean('google_enabled'),
            'apple_enabled' => $request->boolean('apple_enabled'),
        ]);

        $validated = $request->validate([
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'price' => 'required|numeric|min:0.01',
            'coins' => 'required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'required|boolean',
            'direct_enabled' => 'required|boolean',
            'google_product_id' => [
                'nullable', 'required_if:google_enabled,1', 'string', 'max:191',
                'regex:/^[a-z0-9._]+$/',
                Rule::unique('packages', 'google_product_id')->ignore($package?->id),
            ],
            'apple_product_id' => [
                'nullable', 'required_if:apple_enabled,1', 'string', 'max:191',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('packages', 'apple_product_id')->ignore($package?->id),
            ],
            'google_enabled' => 'required|boolean',
            'apple_enabled' => 'required|boolean',
            'authoring_request_id' => [$package ? 'nullable' : 'required', 'uuid'],
        ]);
        unset($validated['authoring_request_id']);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 100);

        $candidate = $package ? clone $package : new Package();
        $candidate->forceFill($validated);
        if ($candidate->is_active && !$candidate->hasPurchasableChannel()) {
            throw ValidationException::withMessages([
                'channels' => ['فعّل كاشير أو اربط منتجًا مفعّلًا في أحد المتجرين قبل إظهار الباقة'],
            ]);
        }

        return $validated;
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function show(Package $package, AdminPaymentOperationsReadService $payments)
    {
        return view('admin.packages.show', [
            'package' => $package,
            'orders' => $payments->packageOrders($package),
            'paymentMethodLabels' => $payments->channelLabels(),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function edit(Package $package)
    {
        $editorVersion = $this->editorVersion($package);
        return view('admin.packages.edit', compact('package', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Package $package)
    {
        $request->validate(['editor_version' => 'required|string|size:64']);
        $editorVersion = (string) $request->input('editor_version');
        $validated = $this->validated($request, $package);

        try {
            DB::transaction(function () use ($package, $validated, $editorVersion): void {
                $locked = Package::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
                if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'تغيّرت الباقة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                    ]);
                }
                $locked->update($validated);
            }, 3);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'package' => [$exception->getMessage()],
            ]);
        }

        return redirect()->route('admin.packages.index')->with('success', 'تم تحديث الباقة بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Package  $package
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Package $package)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        $blocked = DB::transaction(function () use ($package, $validated): bool {
            $locked = Package::query()->whereKey($package->id)->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت الباقة منذ فتح الصفحة\nأعد تحميلها قبل الحذف',
                ]);
            }
            if ($locked->orders()->exists() || $locked->storePurchases()->exists()
                || filled($locked->google_product_id) || filled($locked->apple_product_id)) {
                return true;
            }
            $locked->delete();
            return false;
        }, 3);
        if ($blocked) {
            return redirect()->back()->with(
                'error',
                'لا يمكن حذف باقة دخلت دورة بيع. عطّل قنواتها مع الاحتفاظ بالسجل المالي.'
            );
        }
        return redirect()->route('admin.packages.index')->with('success', 'تم حذف الباقة بنجاح');
    }

    private function editorVersion(Package $package): string
    {
        return AdminEditorVersion::for($package, [
            'name_ar', 'name_en', 'price', 'coins', 'is_active', 'direct_enabled',
            'sort_order',
            'google_product_id', 'apple_product_id', 'google_enabled', 'apple_enabled',
        ]);
    }
}
