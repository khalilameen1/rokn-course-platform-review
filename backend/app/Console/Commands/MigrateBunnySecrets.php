<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MigrateBunnySecrets extends Command
{
    protected $signature = 'bunny:migrate-secrets
        {--dry-run : Inspect only; this is also the default when --apply is absent}
        {--apply : Encrypt verified legacy values and clear their plaintext columns}';

    protected $description = 'Safely migrate legacy Bunny credentials into encrypted setting fields';

    /** @var array<string, string> */
    private const SECRET_COLUMNS = [
        'bunny_api_key' => 'bunny_api_key_secret',
        'bunny_storage_password' => 'bunny_storage_password_secret',
    ];

    public function handle(): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        if (! $this->schemaIsReady()) {
            $this->error('The settings secret columns are unavailable. Run database migrations first.');

            return self::FAILURE;
        }

        $inspection = $this->inspect(Setting::query()->orderBy('id')->get());
        $this->table(['State', 'Credential fields'], [
            ['ready_to_encrypt', $inspection['migrate']],
            ['ready_to_clear', $inspection['clear']],
            ['conflicts_or_unreadable', $inspection['conflict']],
        ]);

        if ($inspection['conflict'] > 0) {
            $ids = implode(', ', array_map('strval', $inspection['conflict_ids']));
            $this->error('No values were changed. Resolve encrypted/plaintext conflicts for setting row(s): '.$ids);
            $this->rotationGuidance();

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Dry-run complete. No values were changed. Re-run with --apply after backing up the database and APP_KEY.');
            $this->rotationGuidance();

            return self::SUCCESS;
        }

        try {
            DB::transaction(function (): void {
                $settings = Setting::query()->orderBy('id')->lockForUpdate()->get();
                $freshInspection = $this->inspect($settings);
                if ($freshInspection['conflict'] > 0) {
                    throw new RuntimeException('Bunny secret state changed after inspection.');
                }

                foreach ($settings as $setting) {
                    $legacyValues = [];
                    foreach (self::SECRET_COLUMNS as $legacyColumn => $encryptedColumn) {
                        $legacy = trim((string) $setting->getRawOriginal($legacyColumn));
                        if ($legacy === '') {
                            continue;
                        }

                        $legacyValues[$legacyColumn] = [$encryptedColumn, $legacy];
                        $encrypted = trim((string) $setting->getAttribute($encryptedColumn));
                        if ($encrypted === '') {
                            $setting->setAttribute($encryptedColumn, $legacy);
                        }
                    }

                    if ($legacyValues === []) {
                        continue;
                    }

                    $setting->save();
                    $setting->refresh();

                    foreach ($legacyValues as [$encryptedColumn, $legacy]) {
                        $decrypted = (string) $setting->getAttribute($encryptedColumn);
                        $ciphertext = (string) $setting->getRawOriginal($encryptedColumn);
                        if (! hash_equals($legacy, $decrypted) || hash_equals($legacy, $ciphertext)) {
                            throw new RuntimeException('Encrypted Bunny credential verification failed.');
                        }
                    }

                    DB::table('settings')->where('id', $setting->getKey())->update(
                        array_fill_keys(array_keys($legacyValues), null)
                    );
                }
            }, 3);
        } catch (\Throwable $exception) {
            // Keep output generic: exception context must never serialize a
            // plaintext value or encrypted payload to the terminal.
            $this->error('Migration aborted and rolled back; no plaintext fields were cleared.');

            return self::FAILURE;
        }

        $remaining = $this->inspect(Setting::query()->orderBy('id')->get());
        if ($remaining['migrate'] !== 0 || $remaining['clear'] !== 0 || $remaining['conflict'] !== 0) {
            $this->error('Post-migration verification failed. Inspect the database before rotating credentials.');

            return self::FAILURE;
        }

        $this->info('Bunny secrets were encrypted, verified, and the legacy plaintext columns were cleared.');
        $this->rotationGuidance();

        return self::SUCCESS;
    }

    private function schemaIsReady(): bool
    {
        return Schema::hasTable('settings') && Schema::hasColumns('settings', [
            'bunny_api_key',
            'bunny_storage_password',
            'bunny_api_key_secret',
            'bunny_storage_password_secret',
        ]);
    }

    /**
     * @param  iterable<int, Setting>  $settings
     * @return array{migrate: int, clear: int, conflict: int, conflict_ids: list<int>}
     */
    private function inspect(iterable $settings): array
    {
        $result = ['migrate' => 0, 'clear' => 0, 'conflict' => 0, 'conflict_ids' => []];

        foreach ($settings as $setting) {
            foreach (self::SECRET_COLUMNS as $legacyColumn => $encryptedColumn) {
                $legacy = trim((string) $setting->getRawOriginal($legacyColumn));
                if ($legacy === '') {
                    continue;
                }

                try {
                    $encrypted = trim((string) $setting->getAttribute($encryptedColumn));
                } catch (\Throwable) {
                    $encrypted = null;
                }

                if ($encrypted === null || ($encrypted !== '' && ! hash_equals($legacy, $encrypted))) {
                    $result['conflict']++;
                    $result['conflict_ids'][] = (int) $setting->getKey();
                } elseif ($encrypted === '') {
                    $result['migrate']++;
                } else {
                    $result['clear']++;
                }
            }
        }

        $result['conflict_ids'] = array_values(array_unique($result['conflict_ids']));

        return $result;
    }

    private function rotationGuidance(): void
    {
        $this->newLine();
        $this->line('Rotation guidance (the command never calls Bunny or rotates provider credentials):');
        $this->line('1. Create replacement API/storage/token-auth credentials in Bunny.');
        $this->line('2. Save them through encrypted admin settings or deployment environment variables.');
        $this->line('3. Verify upload, playback/token authentication, and cleanup in a non-production asset first.');
        $this->line('4. Revoke the superseded Bunny credentials only after verification and rollback readiness.');
    }
}
