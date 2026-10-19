<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\CouponResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Models\Coupon;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCouponAuthoringService;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function __construct(
        private readonly AdminCouponAuthoringService $authoring,
    ) {}

    public function index()
    {
        return redirect(CouponResource::getUrl());
    }

    public function create()
    {
        return redirect(CouponResource::getUrl('create'));
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
        return redirect(CouponResource::getUrl('edit', ['record' => $coupon->id]));
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

    public function destroy(EditorVersionRequest $request, Coupon $coupon)
    {
        $validated = $request->validated();
        $this->authoring->delete((int) $coupon->id, (string) $validated['editor_version']);

        return redirect()->route('admin.coupons.index')->with('success', 'تم الحذف بنجاح ');
    }
}
