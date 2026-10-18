<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * One staff search over learner accounts, shared by the students list and
 * student progress.
 *
 * Input that looks like an identifier is matched the way it is indexed:
 * `#123`/`UID:123` is an exact id, an email is an exact or prefix match on the
 * unique email index, and a phone number is a prefix match on the unique phone
 * index (local 01… numbers also try the stored 201… form). Only free text
 * falls back to a contains-match on the name columns. User input is always
 * escaped so `%` and `_` are literal characters, never wildcards.
 */
final class AdminStudentSearch
{
    /** Portable across MySQL, PostgreSQL and SQLite (backslash is not). */
    private const ESCAPE = '!';

    /** @param Builder<User> $users */
    public static function apply(Builder $users, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        if (preg_match('/^(UID\s*:\s*|#\s*)([1-9][0-9]*)$/iD', $search, $identity) === 1) {
            $id = filter_var($identity[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $id === false ? $users->whereRaw('1 = 0') : $users->whereKey($id);

            return;
        }

        if (str_contains($search, '@')) {
            $email = mb_strtolower($search);
            $users->where(function (Builder $match) use ($email): void {
                $match->where('email', $email);
                self::whereLike($match, 'email', self::escape($email).'%', 'or');
            });

            return;
        }

        $digits = preg_replace('/[\s\-().]/', '', $search) ?? '';
        if (preg_match('/^\+?[0-9]{1,20}$/D', $digits) === 1) {
            $digits = ltrim($digits, '+');
            $prefixes = [$digits];
            if (str_starts_with($digits, '0') && strlen($digits) > 1) {
                $prefixes[] = '20'.substr($digits, 1);
            }
            $id = filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $users->where(function (Builder $match) use ($prefixes, $id, $digits): void {
                foreach (array_unique($prefixes) as $prefix) {
                    self::whereLike($match, 'phone', self::escape($prefix).'%', 'or');
                }
                if ($id !== false && $digits[0] !== '0') {
                    $match->orWhere('id', $id);
                }
            });

            return;
        }

        $pattern = '%'.self::escape($search).'%';
        $users->where(function (Builder $match) use ($pattern): void {
            foreach (['name', 'name_ar', 'name_en'] as $column) {
                self::whereLike($match, $column, $pattern, 'or');
            }
        });
    }

    public static function escape(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $value
        );
    }

    /** @param Builder<User> $query */
    private static function whereLike(Builder $query, string $column, string $pattern, string $boolean): void
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);
        $query->whereRaw("{$wrapped} LIKE ? ESCAPE '".self::ESCAPE."'", [$pattern], $boolean);
    }
}
