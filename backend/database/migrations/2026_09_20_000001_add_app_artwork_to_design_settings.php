<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    private const COLUMNS = [
        'coin_image_url', 'coin_stack_image_url', 'badge_junior_image_url',
        'badge_mid_image_url', 'badge_senior_image_url',
    ];

    public function up(): void
    {
        // Five utf8mb4 VARCHAR(2048) columns exceed this existing table's
        // MySQL row budget. TEXT keeps URL payloads outside that budget.
        // Also resume a partially applied MySQL DDL without losing artwork.
        foreach (self::COLUMNS as $column) {
            $exists = Schema::hasColumn('design_settings', $column);
            if ($exists && Schema::getColumnType('design_settings', $column) === 'text') {
                continue;
            }
            Schema::table('design_settings', function (Blueprint $table) use ($column, $exists): void {
                $definition = $table->text($column)->nullable();
                if ($exists) {
                    $definition->change();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('design_settings', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
