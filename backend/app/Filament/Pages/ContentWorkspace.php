<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Models\Course;
use App\Services\ModeratorHomeReadService;
use App\Support\BusinessClock;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

/** @property-read Schema $infolist */
final class ContentWorkspace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'مساحة المحتوى';

    protected static string|\UnitEnum|null $navigationGroup = 'المحتوى';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.content-workspace';

    private array $cachedReports = [];

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.courses.index', 'GET');
    }

    public function report(?int $page = null): array
    {
        abort_unless(self::canAccess(), 403);
        $page ??= $this->getPage();

        return $this->cachedReports[$page] ??= app(ModeratorHomeReadService::class)->read([], $page);
    }

    public function infolist(Schema $schema): Schema
    {
        $summary = $this->report()['contentSummary'];

        return $schema->components([
            Section::make('ملخص المحتوى')->columns(['default' => 2, 'md' => 4])->schema(
                collect(['courses' => 'كورس', 'modules' => 'وحدة', 'sections' => 'درس ومشروع', 'published' => 'منشور'])
                    ->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label)->state(number_format($summary[$key])))->values()->all()
            ),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('إنشاء كورس')->url(route('admin.courses.create'))
                ->visible(fn (): bool => app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.courses.create', 'GET')),
            Action::make('catalog')->label('القائمة الكاملة')->color('gray')->url(route('admin.courses.index')),
        ];
    }

    public function table(Table $table): Table
    {
        return $table->heading('الكورسات')->description('المسودات غير المكتملة أولًا في كل صفحة')->records(function (int $page): LengthAwarePaginator {
            $report = $this->report($page);
            $courses = $report['courses'];
            $audits = $report['publishingAudits'];
            $needsAttention = fn (Course $course): bool => $course->is_coming_soon && ! ($audits->get($course->id)['ready'] ?? false);
            $incomplete = $courses->getCollection()->filter($needsAttention);
            $ordered = $incomplete->concat($courses->getCollection()->reject($needsAttention));
            $rows = $ordered->mapWithKeys(function (Course $course) use ($audits): array {
                $audit = $audits->get($course->id);

                return [$course->id => [
                    'title' => trim((string) ($course->name_ar ?: $course->name_en)) ?: 'كورس بلا عنوان',
                    'status' => ! $course->is_coming_soon ? 'منشور' : (($audit['ready'] ?? false) ? 'البيانات مكتملة' : 'مسودة'),
                    'missing' => $course->is_coming_soon && ! ($audit['ready'] ?? false) ? count($audit['issues'] ?? []).' عناصر مطلوبة في البيانات الأساسية' : null,
                    'modules' => $course->getAttribute('modules_count'), 'sections' => $course->getAttribute('sections_count'),
                    'updated' => BusinessClock::relative($course->updated_at),
                    'url' => route('admin.courses.show', $course),
                ]];
            });

            return new LengthAwarePaginator($rows, $courses->total(), 12, $page);
        })->paginated([12])->defaultPaginationPageOption(12)->columns([
            TextColumn::make('title')->label('الكورس')->wrap()->description(fn (array $record): ?string => $record['missing']),
            TextColumn::make('status')->label('الحالة')->badge()->color(fn (string $state): string => $state === 'منشور' ? 'success' : 'gray'),
            TextColumn::make('modules')->label('الوحدات')->visibleFrom('md'),
            TextColumn::make('sections')->label('العناصر')->visibleFrom('md'),
            TextColumn::make('updated')->label('آخر تعديل')->visibleFrom('lg'),
        ])->recordActions([
            Action::make('studio')->label('فتح الاستوديو')->url(fn (array $record): string => $record['url']),
        ])->emptyStateHeading('ابدأ بأول كورس')->emptyStateDescription('أضف البيانات الأساسية ثم ابنِ الوحدات والدروس من الاستوديو');
    }
}
