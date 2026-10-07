<?php

namespace App\Domain\Intelligence;

use App\Models\HumanTrainingSnapshot;
use RuntimeException;
use Throwable;

final class HumanTrainingExport
{
    public const FORMAT = 'trademinator-human-training-v2';

    public function write(string $path): array
    {
        $file = @fopen($path, 'xb');
        if ($file === false) {
            throw new RuntimeException('Cannot create export; choose a new filename in an existing writable private directory.');
        }
        $cutoff = now();
        $hash = hash_init('sha256');
        $count = 0;
        try {
            $write = function (array $record) use ($file, $hash): void {
                $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                if (fwrite($file, $line) !== strlen($line)) {
                    throw new RuntimeException('Failed writing human training export.');
                }
                hash_update($hash, $line);
            };
            if (! chmod($path, 0600)) {
                throw new RuntimeException('Cannot restrict export permissions.');
            }
            $write(['type' => 'manifest', 'format' => self::FORMAT, 'snapshot_version' => HumanTraining::VERSION,
                'outcome_labels' => HumanTraining::LABELS, 'candle_actions' => CandleTraining::ACTIONS,
                'exported_at' => $cutoff->toIso8601String(), 'financial_values' => 'decimal_strings',
                'feature_values' => 'normalized_numbers', 'identity' => 'trainer_uuid_only',
                'objective_labels' => 'separate_research_datasets']);
            $query = HumanTrainingSnapshot::query()->where(function ($query) use ($cutoff): void {
                $query->whereHas('reviews', fn ($reviews) => $reviews->whereNotNull('submitted_at')
                    ->where('submitted_at', '<=', $cutoff->format('Y-m-d H:i:s.v')))
                    ->orWhereHas('candleLabels', fn ($labels) => $labels->where('updated_at', '<=', $cutoff->format('Y-m-d H:i:s.v')));
            })->with([
                'reviews' => fn ($query) => $query->whereNotNull('submitted_at')
                    ->where('submitted_at', '<=', $cutoff->format('Y-m-d H:i:s.v'))->orderBy('review_id'),
                'candleLabels' => fn ($query) => $query->where('updated_at', '<=', $cutoff->format('Y-m-d H:i:s.v'))->orderBy('candle_label_id'),
            ]);
            foreach ($query->lazyById(100, 'snapshot_id') as $snapshot) {
                $write(['type' => 'snapshot', 'snapshot_id' => $snapshot->snapshot_id,
                    'dataset_id' => $snapshot->dataset_id, 'sha256' => $snapshot->sha256,
                    'created_at' => $snapshot->created_at->toIso8601String(), 'payload' => $snapshot->verifiedPayload(),
                    'reviews' => $snapshot->reviews->map(fn ($review): array => [
                        'review_id' => $review->review_id, 'trainer_id' => $review->trainer_id,
                        'label' => $review->label, 'confidence' => $review->confidence, 'reason' => $review->reason,
                        'shown_at' => $review->shown_at->toISOString(), 'submitted_at' => $review->submitted_at->toISOString(),
                    ])->all(),
                    'candle_labels' => $snapshot->candleLabels->map(fn ($label): array => [
                        'candle_label_id' => $label->candle_label_id, 'trainer_id' => $label->trainer_id,
                        'action' => $label->action, 'created_at' => $label->created_at->toISOString(),
                        'updated_at' => $label->updated_at->toISOString(),
                    ])->all()]);
                $count++;
            }
            $result = ['type' => 'checksum', 'snapshots' => $count, 'sha256' => hash_final($hash)];
            $footer = json_encode($result, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($file, $footer) !== strlen($footer) || ! fflush($file)) {
                throw new RuntimeException('Failed completing human training export.');
            }

            return $result;
        } catch (Throwable $error) {
            fclose($file);
            unlink($path);
            throw $error;
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }
}
