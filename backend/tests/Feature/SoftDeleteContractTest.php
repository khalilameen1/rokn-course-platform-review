<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CoinEarningMethod;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\CourseRating;
use App\Models\CourseSection;
use App\Models\DesignSetting;
use App\Models\Grade;
use App\Models\Link;
use App\Models\OperatingCostPool;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SoftDeleteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_queried_with_trashed_have_matching_schema_support(): void
    {
        $models = [
            User::class,
            Course::class,
            CourseSection::class,
            CoursePdf::class,
            CourseRating::class,
            CoinEarningMethod::class,
            Coupon::class,
            Bill::class,
            DesignSetting::class,
            Grade::class,
            Link::class,
            OperatingCostPool::class,
            Order::class,
            PaymentMethod::class,
        ];

        foreach ($models as $modelClass) {
            $model = new $modelClass;
            self::assertContains(
                SoftDeletes::class,
                class_uses_recursive($modelClass),
                $modelClass.' must use SoftDeletes before withTrashed is called.'
            );
            self::assertTrue(
                Schema::hasColumn($model->getTable(), $model->getDeletedAtColumn()),
                $modelClass.' must have a deleted_at column.'
            );
        }
    }

    public function test_a_model_booted_by_an_old_migration_uses_soft_deletes_after_migrations_end(): void
    {
        $method = CoinEarningMethod::query()->create([
            'title_ar' => 'مكافأة تحقق الحذف',
            'title_en' => 'Soft delete check',
            'coins_amount' => 1,
            'action_key' => 'soft-delete-contract-check',
            'is_active' => true,
            'is_repeatable' => false,
        ]);

        $method->delete();

        self::assertNull(CoinEarningMethod::query()->find($method->id));
        self::assertNotNull(CoinEarningMethod::withTrashed()->find($method->id));
    }
}
