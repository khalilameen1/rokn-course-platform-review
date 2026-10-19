<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\OrderResource;
use App\Support\PaymentReportPresentation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\Reactive;

final class OrderChannelReport extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.orders.channel-report';

    #[Reactive]
    public array $report = [];

    #[Reactive]
    public array $filters = [];

    public static function canView(): bool
    {
        return OrderResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table->heading('التحصيل حسب القناة')->description('عمليات الاختبار لا تدخل في أي مبلغ مالي')
            ->records(fn () => collect($this->report['paymentChannelReport']['rows'])->keyBy(fn (array $row): string => $row['method'].':'.$row['currency']))
            ->paginated(false)->poll(null)->columns([
                TextColumn::make('label')->label('القناة')->wrap()
                    ->description(fn (array $record): string => $record['currency'] === 'PENDING' ? 'العملة بانتظار كشف المزود' : $record['currency'])
                    ->url(fn (array $record): string => OrderResource::listUrl(array_replace($this->filters, ['payment_method' => $record['method']]))),
                TextColumn::make('live_count')->label('عمليات حقيقية')->numeric()->visibleFrom('md'),
                TextColumn::make('live_coins')->label('عملات مُصدرة')->numeric()->visibleFrom('md'),
                TextColumn::make('confirmed_gross_amount')->label('الإجمالي المؤكد')->wrap()
                    ->formatStateUsing(fn (array $record): string => PaymentReportPresentation::amount($record, 'gross'))
                    ->description(fn (array $record): ?string => PaymentReportPresentation::detail($record, 'gross')),
                TextColumn::make('confirmed_fee_amount')->label('رسوم مؤكدة')->visibleFrom('md')
                    ->formatStateUsing(fn (array $record): string => PaymentReportPresentation::amount($record, 'fee'))
                    ->description(fn (array $record): ?string => PaymentReportPresentation::detail($record, 'fee')),
                TextColumn::make('confirmed_net_amount')->label('صافي مؤكد')->wrap()
                    ->formatStateUsing(fn (array $record): string => PaymentReportPresentation::amount($record, 'net'))
                    ->description(fn (array $record): ?string => PaymentReportPresentation::detail($record, 'net')),
                TextColumn::make('test_count')->label('اختبار')->numeric()->visibleFrom('md')
                    ->description(fn (array $record): string => number_format($record['test_coins']).' عملة'),
            ]);
    }

    public function totals(Schema $schema): Schema
    {
        $channels = $this->report['paymentChannelReport'];
        $egp = PaymentReportPresentation::egp($channels);
        $entries = [];
        foreach (['gross' => 'الإجمالي المؤكد', 'fee' => 'رسوم مؤكدة', 'net' => 'صافي مؤكد'] as $kind => $label) {
            $entries[] = TextEntry::make($kind)->label($label)->state(PaymentReportPresentation::amount($egp, $kind))
                ->helperText(PaymentReportPresentation::detail($egp, $kind));
        }
        $entries[] = TextEntry::make('live_count')->label('عمليات حقيقية')->state($egp['live_count']);
        $entries[] = TextEntry::make('live_coins')->label('عملات مُصدرة')->state(number_format($egp['live_coins']));
        $entries[] = TextEntry::make('test_count')->label('عمليات اختبار خارج الإيراد')->state($egp['test_count']);

        return $schema->components([
            Section::make('الإجمالي بالجنيه')->description($channels['has_other_currencies'] ? 'لا تُجمع العملات المختلفة أو العمليات التي لم يصل كشف عملتها مع الجنيه' : null)
                ->columns(['default' => 2, 'md' => 3])->schema($entries),
        ]);
    }
}
