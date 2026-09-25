<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * UUID primary keys are now created by the original user migration.
     *
     * Keep this migration as a no-op so existing migration histories remain
     * valid while fresh installs avoid a destructive integer-to-UUID rewrite.
     */
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
