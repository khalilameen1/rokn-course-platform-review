<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A teacher is a public course profile, not a dashboard identity.
        // Moderators can author that profile without being allowed to create
        // or inspect login credentials, so email must not be a hidden schema
        // requirement for an otherwise valid teacher record.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Teacher profiles legitimately have no login email. Restoring NOT
        // NULL would make those rows invalid, so this correction is forward-only.
    }
};
