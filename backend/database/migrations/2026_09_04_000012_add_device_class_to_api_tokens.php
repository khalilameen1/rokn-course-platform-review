<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'device_class')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->string('device_class', 12)->nullable()->after('platform');
        });
    }

    public function down(): void
    {
        $tableName = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, 'device_class')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropColumn('device_class');
        });
    }
};
