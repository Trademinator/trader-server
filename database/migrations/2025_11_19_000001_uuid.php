<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'id')) {
                $table->dropColumn('id');
            }
            if (!Schema::hasColumn('users', 'user_id')) {
                $table->uuid('user_id')->primary();
            }
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('user_id');
            //$table->dropForeign('user_id');
            $table->uuid('user_id')->nullable()->bigIncrements()->index();;
            $table->foreign('user_id')->references('user_id')->on('users');
        });


    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            if (Schema::hasIndex('sessions', ['user_id'])) {
                $table->dropForeign('sessions_user_id_index');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'user_id')) {
                $table->dropColumn('user_id');
            }
            $table->id();
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->index();
        });
    }
};
