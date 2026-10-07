<?php

namespace App\Domain\Intelligence;

use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Specifications\DatasetIsLabeled;
use Rubix\ML\Specifications\DatasetIsNotEmpty;
use Rubix\ML\Specifications\LabelsAreCompatibleWithLearner;
use Rubix\ML\Specifications\SamplesAreCompatibleWithEstimator;
use Rubix\ML\Specifications\SpecificationChain;
use Rubix\ML\Specifications\DatasetHasDimensionality;
use Rubix\ML\Exceptions\RuntimeException;

/**
 * Rubix RandomForest with bounded intermediate memory.
 *
 * Rubix's Serial backend defers all training tasks, so the stock implementation
 * retains every bootstrap Dataset until process(). Train each tree immediately
 * instead, and aggregate probability matrices one tree at a time.
 */
final class SequentialRandomForest extends RandomForest
{
    public function train(Dataset $dataset): void
    {
        SpecificationChain::with([
            new DatasetIsLabeled($dataset),
            new DatasetIsNotEmpty($dataset),
            new SamplesAreCompatibleWithEstimator($dataset, $this),
            new LabelsAreCompatibleWithLearner($dataset, $this),
        ])->check();

        $p = max(self::MIN_SUBSAMPLE, (int) ceil($this->ratio * $dataset->numSamples()));
        $weights = null;
        if ($this->balanced) {
            $counts = array_count_values($dataset->labels());
            $min = min($counts);
            $weights = [];
            foreach ($dataset->labels() as $label) {
                $weights[] = $min / $counts[$label];
            }
        }

        $trees = [];
        for ($i = 0; $i < $this->estimators; $i++) {
            $tree = clone $this->base;
            $subset = $weights === null
                ? $dataset->randomSubsetWithReplacement($p)
                : $dataset->randomWeightedSubsetWithReplacement($p, $weights);
            $tree->train($subset);
            $trees[] = $tree;
            unset($subset, $tree);
        }

        $this->trees = $trees;
        $this->classes = array_fill_keys($dataset->possibleOutcomes(), 0.0);
        $this->featureCount = $dataset->numFeatures();
    }

    /** @return list<resource> */
    public function spillTrees(): array
    {
        if (! $this->trees) {
            return [];
        }

        $spools = [];
        foreach ($this->trees as $tree) {
            $spool = tmpfile();
            if ($spool === false) {
                $this->closeSpools($spools);

                throw new RuntimeException('Could not create temporary random-forest tree spool.');
            }
            $serialized = serialize($tree);
            if (fwrite($spool, $serialized) !== strlen($serialized)) {
                fclose($spool);
                $this->closeSpools($spools);

                throw new RuntimeException('Could not spool random-forest tree.');
            }
            unset($serialized);
            $spools[] = $spool;
        }
        $this->trees = [];

        return $spools;
    }

    /** @param list<resource> $spools */
    public function restoreTrees(array $spools): void
    {
        $trees = [];
        foreach ($spools as $spool) {
            rewind($spool);
            $serialized = stream_get_contents($spool);
            fclose($spool);
            $tree = unserialize($serialized, ['allowed_classes' => true]);
            unset($serialized);
            if (! $tree instanceof \Rubix\ML\Classifiers\ClassificationTree
                && ! $tree instanceof \Rubix\ML\Classifiers\ExtraTreeClassifier) {
                throw new RuntimeException('Could not restore random-forest tree.');
            }
            $trees[] = $tree;
        }
        $this->trees = $trees;
    }

    /** @param list<resource> $spools */
    public function closeSpools(array $spools): void
    {
        foreach ($spools as $spool) {
            if (is_resource($spool)) {
                fclose($spool);
            }
        }
    }

    public function proba(Dataset $dataset): array
    {
        if (! $this->trees || ! $this->classes || ! $this->featureCount) {
            throw new RuntimeException('Estimator has not been trained.');
        }

        DatasetHasDimensionality::with($dataset, $this->featureCount)->check();
        $probabilities = array_fill(0, $dataset->numSamples(), $this->classes);

        foreach ($this->trees as $tree) {
            $treeProbabilities = $tree->proba($dataset);
            foreach ($treeProbabilities as $i => $joint) {
                foreach ($joint as $class => $probability) {
                    $probabilities[$i][$class] += $probability;
                }
            }
            unset($treeProbabilities);
        }

        foreach ($probabilities as &$joint) {
            foreach ($joint as &$probability) {
                $probability /= $this->estimators;
            }
            unset($probability);
        }
        unset($joint);

        return $probabilities;
    }
}
