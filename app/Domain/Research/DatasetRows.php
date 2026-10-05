<?php

namespace App\Domain\Research;

use Countable;
use Generator;
use IteratorAggregate;
use OutOfBoundsException;
use RuntimeException;

/**
 * Request-local disk index; decode only requested rows, with no dataset-sized PHP arrays.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class DatasetRows implements Countable, IteratorAggregate
{
    private const ENTRY_BYTES = 56;

    /**
     * @param  resource  $file
     * @param  resource  $index
     */
    public function __construct(private mixed $file, private mixed $index, private int $size) {}

    public function __destruct()
    {
        fclose($this->file);
        fclose($this->index);
    }

    public static function indexEntry(int $offset, array $row, string $line): string
    {
        return pack('J3', $offset, $row['decision_at_ms'], (int) ($row['microtimestamp'] ?? 0)).hash('sha256', $line, true);
    }

    public function count(): int
    {
        return $this->size;
    }

    public function at(int $position): array
    {
        $entry = $this->entry($position);
        if (fseek($this->file, $entry['offset']) !== 0 || ($line = fgets($this->file)) === false) {
            throw new RuntimeException('Failed reading dataset rows.');
        }
        if (! hash_equals($entry['sha256'], hash('sha256', $line, true))) {
            throw new RuntimeException('Dataset row checksum changed after verification.');
        }

        return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
    }

    public function indexOfDecision(int $decision): ?int
    {
        $low = 0;
        $high = $this->size - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $current = $this->entry($middle)['decision_at_ms'];
            if ($current === $decision) {
                return $middle;
            }
            if ($current < $decision) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return null;
    }

    public function findDecision(int $decision): ?array
    {
        $position = $this->indexOfDecision($decision);

        return $position === null ? null : $this->at($position);
    }

    /** The last eligible row, including datasets with gaps in candle history. */
    public function before(int $timestamp, int $decision): ?array
    {
        $position = null;
        foreach ($this->metadata() as $index => $entry) {
            if ($entry['decision_at_ms'] > $decision) {
                break;
            }
            if ($entry['microtimestamp'] < $timestamp) {
                $position = $index;
            }
        }

        return $position === null ? null : $this->at($position);
    }

    /**
     * @param  list<int>  $timestamps
     * @return array<int, array<string, mixed>>
     */
    public function forTimestamps(array $timestamps): array
    {
        $wanted = array_fill_keys($timestamps, true);
        $rows = [];
        foreach ($this->metadata() as $position => $entry) {
            if (isset($wanted[$entry['microtimestamp']])) {
                $rows[$entry['microtimestamp']] = $this->at($position);
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function slice(int $offset, int $length): array
    {
        $rows = [];
        for ($position = max(0, $offset); $position < min($this->size, $offset + $length); $position++) {
            $rows[] = $this->at($position);
        }

        return $rows;
    }

    /** @return Generator<int, array{index: int, decision_at_ms: int, microtimestamp: int}> */
    public function metadata(): Generator
    {
        for ($position = 0; $position < $this->size; $position++) {
            $entry = $this->entry($position);
            yield $position => ['index' => $position, 'decision_at_ms' => $entry['decision_at_ms'], 'microtimestamp' => $entry['microtimestamp']];
        }
    }

    public function getIterator(): Generator
    {
        for ($position = 0; $position < $this->size; $position++) {
            yield $position => $this->at($position);
        }
    }

    /** @return array{offset: int, decision_at_ms: int, microtimestamp: int, sha256: string} */
    private function entry(int $position): array
    {
        if ($position < 0 || $position >= $this->size) {
            throw new OutOfBoundsException('Dataset row index is outside the frozen dataset.');
        }
        if (fseek($this->index, $position * self::ENTRY_BYTES) !== 0
            || ($bytes = fread($this->index, self::ENTRY_BYTES)) === false || strlen($bytes) !== self::ENTRY_BYTES) {
            throw new RuntimeException('Failed reading the temporary dataset index.');
        }

        return [...unpack('Joffset/Jdecision_at_ms/Jmicrotimestamp', $bytes), 'sha256' => substr($bytes, 24)];
    }
}
