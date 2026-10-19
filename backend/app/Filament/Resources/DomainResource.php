<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use Filament\Resources\Resource;
use Filament\Schemas\Components\View;
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

    /** Public, non-sensitive fields that may be recovered on this device. */
    abstract public static function draftFields(): array;

    /** Map aggregate-level refusals to an actual field in this resource's schema. */
    public static function validationFieldMap(): array
    {
        return [];
    }

    public static function draftFileFields(): array
    {
        return [];
    }

    public static function draftOperation(): string
    {
        return 'filament.'.str_replace('admin.', '', static::$permissionRoute).'.store';
    }

    protected static function draftRecovery(): View
    {
        return View::make('filament.forms.authoring-draft')->key('authoring-draft-recovery')->columnSpanFull();
    }

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
