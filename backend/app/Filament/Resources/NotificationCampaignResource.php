<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Resources\NotificationCampaignResource\NotificationCampaignSchema;
use App\Filament\Support\NotificationCampaignAction;
use App\Models\NotificationCampaign;
use App\Services\AdminNotificationCampaignReadService;
use App\Support\AdminStudentSearch;
use App\Support\BusinessClock;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** @extends DomainResource<NotificationCampaign> */
final class NotificationCampaignResource extends DomainResource
{
    public const STATES = ['scheduled' => 'مجدولة', 'queued' => 'في القائمة', 'delivering' => 'جارٍ التوزيع', 'completed' => 'اكتملت', 'failed' => 'تعذّر الإرسال', 'cancelled' => 'أُلغيت قبل الإرسال'];

    protected static bool $isDiscovered = true;

    protected static ?string $model = NotificationCampaign::class;

    protected static ?string $slug = 'notifications';

    protected static ?string $modelLabel = 'حملة إشعار';

    protected static ?string $pluralModelLabel = 'الإشعارات';

    protected static string|\UnitEnum|null $navigationGroup = 'التطبيق';

    protected static ?int $navigationSort = 10;

    protected static string $permissionRoute = 'admin.notifications';

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return in_array($action, ['viewAny', 'view', 'create'], true)
            ? parent::getAuthorizationResponse($action, $record) : Response::deny();
    }

    public static function draftFields(): array
    {
        return ['course_id', 'audience', 'notification_kind', 'title_ar', 'message_ar', 'title_en', 'message_en', 'action_label', 'action_link', 'send_at'];
    }

    public static function draftFileFields(): array
    {
        return ['image'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([self::draftRecovery(), ...NotificationCampaignSchema::components()]);
    }

    public static function allows(string $operation): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.notifications.'.$operation, 'POST');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminNotificationCampaignReadService::class)->query();
    }

    public static function statusLabel(NotificationCampaign $campaign): string
    {
        return $campaign->failure_code === 'course_withdrawn_before_delivery' ? 'لم تُرسل — الكورس غير متاح' : (self::STATES[$campaign->status] ?? 'حالة غير معروفة');
    }

    public static function audienceLabel(NotificationCampaign $campaign): string
    {
        return $campaign->user_ids ? 'طلاب محددون' : (['all' => 'كل الطلاب', 'enrolled' => 'المسجلون في الكورس', 'not_enrolled' => 'غير المسجلين في الكورس'][$campaign->audience] ?? 'جمهور محدد');
    }

    public static function failureDescription(NotificationCampaign $campaign): ?string
    {
        if ($campaign->status !== NotificationCampaign::STATUS_FAILED || $campaign->failure_code === 'course_withdrawn_before_delivery') {
            return null;
        }
        $code = (string) $campaign->failure_code;

        return str_starts_with($code, 'queue_') ? 'تعذر وضع الحملة في قائمة التنفيذ'
            : ((str_starts_with($code, 'coordinator_') || str_starts_with($code, 'chunk_')) ? 'توقف توزيع الحملة قبل اكتماله' : 'لم يكتمل توزيع الحملة');
    }

    public static function destinationLabel(NotificationCampaign $campaign): string
    {
        $link = (string) $campaign->link;

        return match (true) {
            str_starts_with($link, 'rokn://wallet') => 'المحفظة',
            str_starts_with($link, 'rokn://profile/certificates') => 'الشهادات',
            str_starts_with($link, 'rokn://profile/portfolio') => 'البورتفوليو',
            str_starts_with($link, 'rokn://profile/saved') => 'المحفوظات',
            str_starts_with($link, 'rokn://profile') => 'الملف الشخصي',
            str_starts_with($link, 'rokn://support/') => 'الدعم',
            str_starts_with($link, 'rokn://course/') => 'الكورس',
            default => 'الرئيسية',
        };
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title_ar')->label('الحملة')->wrap()->description(fn (NotificationCampaign $record): string => self::audienceLabel($record).' · '.self::destinationLabel($record)),
            TextColumn::make('status')->label('الحالة')->badge()->wrap()->formatStateUsing(fn (NotificationCampaign $record): string => self::statusLabel($record))
                ->description(fn (NotificationCampaign $record): ?string => self::failureDescription($record)),
            TextColumn::make('inbox_count')->label('صندوق الوارد')->numeric()->visibleFrom('md')
                ->description(fn (NotificationCampaign $record): string => 'من '.$record->recipients_count.($record->skipped_count ? ' · استُبعد '.$record->skipped_count : '')),
            TextColumn::make('provider_accepted_count')->label('قبله مزود Push')->numeric()->visibleFrom('md')
                ->description(fn (NotificationCampaign $record): string => 'محاولات '.$record->getAttribute('attempted_count').' · تعذّر '.$record->getAttribute('push_failed_count').' · جزئي '.$record->getAttribute('push_partial_count')),
            TextColumn::make('read_count')->label('قُرئ')->numeric()->visibleFrom('md'),
            TextColumn::make('added_at')->label('الموعد بتوقيت القاهرة')->visibleFrom('lg')
                ->state(fn (NotificationCampaign $record): HtmlString => new HtmlString('<bdi dir="ltr">'.e(BusinessClock::format($record->scheduled_at ?? $record->queued_at ?? $record->created_at, 'Y-m-d H:i')).'</bdi>')),
        ])->searchable()->searchPlaceholder('عنوان الحملة أو نصها')
            ->searchUsing(fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query): Builder => $query
                ->whereRaw("title_ar LIKE ? ESCAPE '!'", ['%'.AdminStudentSearch::escape($search).'%'])
                ->orWhereRaw("message_ar LIKE ? ESCAPE '!'", ['%'.AdminStudentSearch::escape($search).'%'])
                ->orWhereRaw("title_en LIKE ? ESCAPE '!'", ['%'.AdminStudentSearch::escape($search).'%'])))
            ->filters([SelectFilter::make('status')->label('الحالة')->options(self::STATES)])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('COALESCE(scheduled_at, queued_at, created_at) DESC')->orderByDesc('id'))
            ->defaultPaginationPageOption(30)->paginated([10, 30, 50, 100])->poll('15s')
            ->recordActions([ActionGroup::make([ViewAction::make()->label('التفاصيل'), NotificationCampaignAction::make('cancel'), NotificationCampaignAction::make('retry')])->label('إجراءات الحملة')])
            ->emptyStateHeading('لا توجد حملات بهذه التصفيات')->emptyStateDescription('');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('محتوى الإشعار')->columns(2)->schema([
                ImageEntry::make('image_url')->label('الصورة')->imageHeight(144)->visible(fn (NotificationCampaign $record): bool => filled($record->image_url))->columnSpanFull(),
                TextEntry::make('title_ar')->label('العنوان بالعربية'), TextEntry::make('title_en')->label('العنوان بالإنجليزية')->visible(fn (NotificationCampaign $record): bool => filled($record->title_en)),
                TextEntry::make('message_ar')->label('النص بالعربية')->columnSpanFull(), TextEntry::make('message_en')->label('النص بالإنجليزية')->visible(fn (NotificationCampaign $record): bool => filled($record->message_en))->columnSpanFull(),
                TextEntry::make('action_label_ar')->label('نص الزر بالعربية')->placeholder('غير محدد'), TextEntry::make('action_label_en')->label('نص الزر بالإنجليزية')->visible(fn (NotificationCampaign $record): bool => filled($record->action_label_en)),
                TextEntry::make('destination')->label('الوجهة')->state(fn (NotificationCampaign $record): string => self::destinationLabel($record))->columnSpanFull(),
            ])->columnSpanFull(),
            Section::make('التوزيع')->columns(['default' => 2, 'lg' => 3])->schema([
                TextEntry::make('status')->label('الحالة')->formatStateUsing(fn (NotificationCampaign $record): string => self::statusLabel($record))->badge(),
                TextEntry::make('audience')->label('الجمهور')->formatStateUsing(fn (NotificationCampaign $record): string => self::audienceLabel($record)),
                TextEntry::make('course_id')->label('رقم الكورس')->placeholder('غير مرتبط'),
                ...array_map(fn (string $field, string $label): TextEntry => TextEntry::make($field)->label($label)->numeric(),
                    ['recipients_count', 'inbox_count', 'skipped_count', 'attempted_count', 'provider_accepted_count', 'push_failed_count', 'push_partial_count', 'read_count'],
                    ['المستهدفون', 'صندوق الوارد', 'مستبعدون بعد تغيّر الأهلية', 'محاولات Push', 'قبله مزود Push', 'تعذّر Push', 'Push جزئي', 'قُرئ']),
                TextEntry::make('scheduled_at')->label('الموعد بتوقيت القاهرة')->placeholder('غير مجدول')
                    ->formatStateUsing(fn ($state): HtmlString => new HtmlString('<bdi dir="ltr">'.e(BusinessClock::format($state, 'Y-m-d H:i')).'</bdi>')),
                TextEntry::make('failure_description')->label('سبب التوقف')->state(fn (NotificationCampaign $record): ?string => self::failureDescription($record))->placeholder('لا يوجد'),
            ])->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => NotificationCampaignResource\Pages\ListNotificationCampaigns::route('/'),
            'create' => NotificationCampaignResource\Pages\CreateNotificationCampaign::route('/create'),
            'create-for-student' => NotificationCampaignResource\Pages\CreateNotificationCampaign::route('/create/for/{student}'),
            'view' => NotificationCampaignResource\Pages\ViewNotificationCampaign::route('/{record}')];
    }
}
