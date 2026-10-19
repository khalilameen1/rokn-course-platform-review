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
    // Presentation only. Authorization and revision checks remain server-side.
    public bool $authoringDraftReady = false;

    public function authoringDraftConfig(): array
    {
        $context = $this->authoringDraftContext();

        return [
            'actor' => (string) auth('web')->id(),
            'formId' => 'native-authoring',
            'fields' => $context['fields'],
            'fileFields' => $context['fileFields'],
            'version' => $context['version'],
            'receipt' => $context['receipt'],
        ];
    }

    public function restoreAuthoringDraft(array $draft): array
    {
        $context = $this->authoringDraftContext();
        abort_unless($context['canRestore'], 403);
        $version = $context['version'];
        // ConvertEmptyStringsToNull runs on the real HTTP endpoint, unlike direct component tests.
        if ((string) ($draft['version'] ?? '') !== $version) {
            return ['state' => 'conflict'];
        }
        Validator::make($draft, ['receipt' => ['required', 'uuid'], 'values' => ['required', 'array']])->validate();
        if (strlen(json_encode($draft, JSON_THROW_ON_ERROR)) > 65536) {
            throw ValidationException::withMessages(['draft' => 'تعذر قراءة المسودة']);
        }
        $values = Arr::only($draft['values'], $context['fields']);
        // Drafts contain scalars and ID lists, never serialized uploads or nested credentials.
        foreach ($values as $value) {
            $validList = is_array($value) && array_is_list($value) && count($value) <= 500
                && collect($value)->every(fn ($item): bool => is_scalar($item) || $item === null);
            if (! is_scalar($value) && $value !== null && ! $validList) {
                throw ValidationException::withMessages(['draft' => 'تعذر قراءة المسودة']);
            }
        }
        if ($context['tracksReceipt']) {
            $receipt = app(AdminAuthoringCreateIntentService::class)->resourceReceipt(
                request(), $draft['receipt'], $context['operation'], [], $context['model']
            );
            if ($receipt['state'] === 'completed') {
                return ['state' => 'completed', 'url' => $this->completedAuthoringDraftUrl($receipt['resource_id'])];
            }
            $this->adoptAuthoringDraftReceipt($draft['receipt']);
        }
        $this->form->fill(array_replace($this->data ?? [], $values));

        return ['state' => 'restored'];
    }

    protected function afterCreate(): void
    {
        $this->acknowledgeAuthoringDraft('');
    }

    protected function adoptAuthoringDraftReceipt(string $receipt): void
    {
        if ($this instanceof CreateRecord) {
            $this->creationIntent = $receipt;
        }
    }

    /** Record and singleton pages adapt to the same draft store and receipt ledger. */
    protected function authoringDraftContext(): array
    {
        $resource = static::getResource();
        $creating = $this instanceof CreateRecord;

        return ['fields' => $resource::draftFields(), 'fileFields' => $resource::draftFileFields(),
            'version' => $creating ? '' : $this->editorVersion,
            'receipt' => $creating ? $this->creationIntent : (string) Str::uuid(),
            'canRestore' => $creating ? $resource::canCreate() : $resource::canEdit($this->getRecord()),
            'tracksReceipt' => $creating, 'operation' => $resource::draftOperation(), 'model' => $resource::getModel()];
    }

    protected function completedAuthoringDraftUrl(string $recordId): string
    {
        return static::getResource()::getUrl('edit', ['record' => $recordId]);
    }

    protected function afterSave(): void
    {
        $this->acknowledgeAuthoringDraft($this instanceof CreateRecord ? '' : $this->editorVersion);
    }

    protected function acknowledgeAuthoringDraft(string $version, ?string $receipt = null): void
    {
        $this->dispatch('authoring-draft-saved', editorId: $this->getId(), version: $version, receipt: $receipt);
    }
}
