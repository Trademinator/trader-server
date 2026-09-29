<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dashboard/access history tables added after the first compression pass.
     *
     * @var list<string>
     */
    private const TABLES = [
        'market_signals',
        'access_daily_stats',
        'access_daily_visitors',
    ];

    public function up(): void
    {
        if (! $this->isMariaDb()) {
            return;
        }

        $this->assertFilePerTableEnabled();

        DB::statement("SET SESSION alter_algorithm='INSTANT'");

        try {
            foreach (self::TABLES as $table) {
                if (Schema::hasTable($table)) {
                    DB::statement(sprintf('ALTER TABLE `%s` PAGE_COMPRESSED=1', $table));
                }
            }
        } finally {
            DB::statement("SET SESSION alter_algorithm='DEFAULT'");
        }
    }

    public function down(): void
    {
        // Intentionally retain page compression on rollback. Disabling it can
        // require rebuilding these growing tables and should be an explicit
        // DBA operation rather than an automatic rollback side effect.
    }

    private function isMariaDb(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $result = DB::selectOne('SELECT VERSION() AS version');

        return str_contains(strtolower((string) ($result->version ?? '')), 'mariadb');
    }

    private function assertFilePerTableEnabled(): void
    {
        $result = DB::selectOne('SELECT @@innodb_file_per_table AS enabled');

        if ((int) ($result->enabled ?? 0) !== 1) {
            throw new \RuntimeException(
                'MariaDB InnoDB page compression requires innodb_file_per_table=ON.'
            );
        }
    }
};
