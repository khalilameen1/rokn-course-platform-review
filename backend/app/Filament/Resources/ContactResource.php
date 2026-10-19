<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Models\Contact;
use App\Services\AdminContactReadService;
use App\Support\BusinessClock;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** @extends ReadResource<Contact> */
final class ContactResource extends ReadResource
{
    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'المراجعة';

    protected static ?int $navigationSort = 40;

    protected static ?string $model = Contact::class;

    protected static ?string $modelLabel = 'رسالة تواصل';

    protected static ?string $pluralModelLabel = 'رسائل التواصل';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string $legacyListRoute = 'admin.contacts.index';

    public const OUTCOMES = [
        'self_service_completed' => 'حذف صاحب الحساب حسابه من التطبيق',
        'no_account_found' => 'لا يوجد حساب مطابق بعد التحقق',
        'duplicate' => 'طلب مكرر',
        'withdrawn' => 'صاحب الطلب تراجع عنه',
    ];

    public static function allows(string $action, string $method = 'POST'): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.contacts.'.$action, $method);
    }

    public static function status(Contact $record): string
    {
        return $record->isAccountDeletionRequest()
            ? ($record->isResolved() ? 'مغلق' : ($record->isProcessing() ? 'قيد المعالجة' : 'طلب جديد'))
            : ($record->read ? 'مقروءة' : 'غير مقروءة');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminContactReadService::class)->messages();
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('المرسل')->searchable()->wrap()->description(fn (Contact $record): string => $record->email),
            TextColumn::make('kind')->label('النوع')->state(fn (Contact $record): string => $record->isAccountDeletionRequest() ? 'حذف حساب' : 'رسالة'),
            TextColumn::make('state')->label('الحالة')->badge()->state(fn (Contact $record): string => self::status($record)),
            TextColumn::make('email')->label('البريد')->searchable()->visibleFrom('md')->copyable()->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('phone')->label('الهاتف')->searchable()->visibleFrom('md')->extraAttributes(['dir' => 'ltr']),
            TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->sortable()->visibleFrom('md'),
        ])->defaultSort(fn (Builder $query): Builder => $query->orderBy('read')->orderByDesc('id'))->defaultPaginationPageOption(30)->paginated([10, 30, 50, 100])->poll('30s')
            ->filters([TernaryFilter::make('read')->label('مقروءة'),
                SelectFilter::make('resolution_status')->label('معالجة طلب الحذف')->options([
                    Contact::RESOLUTION_PENDING => 'جديد', Contact::RESOLUTION_PROCESSING => 'قيد المعالجة',
                    Contact::RESOLUTION_CLOSED => 'مغلق', Contact::RESOLUTION_FULFILLED => 'مكتمل',
                ]),
            ])->recordActions([ViewAction::make()])->emptyStateHeading('لا توجد رسائل')->emptyStateDescription('');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('الرسالة')->columns(2)->schema([
                TextEntry::make('name')->label('الاسم')->placeholder('—'),
                TextEntry::make('state')->label('الحالة')->state(fn (Contact $record): string => self::status($record))->badge(),
                TextEntry::make('email')->label('البريد')->copyable()->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('phone')->label('الهاتف')->copyable()->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('created_at')->label('وقت الإرسال')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName()),
                TextEntry::make('message')->label('المحتوى')->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('الحساب المطابق')->visible(fn (Contact $record): bool => $record->isAccountDeletionRequest() && ! $record->isResolved())->schema([
                TextEntry::make('matched_account')->label('مطابقة البريد')
                    ->state(fn (ContactResource\Pages\ViewContact $livewire): string => $livewire->matchedAccountLabel())
                    ->helperText('مطابقة البريد ليست تحققًا من هوية مقدم الطلب'),
            ])->columnSpanFull(),
            Section::make('نتيجة المعالجة')->visible(fn (Contact $record): bool => $record->isResolved())->columns(2)->schema([
                TextEntry::make('outcome')->label('النتيجة')->state(fn (Contact $record): string => [
                    ...self::OUTCOMES, 'manual_verified_deletion' => 'حُذف الحساب بعد التحقق من صاحبه',
                ][data_get($record->getAttribute('resolution_metadata'), 'outcome')] ?? 'مغلق بعد المراجعة'),
                TextEntry::make('resolver.name')->label('أغلق الطلب')->placeholder('مسؤول غير متاح'),
                TextEntry::make('resolved_at')->label('وقت الإغلاق')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName()),
                TextEntry::make('resolution_note')->label('ملاحظة المعالجة')->state(fn (Contact $record) => data_get($record->getAttribute('resolution_metadata'), 'note'))->placeholder('—')->columnSpanFull(),
            ])->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ContactResource\Pages\ListContacts::route('/'), 'view' => ContactResource\Pages\ViewContact::route('/{record}')];
    }
}
