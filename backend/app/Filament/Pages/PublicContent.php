<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Auth\AdminPermissionMatrix;
use App\Filament\Support\FormValidation;
use App\Services\AdminPublicContentAuthoringService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @property-read Schema $form */
final class PublicContent extends Page
{
    protected static ?string $title = 'النصوص المنشورة';

    protected static string|\UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.public-content';

    public array $data = [];

    #[Locked]
    public string $editorVersion;

    public static function canAccess(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.about', 'GET');
    }

    public function canSave(): bool
    {
        return app(AdminPermissionMatrix::class)->allows(auth('web')->user(), 'admin.abouts.update', 'PATCH');
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->fillStoredContent();
    }

    private function fillStoredContent(): void
    {
        $state = app(AdminPublicContentAuthoringService::class)->editorState();
        $this->editorVersion = $state['editorVersion'];
        $this->form->fill($state['about']->only(AdminPublicContentAuthoringService::FIELDS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->disabled(fn (): bool => ! $this->canSave())->components([
            Tabs::make('published-content')->persistTabInQueryString('tab')->tabs(
                array_map(fn (string $key, string $label): Tab => Tab::make($label)->key($key, isInheritable: false)->schema([
                    Textarea::make($key.'_ar')->label($label.' بالعربية')->rows(15)->maxLength(100000),
                    Textarea::make($key.'_en')->label($label.' بالإنجليزية')->rows(15)->maxLength(100000)->extraInputAttributes(['dir' => 'ltr']),
                ]), ['about', 'privacy', 'policy'], ['عن ركن', 'سياسة الخصوصية', 'شروط الاستخدام'])
            ),
        ])->columns(1);
    }

    protected function getHeaderActions(): array
    {
        return [ActionGroup::make(array_map(fn (string $route, string $label): Action => Action::make('preview-'.$route)->label($label)->url(route($route))->openUrlInNewTab(),
            ['about', 'privacy', 'terms'], ['عن ركن', 'سياسة الخصوصية', 'شروط الاستخدام']))->label('معاينة الصفحة')->button()];
    }

    public function save(): void
    {
        abort_unless($this->canSave(), 403);
        $data = $this->form->getState();
        try {
            app(AdminPublicContentAuthoringService::class)->save($data, $this->editorVersion);
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['editor_version'])) {
                Notification::make()->title('تغيّر النص المنشور')->body('أعد تحميل الصفحة قبل الحفظ')->warning()->send();

                return;
            }
            throw FormValidation::forRecord($exception);
        }
        $this->fillStoredContent();
        Notification::make()->title('تم حفظ النص المنشور')->success()->send();
    }
}
