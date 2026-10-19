<?php

namespace App\Filament\Resources\PathResource\Pages;

use App\Filament\Resources\PathResource;
use App\Models\Path;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminPathAuthoringService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** @extends CreateRecord<Path> */
final class CreatePath extends CreateRecord
{
    protected static string $resource = PathResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $creationIntent;

    public function mount(): void
    {
        $this->creationIntent = (string) Str::uuid();
        parent::mount();
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(AdminAuthoringCreateIntentService::class)->createResource(
                'filament.paths.store', $this->creationIntent, Path::class, $data,
                fn ($complete): Path => app(AdminPathAuthoringService::class)->create($data, $complete)
            );
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['authoring_request_id'])) {
                throw $exception;
            }
            Notification::make()->title($exception->errors()['authoring_request_id'][0])->danger()->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return PathResource::getUrl();
    }
}
