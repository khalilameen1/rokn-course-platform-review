<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

/**
 * SQLite compiles no row locks, so this swaps in a grammar that marks each
 * locking read with a trailing SQL comment and records the lock type and
 * table in execution order. MySQL/PostgreSQL compile the same builder calls
 * to FOR UPDATE and LOCK IN SHARE MODE / FOR SHARE.
 */
trait RecordsRowLocks
{
    /** @return list<string> "table:update" or "table:shared" in execution order */
    private function recordLocks(callable $callback): array
    {
        $connection = DB::connection();
        $original = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                if (is_string($value)) {
                    return $value;
                }

                return $value ? '/* lock:update */' : '/* lock:shared */';
            }
        });
        $locks = [];
        $recording = true;
        DB::listen(function (QueryExecuted $query) use (&$locks, &$recording): void {
            if (
                $recording
                && preg_match('/\/\* lock:(update|shared) \*\/\s*$/', $query->sql, $lock)
                && preg_match('/\sfrom\s+"([a-z_]+)"/', $query->sql, $table)
            ) {
                $locks[] = $table[1].':'.$lock[1];
            }
        });

        try {
            $callback();
        } finally {
            $recording = false;
            $connection->setQueryGrammar($original);
        }

        return $locks;
    }
}
