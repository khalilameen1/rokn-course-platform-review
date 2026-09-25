<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Package;
use App\Support\PackageEditorVersion;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Store product definitions, not order settlement or store price verification. */
final class AdminPackageAuthoringService
{
    /** @param array<string,mixed> $validated
     *  @param Closure(Package):void $complete Receipt completion in the same transaction.
     */
    public function create(array $validated, Closure $complete): Package
    {
        return DB::transaction(function () use ($validated, $complete): Package {
            $package = new Package();
            $package->fill($this->fields($validated) + ['sort_order' => 100]);
            $this->assertPurchasableWhenActive($package);
            $package->save();
            $complete($package);

            return $package;
        }, 3);
    }

    /** @param array<string,mixed> $validated */
    public function update(int $id, array $validated, string $editorVersion): void
    {
        DB::transaction(function () use ($id, $validated, $editorVersion): void {
            $package = Package::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($package, $editorVersion, 'الحفظ');
            $package->fill($this->fields($validated));
            $this->assertPurchasableWhenActive($package);
            // The model owns immutable issued SKU/coin contracts for all writers.
            $package->save();
        }, 3);
    }

    /** Issued store contracts and financial history are never deleted. */
    public function deleteIfUnused(int $id, string $editorVersion): bool
    {
        return DB::transaction(function () use ($id, $editorVersion): bool {
            $package = Package::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($package, $editorVersion, 'الحذف');
            if ($package->orders()->exists() || $package->storePurchases()->exists()
                || filled($package->google_product_id) || filled($package->apple_product_id)) {
                return false;
            }
            $package->delete();

            return true;
        }, 3);
    }

    private function assertPurchasableWhenActive(Package $package): void
    {
        if ($package->is_active && !$package->hasPurchasableChannel()) {
            throw ValidationException::withMessages([
                'channels' => ['فعّل كاشير أو اربط منتجًا مفعّلًا في أحد المتجرين قبل إظهار الباقة'],
            ]);
        }
    }

    private function assertCurrentVersion(Package $package, string $version, string $operation): void
    {
        if (!hash_equals(PackageEditorVersion::for($package), $version)) {
            throw ValidationException::withMessages([
                'editor_version' => "تغيّرت الباقة منذ فتح الصفحة\nأعد تحميلها قبل {$operation}",
            ]);
        }
    }

    private function fields(array $validated): array
    {
        return Arr::only($validated, (new Package())->getFillable());
    }
}
