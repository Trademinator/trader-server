<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that retain historical data and are expected to grow continuously.
     *
     * @var list<string>
     */
    private const TABLES = [
        'tickers',
        'knowledge',
        'market_context_snapshots',
        'market_features',
        'research_datasets',
        'research_backtests',
        'intelligence_models',
    ];

    public function up(): void
    {
        if (! $this->isMariaDb()) {
            return;
        }

        $this->assertFilePerTableEnabled();

        // MariaDB 10.3.10+ can enable PAGE_COMPRESSED with an INSTANT alter.
        // Force INSTANT so this migration fails rather than unexpectedly
        // rebuilding a large history table on a production/Galera server.
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
        // Intentionally retain page compression on rollback. MariaDB cannot
        // disable PAGE_COMPRESSED with ALGORITHM=INSTANT; doing so can rebuild
        // these potentially very large tables. Disable it only as an explicit
        // DBA operation when a table rebuild is acceptable.
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
