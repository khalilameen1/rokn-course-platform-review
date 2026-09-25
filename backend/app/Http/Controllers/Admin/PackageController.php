<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminEconomyReadService;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\AdminPackageAuthoringService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\PackageEditorVersion;

class PackageController extends Controller
{
    public function __construct(private readonly AdminPackageAuthoringService $authoring)
    {
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(AdminEconomyReadService $economy)
    {
        $packages = $economy->packages();
        $editorVersions = $packages->getCollection()->mapWithKeys(fn (Package $package): array => [
            $package->id => PackageEditorVersion::for($package),
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

        $this->authoring->create($validated, function (Package $package) use ($request, $createIntents): void {
            $createIntents->completeRedirect(
                $request,
                route('admin.packages.index'),
                302,
                Package::class,
                $package->id
            );
        });

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
        $editorVersion = PackageEditorVersion::for($package);
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
            $this->authoring->update((int) $package->id, $validated, $editorVersion);
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
        if (!$this->authoring->deleteIfUnused((int) $package->id, (string) $validated['editor_version'])) {
            return redirect()->back()->with(
                'error',
                'لا يمكن حذف باقة دخلت دورة بيع. عطّل قنواتها مع الاحتفاظ بالسجل المالي.'
            );
        }
        return redirect()->route('admin.packages.index')->with('success', 'تم حذف الباقة بنجاح');
    }

}
