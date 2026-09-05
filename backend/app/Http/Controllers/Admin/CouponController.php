<?php

namespace App\Http\Controllers\Admin;

use App\Models\Coupon;
use App\Models\Course;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\BusinessClock;
use App\Support\AdminEditorVersion;
use Illuminate\Http\Request;
use App\Services\StoredFileDeletionService;

class CouponController extends Controller
{
    /**
     * Display a couponing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $coupons = Coupon::query()
            ->with('course:id,name_ar')
            ->withCount('redemptions')
            ->latest()
            ->paginate(25)
            ->withQueryString();
        $editorVersions = $coupons->mapWithKeys(fn (Coupon $coupon): array => [
            $coupon->id => $this->editorVersion($coupon),
        ]);

        return view('admin.coupons.index', compact('coupons', 'editorVersions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $courses = Course::query()->orderBy('name_ar')->get(['id', 'name_ar']);
        return view('admin.coupons.create', compact('courses'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CouponRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $payload = $request->safe()->except('image');
        $payload['starts_at'] = BusinessClock::localInputToUtc($payload['starts_at'] ?? null);
        $requestId = (string) $payload['authoring_request_id'];
        $coupon = Coupon::withTrashed()->where('authoring_request_id', $requestId)->first();
        if (!$coupon) {
            $coupon = DB::transaction(function () use (
                $request,
                $payload,
                $createIntents
            ): Coupon {
                $coupon = Coupon::create($payload);
                $createIntents->checkpointResource($request, Coupon::class, $coupon->id);
                return $coupon;
            }, 3);
        } else {
            DB::transaction(function () use ($request, $coupon, $createIntents): void {
                Coupon::withTrashed()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
                $createIntents->checkpointResource($request, Coupon::class, $coupon->id);
            }, 3);
        }
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $coupon->storeImage(
                $file,
                'coupons',
                'featured',
                'admin-coupon|'.strtolower($requestId).'|'.hash_file('sha256', $file->getRealPath())
            );
        }

        DB::transaction(function () use ($request, $coupon, $createIntents): void {
            $locked = Coupon::withTrashed()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            $createIntents->completeRedirect(
                $request,
                route('admin.coupons.index'),
                302,
                Coupon::class,
                $locked->id
            );
        }, 3);

        return redirect()->route('admin.coupons.index')->with('success', 'تمت الإضافة بنجاح ');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function edit(Coupon $coupon)
    {
         $courses = Course::query()->orderBy('name_ar')->get(['id', 'name_ar']);
         $editorVersion = $this->editorVersion($coupon);
         return view('admin.coupons.edit', compact('coupon', 'courses', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function update(
        CouponRequest $request,
        Coupon $coupon,
        StoredFileDeletionService $storedFiles
    )
    {
        $storedImagePath = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $storedImagePath = $storedFiles->storeTrackedUpload(
                $image,
                'coupons',
                'public',
                60,
                implode('|', [
                    'admin-coupon-update',
                    $coupon->id,
                    (string) $request->input('editor_version'),
                    hash_file('sha256', $image->getRealPath()),
                ])
            );
        }
        $committed = false;
        try {
            $payload = $request->safe()->except(['image', 'authoring_request_id', 'editor_version']);
            $payload['starts_at'] = BusinessClock::localInputToUtc($payload['starts_at'] ?? null);
            DB::transaction(function () use (
                $request,
                $coupon,
                $payload,
                $storedImagePath
            ): void {
                $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
                if (!hash_equals($this->editorVersion($locked), (string) $request->input('editor_version'))) {
                    throw ValidationException::withMessages([
                        'editor_version' => "تغيّر كود الخصم منذ فتح الصفحة\nأعد تحميله قبل الحفظ",
                    ]);
                }
                $locked->update($payload);
                if (is_string($storedImagePath) && $storedImagePath !== '') {
                    $oldPhotos = $locked->allPhotos()->where('type', 'featured')
                        ->lockForUpdate()->get();
                    $newPhoto = $locked->allPhotos()->firstOrCreate([
                        'path' => $storedImagePath,
                        'type' => 'featured',
                    ]);
                    $oldPhotos->where('id', '!=', $newPhoto->id)->each->delete();
                }
            }, 3);
            $committed = true;
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'code' => [$exception->getMessage()],
            ]);
        } finally {
            if (!$committed && is_string($storedImagePath) && $storedImagePath !== '') {
                $storedFiles->deleteOrQueue('public', $storedImagePath);
            }
        }

        return redirect()->route('admin.coupons.index')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Coupon  $coupon
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Coupon $coupon)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($coupon, $validated): void {
            $locked = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّر كود الخصم منذ فتح الصفحة\nأعد تحميله قبل الحذف",
                ]);
            }
            $locked->delete();
        }, 3);

        return redirect()->route('admin.coupons.index')->with('success', 'تم الحذف بنجاح ');
    }

    private function editorVersion(Coupon $coupon): string
    {
        $coupon->loadMissing('photo');
        return hash('sha256', json_encode([
            AdminEditorVersion::for($coupon, [
            'name_ar', 'name_en', 'code', 'course_id', 'starts_at', 'balance',
            'max_redemptions', 'expiry_date', 'active',
            ]),
            (string) ($coupon->photo?->path ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
