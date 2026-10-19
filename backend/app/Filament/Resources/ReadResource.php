<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read projections never gain generic CRUD access to financial/account aggregates.
 *
 * @template TModel of Model
 *
 * @extends resource<TModel>
 */
abstract class ReadResource extends Resource
{
    protected static bool $isDiscovered = false;

    protected static string $legacyListRoute;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = auth('web')->user();
        $allowed = $user && $user->active
            && in_array($action, ['viewAny', 'view'], true)
            && app(AdminPermissionMatrix::class)->allows($user, static::$legacyListRoute, 'GET');

        return $allowed ? Response::allow() : Response::deny();
    }
}
