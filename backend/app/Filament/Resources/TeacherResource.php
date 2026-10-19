<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Auth\AdminPermissionMatrix;
use App\Models\User;
use App\Services\AdminTeacherAuthoringService;
use App\Services\AdminTeacherReadService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** @extends DomainResource<User> */
final class TeacherResource extends DomainResource
{
    protected static bool $isDiscovered = true;

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    protected static ?int $navigationSort = 40;

    protected static ?string $model = User::class;

    protected static ?string $slug = 'teachers';

    protected static ?string $modelLabel = 'محاضر';

    protected static ?string $pluralModelLabel = 'المحاضرون';

    protected static ?string $recordTitleAttribute = 'name_ar';

    protected static string $permissionRoute = 'admin.teachers';

    public static function draftFields(): array
    {
        return ['name_ar', 'name_en', 'job_title', 'bio_ar', 'bio_en', 'active'];
    }

    public static function draftFileFields(): array
    {
        return ['image'];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('ملف المحاضر')->columns(2)->schema([
                ImageEntry::make('profile_image_url')->label('الصورة')->circular()->hiddenLabel()
                    ->state(fn (User $record): ?string => $record->profile_image_url ? url($record->profile_image_url) : null)
                    ->visible(fn (User $record): bool => filled($record->profile_image_url)),
                TextEntry::make('name_ar')->label('الاسم بالعربية'),
                TextEntry::make('name_en')->label('الاسم بالإنجليزية')->visible(fn (User $record): bool => filled($record->name_en)),
                TextEntry::make('job_title')->label('المسمى الوظيفي')->visible(fn (User $record): bool => filled($record->job_title)),
                TextEntry::make('bio_ar')->label('نبذة بالعربية')->columnSpanFull()->visible(fn (User $record): bool => filled($record->bio_ar)),
                TextEntry::make('bio_en')->label('نبذة بالإنجليزية')->columnSpanFull()->extraAttributes(['dir' => 'ltr'])->visible(fn (User $record): bool => filled($record->bio_en)),
            ])->columnSpanFull(),
            Section::make('بيانات التواصل')->visible(fn (): bool => self::canManageCredentials())->columns(2)->schema([
                TextEntry::make('email')->label('البريد الإلكتروني')->copyable()->extraAttributes(['dir' => 'ltr'])->visible(fn (User $record): bool => filled($record->email)),
                TextEntry::make('phone')->label('الهاتف')->copyable()->extraAttributes(['dir' => 'ltr'])->visible(fn (User $record): bool => filled($record->phone)),
                TextEntry::make('created_at')->label('تاريخ الانضمام')->date('Y-m-d')->visible(fn (User $record): bool => $record->created_at !== null),
            ])->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [TeacherResource\RelationManagers\CoursesRelationManager::class];
    }

    public static function canManageCredentials(): bool
    {
        return app(AdminPermissionMatrix::class)->allowsCapability(auth('web')->user(), AdminPermissionMatrix::ACCOUNT_CREDENTIALS);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(AdminTeacherReadService::class)->listQuery(self::canManageCredentials());
    }

    public static function form(Schema $schema): Schema
    {
        $fields = [
            self::draftRecovery(),
            TextInput::make('name_ar')->label('الاسم بالعربية')->required()->maxLength(255),
            TextInput::make('name_en')->label('الاسم بالإنجليزية')->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
            TextInput::make('job_title')->label('المسمى الوظيفي')->maxLength(255),
            Toggle::make('active')->label('نشط')->default(true),
            Textarea::make('bio_ar')->label('نبذة بالعربية')->rows(4),
            Textarea::make('bio_en')->label('نبذة بالإنجليزية')->rows(4)->extraInputAttributes(['dir' => 'ltr']),
            // The domain service owns the permanent upload and photo replacement.
            FileUpload::make('image')->label('صورة المحاضر')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(4096)->disk('public')->visibility('public')->storeFiles(false)->deletable(false),
        ];
        if (self::canManageCredentials()) {
            $fields[] = Section::make('بيانات تسجيل الدخول')->columns(2)->columnSpanFull()->schema([
                Toggle::make('manage_credentials')->label('تعديل بيانات الدخول')->live()->columnSpanFull()->default(false),
                TextInput::make('email')->label('البريد الإلكتروني')->email()->extraInputAttributes(['dir' => 'ltr'])->maxLength(255)->unique(table: User::class, ignoreRecord: true)
                    ->visible(fn (Get $get): bool => (bool) $get('manage_credentials')),
                TextInput::make('phone')->label('الهاتف')->tel()->extraInputAttributes(['dir' => 'ltr'])->maxLength(20)->unique(table: User::class, ignoreRecord: true)
                    ->visible(fn (Get $get): bool => (bool) $get('manage_credentials')),
                TextInput::make('password')->label('كلمة المرور الجديدة')->password()->minLength(10)->maxLength(72)->confirmed()
                    ->visible(fn (Get $get): bool => (bool) $get('manage_credentials')),
                TextInput::make('password_confirmation')->label('تأكيد كلمة المرور')->password()->dehydrated(false)
                    ->visible(fn (Get $get): bool => (bool) $get('manage_credentials')),
            ]);
        }

        return $schema->columns(2)->components($fields);
    }

    /** Only schema-validated profile fields, with a second server-side credential boundary. */
    public static function profilePayload(array $data, bool $creating): array
    {
        $payload = array_intersect_key($data, array_flip(['name_ar', 'name_en', 'job_title', 'bio_ar', 'bio_en']));
        $payload['name_ar'] = trim((string) $payload['name_ar']);
        if ($creating) {
            $payload += ['email' => null, 'phone' => null, 'password' => null];
        }
        if (self::canManageCredentials() && ($data['manage_credentials'] ?? false)) {
            foreach (['email', 'phone', 'password'] as $field) {
                if (filled($data[$field] ?? null)) {
                    $payload[$field] = $field === 'email' ? strtolower(trim($data[$field])) : ($field === 'phone' ? trim($data[$field]) : $data[$field]);
                }
            }
        }

        return $payload;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('profile_image_url')->label('الصورة')->circular()
                ->state(fn (User $record): ?string => $record->profile_image_url ? url($record->profile_image_url) : null),
            TextColumn::make('name_ar')->label('المحاضر')->searchable(['name_ar', 'name_en'])->wrap(),
            TextColumn::make('job_title')->label('التخصص')->wrap(),
            TextColumn::make('teaching_courses_count')->label('الكورسات')->numeric()->sortable(),
            TextColumn::make('active')->label('الحالة')->badge()->formatStateUsing(fn ($state): string => $state ? 'نشط' : 'غير نشط')
                ->color(fn ($state): string => $state ? 'success' : 'gray'),
            TextColumn::make('email')->label('البريد')->searchable(condition: fn (): bool => self::canManageCredentials())
                ->extraAttributes(['dir' => 'ltr'])->visible(fn (): bool => self::canManageCredentials()),
            TextColumn::make('phone')->label('الهاتف')->searchable(condition: fn (): bool => self::canManageCredentials())
                ->extraAttributes(['dir' => 'ltr'])->visible(fn (): bool => self::canManageCredentials()),
        ])->defaultSort('id', 'desc')->poll('30s')->filters([TernaryFilter::make('active')->label('الحالة')])
            ->emptyStateHeading('لا يوجد محاضرون')->emptyStateDescription('')->recordActions([
                Action::make('profile')->label('ملف المحاضر')->url(fn (User $record): string => self::getUrl('view', ['record' => $record->id])),
                EditAction::make(),
                Action::make('remove')->label('حذف')->color('danger')->requiresConfirmation()
                    ->authorize(fn (User $record): bool => self::canDelete($record))->action(fn (User $record) => self::removeTeachers(collect([$record]))),
            ])->toolbarActions([
                BulkAction::make('remove')->label('حذف المحاضرين المحددين')->color('danger')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->authorize(fn (): bool => self::canDeleteAny())->action(fn (Collection $records) => self::removeTeachers($records)),
            ]);
    }

    private static function removeTeachers(Collection $records): void
    {
        $blocked = 0;
        foreach ($records as $record) {
            $blocked += app(AdminTeacherAuthoringService::class)->delete((int) $record->getKey()) ? 0 : 1;
        }
        Notification::make()->title($blocked ? 'بقي محاضرون مرتبطون بكورسات' : 'تم الحذف')
            ->body($blocked ? 'انقل الكورسات إلى محاضر آخر قبل حذفهم' : null)->status($blocked ? 'warning' : 'success')->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => TeacherResource\Pages\ListTeachers::route('/'),
            'create' => TeacherResource\Pages\CreateTeacher::route('/create'),
            'view' => TeacherResource\Pages\ViewTeacher::route('/{record}'),
            'edit' => TeacherResource\Pages\EditTeacher::route('/{record}/edit'),
        ];
    }
}
