<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductFeatureFlag;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class SetProductFeatureFlag extends Command
{
    protected $signature = 'product:feature
        {key : checkout, playback, project_uploads or ai_chat}
        {state : on or off}
        {--rollout=100 : Percentage from 0 to 100}
        {--owner= : Team or person accountable for this change}
        {--reason= : Required operational reason}
        {--expires= : ISO timestamp after which the safe default applies}';

    protected $description = 'Set a product kill switch with rollout, ownership and expiry evidence';

    public function handle(): int
    {
        $key = trim((string) $this->argument('key'));
        $definitions = config('product_features.definitions', []);
        if (! array_key_exists($key, $definitions)) {
            $this->error('Unknown feature key.');

            return self::INVALID;
        }
        $state = strtolower(trim((string) $this->argument('state')));
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('State must be on or off.');

            return self::INVALID;
        }
        $owner = trim((string) $this->option('owner'));
        $reason = trim((string) $this->option('reason'));
        if ($owner === '' || $reason === '') {
            $this->error('--owner and --reason are required for an auditable change.');

            return self::INVALID;
        }
        $rollout = filter_var($this->option('rollout'), FILTER_VALIDATE_INT);
        if ($rollout === false || $rollout < 0 || $rollout > 100) {
            $this->error('--rollout must be an integer from 0 to 100.');

            return self::INVALID;
        }
        try {
            $expiresAt = $this->option('expires')
                ? CarbonImmutable::parse((string) $this->option('expires'))
                : null;
        } catch (\Throwable) {
            $this->error('--expires must be a valid timestamp.');

            return self::INVALID;
        }

        ProductFeatureFlag::query()->updateOrCreate(
            ['key' => $key],
            [
                'enabled' => $state === 'on',
                'rollout_percentage' => $rollout,
                'owner' => $owner,
                'reason' => $reason,
                'expires_at' => $expiresAt,
            ]
        );
        $this->info("{$key} is {$state} at {$rollout}%.");

        return self::SUCCESS;
    }
}
