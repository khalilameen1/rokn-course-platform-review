<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class RecoveryCheckpointService
{
    public const SCOPE = 'production';

    /** @return array<string,mixed> */
    public function checkpoint(): array
    {
        if (! Schema::hasTable('recovery_markers')) {
            throw new RuntimeException('Recovery marker schema is missing.');
        }
        $keyId = trim((string) config('operations.recovery_encryption_key_id'));
        if ($keyId === '') {
            throw new RuntimeException('RECOVERY_ENCRYPTION_KEY_ID is required.');
        }

        return DB::transaction(function () use ($keyId): array {
            $marker = DB::table('recovery_markers')
                ->where('scope', self::SCOPE)
                ->lockForUpdate()
                ->first();
            if (! $marker) {
                if ((bool) config('operations.disaster_recovery_mode', false)) {
                    throw new RuntimeException('A recovery deployment may not create a marker missing from its backup.');
                }
                $probe = bin2hex(random_bytes(32));
                DB::table('recovery_markers')->insert([
                    'scope' => self::SCOPE,
                    'generation' => (string) Str::uuid(),
                    'encryption_key_id' => $keyId,
                    'encrypted_probe' => Crypt::encryptString($probe),
                    'probe_hash' => hash('sha256', $probe),
                    'checkpoint_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $marker = DB::table('recovery_markers')->where('scope', self::SCOPE)->first();
            } else {
                $this->assertDecryptable($marker, $keyId);
                if ((bool) config('operations.disaster_recovery_mode', false)) {
                    return [
                        'generation' => (string) $marker->generation,
                        'encryption_key_id' => (string) $marker->encryption_key_id,
                        'checkpoint_at' => (string) $marker->checkpoint_at,
                        'updated' => false,
                    ];
                }
                DB::table('recovery_markers')->where('id', $marker->id)->update([
                    'checkpoint_at' => now(),
                    'updated_at' => now(),
                ]);
                $marker->checkpoint_at = now();
            }

            return [
                'generation' => (string) $marker->generation,
                'encryption_key_id' => (string) $marker->encryption_key_id,
                'checkpoint_at' => (string) $marker->checkpoint_at,
                'updated' => true,
            ];
        }, 3);
    }

    /** @return array{ready:bool,generation:?string,checkpoint_at:mixed,reason:?string} */
    public function status(): array
    {
        try {
            if (! Schema::hasTable('recovery_markers')) {
                return ['ready' => false, 'generation' => null, 'checkpoint_at' => null, 'reason' => 'schema_missing'];
            }
            $marker = DB::table('recovery_markers')->where('scope', self::SCOPE)->first();
            if (! $marker) {
                return ['ready' => false, 'generation' => null, 'checkpoint_at' => null, 'reason' => 'marker_missing'];
            }
            $this->assertDecryptable($marker, trim((string) config('operations.recovery_encryption_key_id')));

            return [
                'ready' => true,
                'generation' => (string) $marker->generation,
                'checkpoint_at' => $marker->checkpoint_at,
                'reason' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['ready' => false, 'generation' => null, 'checkpoint_at' => null, 'reason' => 'marker_unreadable'];
        }
    }

    private function assertDecryptable(object $marker, string $expectedKeyId): void
    {
        if ($expectedKeyId === '' || ! hash_equals((string) $marker->encryption_key_id, $expectedKeyId)) {
            throw new RuntimeException('Recovery encryption key identifier does not match the database marker.');
        }
        $probe = Crypt::decryptString((string) $marker->encrypted_probe);
        if (! hash_equals((string) $marker->probe_hash, hash('sha256', $probe))) {
            throw new RuntimeException('Recovery encryption probe failed integrity verification.');
        }
    }
}
