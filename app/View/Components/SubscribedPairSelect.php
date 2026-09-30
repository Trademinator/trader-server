<?php

namespace App\View\Components;

use App\Domain\MarketData\SubscribedPairOptions;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class SubscribedPairSelect extends FormControl
{
    /** @var array<int, array{value:string,label:string}> */
    public array $options = [];

    public function __construct(
        SubscribedPairOptions $pairs,
        string $name,
        ?string $id = null,
        string $value = '',
        string $label = '',
        string $bag = 'default',
        public bool $allSubscribed = false,
        public array|string|null $sort = null,
        public array $datasets = [],
        public ?array $currentDataset = null,
        public bool $showDatasetVersions = false,
        public string $placeholder = '',
    ) {
        parent::__construct($name, $id, $value, $label, $bag);
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        if ($datasets !== []) {
            $availableDatasets = $pairs->datasets($user, $datasets, $allSubscribed, $sort);
            if (! $showDatasetVersions) {
                $availableDatasets = $this->collapseDatasetVersions($availableDatasets, $currentDataset);
            }
            foreach ($availableDatasets as $dataset) {
                $option = $this->datasetOption($dataset);
                if ($option !== null) {
                    $this->options[] = $option;
                }
            }
            if ($currentDataset !== null) {
                $current = $this->datasetOption($currentDataset, true);
                if ($current !== null && ! collect($this->options)->contains('value', $current['value'])) {
                    $this->options[] = $current;
                }
            }

            return;
        }

        $this->options = array_map(fn (array $market): array => [
            'value' => $market['market_id'],
            'label' => $market['exchange'].' · '.$market['pair'].' · '.($market['period'] !== '' ? $market['period'] : 'Period pending'),
        ], $pairs->markets($user, $allSubscribed, $sort));
    }

    public function isSelected(string $option): bool
    {
        return (string) $option === (string) $this->value;
    }

    public function render(): View
    {
        return view('components.subscribed-pair-select');
    }

    /**
     * @param  array<string, mixed>  $dataset
     * @return array{value:string,label:string}|null
     */
    private function datasetOption(array $dataset, bool $current = false): ?array
    {
        $id = $dataset['dataset_id'] ?? null;
        $exchange = $dataset['exchange_name'] ?? $dataset['exchange'] ?? null;
        $pair = $dataset['symbol'] ?? null;
        $period = $dataset['period'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($exchange) || ! is_string($pair) || ! is_string($period)) {
            return null;
        }
        $parts = [$exchange, $pair, $period];
        if (isset($dataset['rows']) && is_numeric($dataset['rows'])) {
            $parts[] = number_format((int) $dataset['rows']).' samples';
            if ($this->showDatasetVersions) {
                $parts[] = substr($id, 0, 8);
            }
        } elseif ($current && $this->showDatasetVersions) {
            $parts[] = 'current dataset';
        }

        return ['value' => $id, 'label' => implode(' · ', $parts)];
    }

    /**
     * Keep one dataset per exchange/pair/period for normal selectors. The dataset list
     * arrives newest first. On an open replay page, prefer the dataset being viewed so
     * the selected value never changes underneath the user.
     *
     * @param  array<int, array<string, mixed>>  $datasets
     * @param  array<string, mixed>|null  $currentDataset
     * @return array<int, array<string, mixed>>
     */
    private function collapseDatasetVersions(array $datasets, ?array $currentDataset): array
    {
        $currentKey = $currentDataset === null ? null : $this->datasetKey($currentDataset);
        $collapsed = [];
        $seen = [];

        foreach ($datasets as $dataset) {
            $key = $this->datasetKey($dataset);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            if ($currentDataset !== null && $currentKey === $key) {
                $dataset = [
                    ...$currentDataset,
                    'exchange_name' => $dataset['exchange_name'] ?? $currentDataset['exchange_name'] ?? null,
                ];
            }
            $collapsed[] = $dataset;
            $seen[$key] = true;
        }

        return $collapsed;
    }

    /** @param array<string, mixed> $dataset */
    private function datasetKey(array $dataset): ?string
    {
        $exchange = $dataset['exchange'] ?? null;
        $pair = $dataset['symbol'] ?? null;
        $period = $dataset['period'] ?? null;
        if (! is_string($exchange) || ! is_string($pair) || ! is_string($period)) {
            return null;
        }

        return strtolower($exchange).'|'.$pair.'|'.$period;
    }
}
