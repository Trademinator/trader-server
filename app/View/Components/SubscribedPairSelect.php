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
        public string $placeholder = '',
    ) {
        parent::__construct($name, $id, $value, $label, $bag);
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        if ($datasets !== []) {
            foreach ($pairs->datasets($user, $datasets, $allSubscribed, $sort) as $dataset) {
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
            $parts[] = substr($id, 0, 8);
        } elseif ($current) {
            $parts[] = 'current dataset';
        }

        return ['value' => $id, 'label' => implode(' · ', $parts)];
    }
}
