<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_api_keys', function (Blueprint $table): void {
            $table->string('prefix', 20)->change();
        });
    }

    public function down(): void
    {
        $hasLongPrefix = DB::table('client_api_keys')->pluck('prefix')
            ->contains(fn (mixed $prefix): bool => strlen((string) $prefix) > 12);
        if ($hasLongPrefix) {
            throw new RuntimeException('Cannot narrow Client API key prefixes while 20-character prefixes exist.');
        }

        Schema::table('client_api_keys', function (Blueprint $table): void {
            $table->string('prefix', 12)->change();
        });
    }
};
