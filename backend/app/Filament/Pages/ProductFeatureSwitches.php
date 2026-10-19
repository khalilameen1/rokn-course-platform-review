<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Services\AdminProductFeatureAuthoringService;
use App\Services\ProductFeatureFlagService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

final class ProductFeatureSwitches extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'مفاتيح تشغيل المنتج';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.product-feature-switches';

    #[Locked]
    public ?string $pendingFeature = null;

    #[Locked]
    public ?string $pendingVersion = null;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.product-operations.index', 'GET');
    }

    public function canSave(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.product-operations.features.update', 'POST');
    }

    public function table(Table $table): Table
    {
        return $table->records(function (): array {
            $records = app(ProductFeatureFlagService::class)->operationalSnapshot();
            $labels = ['checkout' => 'الدفع', 'playback' => 'تشغيل الفيديو', 'project_uploads' => 'رفع المشاريع', 'ai_chat' => 'شات ركن'];
            foreach ($records as $key => &$record) {
                $record['feature'] = $key;
                $record['label'] = $labels[$key] ?? $key;
            }

            return $records;
        })->columns([
            TextColumn::make('label')->label('الميزة')->wrap()
                ->description(fn (array $record): string => ! $record['enabled'] || $record['rollout_percentage'] === 0
                    ? 'متوقفة' : 'مفعّلة · '.$record['rollout_percentage'].'٪ من المستخدمين'),
            TextColumn::make('reason')->label('آخر قرار محفوظ')->wrap()->default('الإعداد الافتراضي')->visibleFrom('md')
                ->description(fn (array $record): ?string => $record['owner']),
            TextColumn::make('expires_at')->label('انتهاء القرار بتوقيت القاهرة')
                ->state(fn (array $record): string => BusinessClock::format($record['expires_at']) ?: 'بدون موعد')->visibleFrom('md'),
        ])->paginated(false)->poll('30s')->recordActions([
            Action::make('decide')->label('تغيير')->authorize(fn (): bool => $this->canSave())
                ->modalHeading(fn (array $record): string => 'قرار تشغيل · '.$record['label'])
                ->modalDescription('القرار يطبق على الطلبات الجديدة ولا يلغي العمليات التي بدأت بالفعل')
                ->modalSubmitActionLabel('تطبيق القرار')->schema([
                    Toggle::make('enabled')->label('تشغيل')->required(),
                    TextInput::make('rollout_percentage')->label('نسبة المستخدمين')->numeric()->integer()->minValue(0)->maxValue(100)->required()->suffix('٪'),
                    TextInput::make('expires_at')->label('ينتهي تلقائيًا بتوقيت القاهرة')->type('datetime-local')
                        ->helperText('عند الانتهاء يعود الفيديو للتشغيل وتتوقف الميزات الأخرى حتى يصدر قرار جديد')->nullable()->rules(['nullable', 'date_format:Y-m-d\TH:i']),
                    Textarea::make('reason')->label('سبب القرار')->required()->minLength(8)->maxLength(255)->rows(3),
                ])->mountUsing(function (Schema $schema, array $record): void {
                    abort_unless($this->canSave(), 403);
                    $this->pendingFeature = $record['feature'];
                    $this->pendingVersion = $record['editor_version'];
                    $schema->fill(['enabled' => $record['enabled'], 'rollout_percentage' => $record['rollout_percentage'], 'expires_at' => null, 'reason' => null]);
                })->action(function (array $data, array $record): void {
                    abort_unless($this->canSave(), 403);
                    abort_unless($this->pendingFeature === $record['feature'] && $this->pendingVersion !== null, 409);
                    try {
                        app(AdminProductFeatureAuthoringService::class)->update($this->pendingFeature,
                            $data + ['editor_version' => $this->pendingVersion], auth('web')->user()?->email ?: 'admin:'.auth('web')->id());
                    } catch (ValidationException $exception) {
                        if (! isset($exception->errors()['editor_version'])) {
                            $errors = [];
                            foreach ($exception->errors() as $field => $messages) {
                                $errors[$this->getMountedActionSchema()->getStatePath().'.'.$field] = $messages;
                            }
                            throw ValidationException::withMessages($errors);
                        }
                        Notification::make()->title('تغيّر قرار التشغيل')->body('أغلق النافذة وافتحها لقراءة القرار الأحدث')->warning()->send();
                        throw new Halt;
                    }
                    $this->pendingFeature = $this->pendingVersion = null;
                    Notification::make()->title('تم تطبيق قرار التشغيل')->success()->send();
                }),
        ]);
    }
}
