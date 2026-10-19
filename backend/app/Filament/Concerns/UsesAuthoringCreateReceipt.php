<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\FormValidation;
use App\Services\AdminAuthoringCreateIntentService;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/** Adapts the existing authoring receipt ledger to native CreateRecord pages. */
trait UsesAuthoringCreateReceipt
{
    use RestoresAuthoringDraft;

    #[Locked]
    public string $creationIntent;

    public function mount(): void
    {
        $this->creationIntent = (string) Str::uuid();
        parent::mount();
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(Closure(TModel):void):TModel  $create
     * @return TModel
     */
    protected function createWithReceipt(string $operation, string $model, array $data, Closure $create): Model
    {
        try {
            return app(AdminAuthoringCreateIntentService::class)->createResource($operation, $this->creationIntent, $model, $data, $create);
        } catch (ValidationException $exception) {
            if (! isset($exception->errors()['authoring_request_id'])) {
                throw FormValidation::forRecord($exception);
            }
            Notification::make()->title($exception->errors()['authoring_request_id'][0])->danger()->send();
            throw new Halt;
        }
    }
}
