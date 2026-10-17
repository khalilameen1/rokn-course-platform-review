<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coupon codes are matched exactly like course codes: the model mutator
 * stores the normalized identifier and checkout looks it up by an indexed
 * equality. Rows written before the mutator are normalized here so the
 * unique index (and the `Rule::unique('coupons', 'code')` dashboard check)
 * sees the same value the learner types.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'coupons_code_unique';

    // Frozen copy of App\Support\UnicodeText::identifier() at this release;
    // tail migrations must not depend on mutable application code.
    private const BIDI_AND_INVISIBLE_PATTERN =
        '/[\x{00AD}\x{034F}\x{061C}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u';

    public function up(): void
    {
        if (!Schema::hasTable('coupons') || !Schema::hasColumn('coupons', 'code')) {
            return;
        }

        $owners = [];
        $updates = [];
        DB::table('coupons')->select(['id', 'code'])->orderBy('id')
            ->each(function (object $row) use (&$owners, &$updates): void {
                $normalized = $this->identifier($row->code);
                if ($normalized !== null) {
                    if (isset($owners[$normalized])) {
                        throw new RuntimeException(sprintf(
                            'Coupons #%d and #%d share the normalized code %s. Rename one before migrating.',
                            $owners[$normalized],
                            $row->id,
                            $normalized
                        ));
                    }
                    $owners[$normalized] = (int) $row->id;
                }
                if ($row->code !== $normalized) {
                    $updates[(int) $row->id] = $normalized;
                }
            });

        foreach ($updates as $id => $code) {
            DB::table('coupons')->where('id', $id)->update(['code' => $code]);
        }

        if (!Schema::hasIndex('coupons', self::INDEX)) {
            Schema::table('coupons', fn (Blueprint $table) => $table->unique('code', self::INDEX));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('coupons') && Schema::hasIndex('coupons', self::INDEX)) {
            Schema::table('coupons', fn (Blueprint $table) => $table->dropUnique(self::INDEX));
        }
    }

    private function identifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = (string) $value;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $repaired = function_exists('iconv') ? iconv('UTF-8', 'UTF-8//IGNORE', $text) : false;
            $text = is_string($repaired) ? $repaired : '';
        }
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_KC) ?: $text;
        }
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $text = preg_replace(self::BIDI_AND_INVISIBLE_PATTERN, '', $text) ?? '';
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/\s+/u', '', $text) ?? '';
        $text = mb_strtoupper($text, 'UTF-8');

        return $text !== '' ? $text : null;
    }
};
