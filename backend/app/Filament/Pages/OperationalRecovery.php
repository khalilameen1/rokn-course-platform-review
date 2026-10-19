<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Services\AdminOperationalRecoveryService;
use App\Services\OperationalRuntimeService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OperationalRecovery extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'استعادة المهام';

    protected static string|\UnitEnum|null $navigationGroup = 'التشغيل';

    protected static ?int $navigationSort = 16;

    protected string $view = 'filament.pages.operational-recovery';

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.product-operations.index', 'GET');
    }

    public function table(Table $table): Table
    {
        return $table->records(fn (): array => app(OperationalRuntimeService::class)->recoveryRecords())->columns([
            TextColumn::make('title')->label('الطابور أو الحدث')->wrap()->extraAttributes(['dir' => 'ltr'])
                ->description(fn (array $record): string => $record['description']),
            TextColumn::make('occurred_at')->label('آخر فشل بتوقيت القاهرة')->dateTime('Y-m-d H:i')->timezone(BusinessClock::timezoneName())->visibleFrom('md'),
        ])->paginated(false)->poll('30s')->emptyStateHeading('لا توجد مهام تحتاج استعادة')->emptyStateDescription('')->recordActions([
            ActionGroup::make([
                $this->recoveryAction('retry', 'إعادة الحدث', 'event', 'outbox.retry', 'يعاد نفس الحدث بهويته الحالية دون إنشاء نسخة أخرى'),
                $this->recoveryAction('skip', 'تجاوز الحدث', 'event', 'outbox.skip', 'يسمح للأحداث التالية بالوصول رغم فشل هذا الحدث')->color('danger'),
                $this->recoveryAction('acknowledge', 'إغلاق دون إعادة', 'job', 'failed-jobs.acknowledge', 'تزال المهمة من قائمة الفشل دون إعادة تنفيذها')->color('danger'),
            ])->label('إجراءات الاستعادة'),
        ]);
    }

    private function recoveryAction(string $operation, string $label, string $kind, string $route, string $description): Action
    {
        return Action::make($operation)->label($label)->visible(fn (array $record): bool => $record['kind'] === $kind)
            ->authorize(fn (): bool => $this->canRecover($route))->requiresConfirmation()
            ->modalHeading($label)->modalDescription($description)->modalSubmitActionLabel($label)
            ->schema([Textarea::make('reason')->label('سبب الإجراء')->required()->minLength(8)->maxLength(190)->rows(3)])
            ->action(function (array $record, array $data) use ($operation, $route, $kind): void {
                abort_unless($this->canRecover($route), 403);
                abort_unless($record['kind'] === $kind, 409);
                $owner = app(AdminOperationalRecoveryService::class);
                $id = (int) $record['id'];
                $actor = auth('web')->id();
                try {
                    match ($operation) {
                        'retry' => $owner->retryOutbox($id, $actor, $data['reason']),
                        'skip' => $owner->skipOutbox($id, $actor, $data['reason']),
                        'acknowledge' => $owner->acknowledgeFailedJob($id, $actor, $data['reason']),
                        default => abort(404),
                    };
                } catch (HttpExceptionInterface $exception) {
                    if (! in_array($exception->getStatusCode(), [404, 409], true)) {
                        throw $exception;
                    }
                    Notification::make()->title('تغيّرت حالة المهمة')->body('أغلق النافذة وحدّث القائمة')->warning()->send();
                    throw new Halt;
                }
                Notification::make()->title('تم تسجيل إجراء الاستعادة')->success()->send();
            });
    }

    private function canRecover(string $route): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.product-operations.'.$route, 'POST');
    }
}
