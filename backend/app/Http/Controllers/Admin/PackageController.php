<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Http\Requests\Admin\PackageProfileRequest;
use App\Models\Package;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminEconomyReadService;
use App\Services\AdminPackageAuthoringService;
use App\Services\AdminPaymentOperationsReadService;
use App\Support\PackageEditorVersion;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PackageController extends Controller
{
    public function __construct(private readonly AdminPackageAuthoringService $authoring) {}

    /**
     * Display a listing of the resource.
     *
     * @return Response
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
     * @return Response
     */
    public function create()
    {
        return view('admin.packages.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(PackageProfileRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $validated = $request->authoringData();

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

    /**
     * Display the specified resource.
     *
     * @return Response
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
     * @return Response
     */
    public function edit(Package $package)
    {
        $editorVersion = PackageEditorVersion::for($package);

        return view('admin.packages.edit', compact('package', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(PackageProfileRequest $request, Package $package)
    {
        $editorVersion = (string) $request->validated('editor_version');
        $validated = $request->authoringData();

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
     * @return Response
     */
    public function destroy(EditorVersionRequest $request, Package $package)
    {
        $validated = $request->validated();
        if (! $this->authoring->deleteIfUnused((int) $package->id, (string) $validated['editor_version'])) {
            return redirect()->back()->with(
                'error',
                'لا يمكن حذف باقة دخلت دورة بيع. عطّل قنواتها مع الاحتفاظ بالسجل المالي.'
            );
        }

        return redirect()->route('admin.packages.index')->with('success', 'تم حذف الباقة بنجاح');
    }
}
