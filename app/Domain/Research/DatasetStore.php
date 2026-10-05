<?php

namespace App\Domain\Research;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class DatasetStore
{
    public function directory(string $id): string
    {
        if (! Str::isUuid($id)) {
            throw new InvalidArgumentException('Dataset ID must be a UUID.');
        }

        return rtrim(config('research.path'), '/').'/'.$id;
    }

    public function manifest(string $id): array
    {
        $directory = $this->directory($id);
        $record = DB::table('research_datasets')->where('dataset_id', $id)->first();
        if ($record === null) {
            throw new InvalidArgumentException('Unknown dataset ID.');
        }
        $manifest = json_decode($record->manifest, true, flags: JSON_THROW_ON_ERROR);
        $onDisk = is_file($directory.'/manifest.json') ? file_get_contents($directory.'/manifest.json') : false;
        // JSON database engines may reorder object properties or normalize 10.0 to 10.
        // Numeric array positions (including feature order) still have to match.
        if ($onDisk === false || json_decode($onDisk, true, flags: JSON_THROW_ON_ERROR) != $manifest) {
            throw new RuntimeException('Dataset manifest is missing or does not match its database record.');
        }
        if (($manifest['format_version'] ?? null) !== 'm3-dataset-v1') {
            throw new RuntimeException('Unsupported dataset format version.');
        }

        return $manifest;
    }

    public function load(string $id, ?int $maxRows = null): array
    {
        return $this->read($id, $maxRows, indexed: false);
    }

    /** @return array{array, DatasetRows} */
    public function open(string $id, ?int $maxRows = null): array
    {
        return $this->read($id, $maxRows, indexed: true);
    }

    /** Verify the complete artifact before exposing either rows or their disk index. */
    private function read(string $id, ?int $maxRows, bool $indexed): array
    {
        $manifest = $this->manifest($id);
        $semantic = ($manifest['label_definition']['version'] ?? null) === SemanticLabels::VERSION;
        $limit = min($semantic ? PHP_INT_MAX : (int) config('research.max_rows'), $maxRows ?? PHP_INT_MAX);
        if ($manifest['rows'] > $limit) {
            throw new RuntimeException('Dataset exceeds research.max_rows; use a smaller date range.');
        }
        $file = @fopen($this->directory($id).'/rows.jsonl', 'rb');
        if ($file === false) {
            throw new RuntimeException('Dataset rows are missing.');
        }
        $hash = hash_init('sha256');
        $rows = [];
        $index = null;
        $count = 0;
        $offset = 0;
        $previous = null;
        try {
            if ($indexed) {
                $index = @tmpfile();
                if ($index === false) {
                    throw new RuntimeException('Could not create the temporary dataset index.');
                }
            }
            while (($line = fgets($file)) !== false) {
                hash_update($hash, $line);
                $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (! is_int($row['decision_at_ms'] ?? null) || ($previous !== null && $row['decision_at_ms'] <= $previous)
                    || count($row['vector'] ?? []) !== count($manifest['keys'])
                    || ($row['label_available_at_ms'] ?? 0) <= $row['decision_at_ms']) {
                    throw new RuntimeException('Invalid dataset row contract.');
                }
                $previous = $row['decision_at_ms'];
                if ($indexed) {
                    self::write($index, DatasetRows::indexEntry($offset, $row, $line));
                } else {
                    $rows[] = $row;
                }
                $offset += strlen($line);
                $count++;
                if ($count > $limit) {
                    throw new RuntimeException('Dataset exceeds research.max_rows.');
                }
            }
            if (! feof($file)) {
                throw new RuntimeException('Failed reading dataset rows.');
            }
            if (! hash_equals($manifest['rows_sha256'], hash_final($hash)) || $count !== $manifest['rows']) {
                throw new RuntimeException('Dataset checksum or row count mismatch.');
            }
            if ($indexed) {
                return [$manifest, new DatasetRows($file, $index, $count)];
            }
        } catch (Throwable $exception) {
            fclose($file);
            if (is_resource($index)) {
                fclose($index);
            }
            throw $exception;
        }
        fclose($file);

        return [$manifest, $rows];
    }

    public static function write($file, string $bytes): void
    {
        if (fwrite($file, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Could not write research artifact.');
        }
    }
}
