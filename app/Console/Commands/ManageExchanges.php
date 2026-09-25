<?php

namespace App\Console\Commands;

use App\Models\Exchange as ExchangeModel;
use App\Models\Market;
use Illuminate\Support\Facades\DB;
use ccxt\Exchange as CcxtExchange;
use Illuminate\Console\Command;
use JsonException;

class ManageExchanges extends Command
{
    protected $signature = 'trademinator:exchange
        {action : add, edit, delete, or list}
        {class? : The CCXT exchange ID, such as kraken}
        {--name= : Exchange display name for add or edit}
        {--config= : CCXT settings as a JSON object}
        {--config-file= : Path to a file containing a CCXT JSON object}
        {--search= : Filter the list by CCXT ID or name}
        {--force : Skip the deletion confirmation}';

    protected $description = 'Add, edit, list, or delete configured CCXT exchanges';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if ($action === 'list') {
            return $this->listExchanges();
        }

        if (! in_array($action, ['add', 'edit', 'delete'], true)) {
            $this->error('Action must be add, edit, delete, or list.');

            return self::FAILURE;
        }

        $class = (string) $this->argument('class');
        if ($class === '') {
            $this->error('Provide the CCXT exchange ID (for example, kraken).');

            return self::FAILURE;
        }

        $matches = ExchangeModel::query()->where('class', $class)->limit(2)->get();
        if ($matches->count() > 1) {
            $this->error("Multiple records use {$class}. Resolve the duplicate records before changing this exchange.");

            return self::FAILURE;
        }
        $exchange = $matches->first();

        return match ($action) {
            'add' => $this->addExchange($class, $exchange),
            'edit' => $this->editExchange($class, $exchange),
            'delete' => $this->deleteExchange($class, $exchange),
        };
    }

    private function addExchange(string $class, ?ExchangeModel $exchange): int
    {
        if ($exchange !== null) {
            $this->error("Exchange {$class} already exists. Use edit to change it.");

            return self::FAILURE;
        }

        if (! in_array($class, CcxtExchange::$exchanges, true)) {
            $this->error("{$class} is not a CCXT exchange ID supported by the installed version.");

            return self::FAILURE;
        }

        $name = $this->nameOption() ?? $class;
        $config = $this->configOption() ?? '{}';
        if ($name === false || $config === false) {
            return self::FAILURE;
        }

        $created = ExchangeModel::query()->create(['class' => $class, 'name' => $name, 'config' => $config]);
        $this->info("Added {$created->class} ({$created->exchange_id}).");

        return self::SUCCESS;
    }

    private function editExchange(string $class, ?ExchangeModel $exchange): int
    {
        if ($exchange === null) {
            $this->error("Exchange {$class} does not exist. Use add first.");

            return self::FAILURE;
        }

        if ($this->option('name') === null && $this->option('config') === null && $this->option('config-file') === null) {
            $this->error('Provide --name, --config, or --config-file to edit an exchange.');

            return self::FAILURE;
        }

        $name = $this->nameOption();
        $config = $this->configOption();
        if ($name === false || $config === false) {
            return self::FAILURE;
        }

        if ($name !== null) {
            $exchange->name = $name;
        }
        if ($config !== null) {
            $exchange->config = $config;
        }
        $exchange->save();
        $this->info("Updated {$exchange->class} ({$exchange->exchange_id}).");

        return self::SUCCESS;
    }

    private function deleteExchange(string $class, ?ExchangeModel $exchange): int
    {
        if ($exchange === null) {
            $this->error("Exchange {$class} does not exist.");

            return self::FAILURE;
        }

        if (Market::query()->where('exchange_id', $exchange->exchange_id)
            ->whereHas('subscriptions', fn ($query) => $query->where('active', true))->exists()) {
            $this->error('This exchange still has active market subscriptions. Unsubscribe users before deleting it.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Delete exchange {$class} configuration? Historical candles will remain.", false)) {
            $this->warn('Exchange was not deleted.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($exchange): void {
            foreach (Market::query()->where('exchange_id', $exchange->exchange_id)->get() as $market) {
                $market->delete(); // Inactive subscriptions and feed cascade; ticker history remains.
            }
            $exchange->delete();
        });
        $this->info("Deleted exchange {$class} configuration. Historical candles remain.");

        return self::SUCCESS;
    }

    private function listExchanges(): int
    {
        if ($this->argument('class') !== null) {
            $this->error('Use --search to filter the exchange list.');

            return self::FAILURE;
        }

        $search = $this->option('search');
        $query = ExchangeModel::query();
        if ($search !== null && $search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('class', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%');
            });
        }

        $rows = $query->orderBy('class')->get(['class', 'name', 'exchange_id']);
        $this->table(['CCXT ID', 'Name', 'UUID'], $rows->map(fn (ExchangeModel $exchange): array => [
            $exchange->class, $exchange->name, $exchange->exchange_id,
        ])->all());

        return self::SUCCESS;
    }

    private function nameOption(): string|false|null
    {
        $option = $this->option('name');
        if ($option === null) {
            return null;
        }

        $name = trim((string) $option);
        if ($name === '' || mb_strlen($name) > 64) {
            $this->error('The exchange name must contain 1 to 64 characters.');

            return false;
        }

        return $name;
    }

    private function configOption(): string|false|null
    {
        $json = $this->option('config');
        $file = $this->option('config-file');

        if ($json !== null && $file !== null) {
            $this->error('Use either --config or --config-file, not both.');

            return false;
        }

        if ($file !== null) {
            if (! is_file($file) || ! is_readable($file) || filesize($file) > 65536) {
                $this->error('The config file must be a readable JSON file of at most 64 KiB.');

                return false;
            }
            $json = file_get_contents($file);
            if ($json === false) {
                $this->error('Could not read the config file.');

                return false;
            }
        }

        if ($json === null) {
            return null;
        }

        try {
            $decoded = json_decode((string) $json, flags: JSON_THROW_ON_ERROR);
            if (! is_object($decoded)) {
                $this->error('CCXT config must be a JSON object, such as {}.');

                return false;
            }

            return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $this->error('CCXT config must contain valid JSON.');

            return false;
        }
    }
}
