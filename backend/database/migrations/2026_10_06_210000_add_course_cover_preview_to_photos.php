<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('photos', 'preview_path')) {
            Schema::table('photos', function (Blueprint $table): void {
                $table->string('preview_path', 500)->nullable()->after('path');
            });
        }
    }

    public function down(): void
    {
        Schema::table('photos', fn (Blueprint $table) => $table->dropColumn('preview_path'));
    }
};
