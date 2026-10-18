<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use LogicException;

trait GuardsDevelopmentFixtures
{
    /**
     * Fixture seeders are useful locally, but must never be a production data
     * source. Keep the check in every fixture seeder so `--class` cannot bypass
     * the DatabaseSeeder gate.
     */
    private function guardDevelopmentFixtures(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException(
                'Development fixtures are forbidden outside the local and testing environments.'
            );
        }

        if (! config('demo.seed_enabled', false)) {
            throw new LogicException(
                'Development fixtures are disabled. Set ROKN_SEED_DEMO=true only in an isolated local or test environment.'
            );
        }
    }
}
