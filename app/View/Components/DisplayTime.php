<?php

namespace App\View\Components;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class DisplayTime extends Component
{
    public ?CarbonImmutable $instant = null;

    public string $text = '';

    public function __construct(
        DateTimeInterface|string|int|float|null $value = null,
        string $unit = 'date',
        public string $precision = 'seconds',
        public string $fallback = 'Not available',
    ) {
        if ($value === null || $value === '') {
            return;
        }

        $this->instant = ($value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : match ($unit) {
                'milliseconds' => CarbonImmutable::createFromTimestampMs($value, 'UTC'),
                'seconds' => CarbonImmutable::createFromTimestamp($value, 'UTC'),
                default => CarbonImmutable::parse($value, 'UTC'),
            })->utc();

        $local = $this->instant->setTimezone(auth()->user()?->timezone ?? 'UTC');
        $format = match ($precision) {
            'minutes' => 'Y-m-d H:i',
            'date' => 'Y-m-d',
            'time' => 'H:i:s',
            default => 'Y-m-d H:i:s',
        };
        $this->text = $local->format($format).' UTC'.($local->offset === 0 ? '' : $local->format('P'));
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        return view('components.display-time');
    }
}
