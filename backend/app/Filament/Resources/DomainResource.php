<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Resource policies use the same permissions as the owning classic workflow.
 * Subclasses must explicitly wire their owning service before exposing writes.
 *
 * @template TModel of Model
 *
 * @extends resource<TModel>
 */
abstract class DomainResource extends Resource
{
    protected static bool $isDiscovered = false;

    protected static string $permissionRoute;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $operation = match ($action) {
            'viewAny', 'view' => ['index', 'GET'],
            'create' => ['store', 'POST'],
            'update' => ['update', 'PATCH'],
            'delete', 'deleteAny' => ['destroy', 'DELETE'],
            default => null,
        };
        $user = auth('web')->user();

        return $operation && $user?->active && app(AdminPermissionMatrix::class)->allows($user, static::$permissionRoute.'.'.$operation[0], $operation[1])
            ? Response::allow() : Response::deny();
    }
}
