<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecoveryCheckpointService;
use Illuminate\Console\Command;

final class CheckpointRecovery extends Command
{
    protected $signature = 'ops:checkpoint-recovery';

    protected $description = 'Persist a backup-visible timestamp and verify the configured encryption key';

    public function handle(RecoveryCheckpointService $checkpoints): int
    {
        $state = $checkpoints->checkpoint();
        $verb = ($state['updated'] ?? false) ? 'written' : 'verified without mutation';
        $this->info('Recovery checkpoint '.$verb.' at '.$state['checkpoint_at'].'.');

        return self::SUCCESS;
    }
}
