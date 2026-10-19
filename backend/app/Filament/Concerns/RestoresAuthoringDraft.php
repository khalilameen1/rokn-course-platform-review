<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Services\AdminAuthoringCreateIntentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Restores public form fields only; the owning schema/service still validates every save. */
trait RestoresAuthoringDraft
{
    public function authoringDraftConfig(): array
    {
        $resource = static::getResource();

        return [
            'actor' => (string) auth('web')->id(),
            'formId' => 'native-authoring',
            'fields' => $resource::draftFields(),
            'fileFields' => $resource::draftFileFields(),
            'version' => $this instanceof CreateRecord ? '' : $this->editorVersion,
            'receipt' => $this instanceof CreateRecord ? $this->creationIntent : (string) Str::uuid(),
        ];
    }

    public function restoreAuthoringDraft(array $draft): array
    {
        $resource = static::getResource();
        $creating = $this instanceof CreateRecord;
        abort_unless($creating ? $resource::canCreate() : $resource::canEdit($this->getRecord()), 403);
        $version = $creating ? '' : $this->editorVersion;
        // ConvertEmptyStringsToNull runs on the real HTTP endpoint, unlike direct component tests.
        if ((string) ($draft['version'] ?? '') !== $version) {
            return ['state' => 'conflict'];
        }
        Validator::make($draft, ['receipt' => ['required', 'uuid'], 'values' => ['required', 'array']])->validate();
        if (strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 65536) {
            throw ValidationException::withMessages(['draft' => 'تعذر قراءة المسودة']);
        }
        $values = Arr::only($draft['values'], $resource::draftFields());
        // Drafts contain scalars and ID lists, never serialized uploads or nested credentials.
        foreach ($values as $value) {
            $validList = is_array($value) && array_is_list($value) && count($value) <= 500
                && collect($value)->every(fn ($item): bool => is_scalar($item) || $item === null);
            if (! is_scalar($value) && $value !== null && ! $validList) {
                throw ValidationException::withMessages(['draft' => 'تعذر قراءة المسودة']);
            }
        }
        if ($creating) {
            $receipt = app(AdminAuthoringCreateIntentService::class)->resourceReceipt(
                request(), $draft['receipt'], $resource::draftOperation(), [], $resource::getModel()
            );
            if ($receipt['state'] === 'completed') {
                return ['state' => 'completed', 'url' => $this->completedAuthoringDraftUrl($receipt['resource_id'])];
            }
            $this->creationIntent = $draft['receipt'];
        }
        $this->form->fill(array_replace($this->data ?? [], $values));

        return ['state' => 'restored'];
    }

    protected function afterCreate(): void
    {
        $this->acknowledgeAuthoringDraft('');
    }

    protected function completedAuthoringDraftUrl(string $recordId): string
    {
        return static::getResource()::getUrl('edit', ['record' => $recordId]);
    }

    protected function afterSave(): void
    {
        $this->acknowledgeAuthoringDraft($this instanceof CreateRecord ? '' : $this->editorVersion);
    }

    private function acknowledgeAuthoringDraft(string $version): void
    {
        $this->dispatch('authoring-draft-saved', editorId: $this->getId(), version: $version);
    }
}
