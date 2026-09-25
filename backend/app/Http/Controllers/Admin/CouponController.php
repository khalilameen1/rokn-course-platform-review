<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Coupon;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminContentInventoryReadService;
use App\Services\AdminCouponAuthoringService;
use App\Support\CouponEditorVersion;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function __construct(
        private readonly AdminCouponAuthoringService $authoring,
        private readonly AdminContentInventoryReadService $inventory
    ) {
    }

    public function index()
    {
        $coupons = Coupon::query()->with('course:id,name_ar')->withCount('redemptions')
            ->latest()->paginate(25)->withQueryString();
        $editorVersions = $coupons->mapWithKeys(fn (Coupon $coupon): array => [
            $coupon->id => CouponEditorVersion::for($coupon),
        ]);

        return view('admin.coupons.index', compact('coupons', 'editorVersions'));
    }

    public function create()
    {
        $courses = $this->inventory->courses()->orderBy('name_ar')->get(['id', 'name_ar']);

        return view('admin.coupons.create', compact('courses'));
    }

    public function store(CouponRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $this->authoring->create(
            $request->validated(),
            (string) $request->input('authoring_request_id'),
            $request->file('image'),
            function (Coupon $coupon) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request, route('admin.coupons.index'), 302, Coupon::class, $coupon->id
                );
            }
        );

        return redirect()->route('admin.coupons.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    public function edit(Coupon $coupon)
    {
        $courses = $this->inventory->courses()->orderBy('name_ar')->get(['id', 'name_ar']);
        $editorVersion = CouponEditorVersion::for($coupon);

        return view('admin.coupons.edit', compact('coupon', 'courses', 'editorVersion'));
    }

    public function update(CouponRequest $request, Coupon $coupon)
    {
        try {
            $this->authoring->update(
                $coupon, $request->validated(),
                (string) $request->input('editor_version'), $request->file('image')
            );
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['code' => [$exception->getMessage()]]);
        }

        return redirect()->route('admin.coupons.index')->with('success', 'تم التعديل بنجاح');
    }

    public function destroy(Request $request, Coupon $coupon)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        $this->authoring->delete((int) $coupon->id, (string) $validated['editor_version']);

        return redirect()->route('admin.coupons.index')->with('success', 'تم الحذف بنجاح ');
    }
}
