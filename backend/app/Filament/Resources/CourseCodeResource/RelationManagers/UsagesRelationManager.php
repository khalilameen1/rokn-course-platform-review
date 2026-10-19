<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseCodeResource\RelationManagers;

use App\Filament\Resources\CourseCodeResource;
use App\Models\CourseCodeUsage;
use App\Support\BusinessClock;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

final class UsagesRelationManager extends RelationManager
{
    protected static string $relationship = 'usages';

    protected static ?string $title = 'سجل الاستخدام';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return CourseCodeResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user:id,name,name_ar,name_en,email'))
            ->columns([
                TextColumn::make('user.name')->label('الطالب')->placeholder('حساب محذوف')->wrap()
                    ->description(fn (CourseCodeUsage $record): ?string => $record->user?->email),
                TextColumn::make('used_at')->label('بتوقيت القاهرة')->sortable()
                    ->formatStateUsing(fn ($state): HtmlString => new HtmlString('<bdi dir="ltr">'.e(BusinessClock::format($state, 'Y-m-d H:i:s')).'</bdi>')),
                TextColumn::make('ip_address')->label('عنوان الشبكة')->placeholder('غير مسجل')->visibleFrom('md')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('user_agent')->label('الجهاز')->placeholder('غير مسجل')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])->defaultSort('used_at', 'desc')->defaultPaginationPageOption(25)->paginated([25, 50, 100])
            ->poll('30s')->emptyStateHeading('لم يُستخدم الكود بعد')->emptyStateDescription('')->recordActions([])->toolbarActions([]);
    }
}
