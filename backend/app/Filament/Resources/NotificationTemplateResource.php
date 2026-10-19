<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationTemplateResource\NotificationTemplateSchema;
use App\Filament\Resources\NotificationTemplateResource\Pages\ListNotificationTemplates;
use App\Models\AdminNotification;
use App\Services\AdminNotificationTemplateReadService;
use App\Support\AdminStudentSearch;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/** @extends DomainResource<AdminNotification> */
final class NotificationTemplateResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static ?string $model = AdminNotification::class;

    protected static ?string $slug = 'notification-templates';

    protected static ?string $modelLabel = 'قالب إشعار';

    protected static ?string $pluralModelLabel = 'قوالب الإشعارات';

    protected static string|\UnitEnum|null $navigationGroup = 'التطبيق';

    protected static ?int $navigationSort = 15;

    protected static string $permissionRoute = 'admin.admin_notifications';

    public static function draftFields(): array
    {
        return ['surface', 'title_ar', 'title_en', 'description_ar', 'description_en', 'action_label_ar', 'action_label_en',
            'secondary_action_label_ar', 'secondary_action_label_en', 'link', 'is_active', 'is_dismissible', 'priority', 'cooldown_hours', 'starts_at', 'ends_at'];
    }

    public static function draftFileFields(): array
    {
        return ['image'];
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminNotificationTemplateReadService::class)->query();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([self::draftRecovery(), ...NotificationTemplateSchema::components()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('photo.path')->label('الصورة')->disk('public')->imageHeight(48),
            TextColumn::make('title_ar')->label('القالب')->wrap()->description(fn (AdminNotification $record): HtmlString => new HtmlString(
                e(($record->is_active ? 'مفعّل' : 'موقوف').' · '.(AdminNotification::SURFACES[$record->surface] ?? 'غير محدد')).'<br><bdi dir="ltr">'.e($record->system_key ?: '#'.$record->id).'</bdi>')),
            TextColumn::make('type')->label('النوع')->visibleFrom('md')->state(fn (AdminNotification $record): string => $record->isSystemTemplate() ? 'حدث تلقائي' : 'إعلان يدوي'),
            TextColumn::make('priority')->label('الأولوية')->numeric()->sortable()->visibleFrom('md'),
            TextColumn::make('cooldown_hours')->label('سياسة الظهور')->visibleFrom('md')
                ->formatStateUsing(fn (AdminNotification $record): string => $record->system_key === 'guest_registration_prompt'
                    ? 'مرة واحدة على الجهاز' : ($record->cooldown_hours ? $record->cooldown_hours.' ساعة بين الظهورين' : 'بدون مهلة')),
        ])->searchable()->searchPlaceholder('عنوان القالب أو نصه أو الحدث')
            ->searchUsing(fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                ->whereRaw("title_ar LIKE ? ESCAPE '!'", ['%'.AdminStudentSearch::escape($search).'%'])
                ->orWhereRaw("description_ar LIKE ? ESCAPE '!'", ['%'.AdminStudentSearch::escape($search).'%'])
                ->orWhereRaw("system_key LIKE ? ESCAPE '!'", ['%'.AdminStudentSearch::escape($search).'%'])))
            ->filters([SelectFilter::make('surface')->label('مكان الاستخدام')->options(AdminNotification::SURFACES), TernaryFilter::make('is_active')->label('مفعّل')])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('priority')->orderByDesc('updated_at')->orderByDesc('id'))
            ->defaultPaginationPageOption(30)->paginated([10, 30, 50, 100])->poll('30s')->recordUrl(null)
            ->recordActions([ActionGroup::make([
                Action::make('edit')->label('تعديل')->authorize(fn (AdminNotification $record): bool => self::canEdit($record))
                    ->url(fn (AdminNotification $record): string => self::getUrl('edit', ['record' => $record->id])),
                Action::make('remove')->label(fn (AdminNotification $record): string => $record->isSystemTemplate() ? 'إيقاف' : 'حذف')->color('danger')
                    ->authorize(fn (AdminNotification $record): bool => self::canDelete($record))->requiresConfirmation()
                    ->modalDescription(fn (AdminNotification $record): string => $record->isSystemTemplate() ? 'يتوقف القالب مع الاحتفاظ بربطه بالحدث' : 'يُحذف الإعلان اليدوي')
                    ->mountUsing(fn (AdminNotification $record, ListNotificationTemplates $livewire) => $livewire->remember($record))
                    ->action(fn (AdminNotification $record, ListNotificationTemplates $livewire) => $livewire->remove($record)),
            ])->label('إجراءات القالب')])->emptyStateHeading('لا توجد قوالب بهذه التصفيات')->emptyStateDescription('');
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationTemplates::route('/'),
            'create' => NotificationTemplateResource\Pages\CreateNotificationTemplate::route('/create'),
            'edit' => NotificationTemplateResource\Pages\EditNotificationTemplate::route('/{record}/edit')];
    }
}
