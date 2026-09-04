<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $table = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'session_id')) {
            return;
        }

        do {
            $tokens = DB::table($table)
                ->whereNull('session_id')
                ->orderBy('token')
                ->limit(500)
                ->pluck('token');

            foreach ($tokens as $token) {
                DB::table($table)
                    ->where('token', $token)
                    ->whereNull('session_id')
                    ->update(['session_id' => (string) Str::uuid()]);
            }
        } while ($tokens->count() === 500);
    }

    public function down(): void
    {
        // Session ids become public revocation handles. Removing generated
        // values would make existing signed-in devices unmanageable again.
    }
};
