<?php

namespace App\Domain\MarketEvents;

final class GdeltForkClassifier
{
    /**
     * @param array<string, list<string>> $contextEvidence
     * @param list<string> $symbols
     * @return array{event_type:string, confidence:float, matched_symbols:list<string>, evidence:array<string,mixed>, context_snippet:?string}
     */
    public function classify(string $title, array $contextEvidence, array $symbols): array
    {
        $snippets = [];
        foreach ($contextEvidence as $items) {
            foreach ($items as $snippet) {
                if ($snippet !== '') {
                    $snippets[] = $snippet;
                }
            }
        }
        $text = implode("\n", [$title, ...$snippets]);
        $lower = mb_strtolower($text);
        $contains = static fn (array $phrases): bool => collect($phrases)->contains(
            static fn (string $phrase): bool => str_contains($lower, $phrase)
        );

        $genericFork = preg_match('/\bforks?\b|\bforked\b|\bforking\b/u', $lower) === 1;
        $hardFork = $contains(['hard fork']);
        $chainSplit = $contains(['chain split', 'blockchain split']);
        $upgrade = $contains(['network upgrade', 'protocol upgrade', 'consensus upgrade', 'activation block', 'activation height']);
        $entitlement = $contains(['holders will receive', 'holders receive', 'eligible holders', 'distributed to holders', 'for every']);
        $snapshot = $contains(['snapshot block', 'snapshot height', 'snapshot date', 'snapshot will occur', 'snapshot']);
        $airdrop = $contains(['airdrop', 'air drop']);
        $migration = $contains(['token swap', 'token migration', 'mainnet migration']);
        $emergency = $contains(['emergency hard fork', 'emergency fork', 'chain rollback']);
        $codebase = $contains(['codebase fork', 'forked from bitcoin', 'fork of bitcoin']);
        $negative = $contains(['no new token', 'no chain split', 'will not create', 'not an airdrop']);
        $newAsset = $contains(['new token', 'new coin', 'new asset']) || ($entitlement && ! $negative);

        $eventType = match (true) {
            $emergency => 'emergency_fork',
            $migration => 'token_migration',
            $codebase && ! $entitlement && ! $snapshot => 'codebase_fork',
            $chainSplit || ($hardFork && $entitlement && $newAsset && ! $negative) => 'chain_split',
            $snapshot && ($airdrop || $entitlement) => 'snapshot_airdrop',
            $hardFork && ($upgrade || $negative) => 'protocol_hard_fork',
            default => 'unknown',
        };

        $matchedSymbols = array_values(array_filter($symbols, static function (string $symbol) use ($text): bool {
            return preg_match('/(?<![A-Za-z0-9])'.preg_quote($symbol, '/').'(?![A-Za-z0-9])/u', $text) === 1;
        }));

        $confidence = 0.20;
        $confidence += $genericFork ? 0.25 : 0.0;
        $confidence += ($hardFork || $chainSplit) ? 0.10 : 0.0;
        $confidence += $upgrade ? 0.25 : 0.0;
        $confidence += $snapshot ? 0.15 : 0.0;
        $confidence += ($entitlement || $airdrop) ? 0.15 : 0.0;
        $confidence += ($migration || $emergency) ? 0.30 : 0.0;
        $confidence += $codebase ? 0.25 : 0.0;
        $confidence += $eventType !== 'unknown' ? 0.15 : 0.0;
        $confidence += $newAsset ? 0.05 : 0.0;
        $confidence += $matchedSymbols !== [] ? 0.05 : 0.0;
        $confidence = round(min(0.98, $confidence), 4);

        $flags = compact('genericFork', 'hardFork', 'chainSplit', 'upgrade', 'entitlement', 'snapshot', 'airdrop', 'migration', 'emergency', 'codebase', 'negative', 'newAsset');
        $families = [];
        foreach ($flags as $family => $active) {
            if ($active) {
                $families[] = $family;
            }
        }

        return [
            'event_type' => $eventType,
            'confidence' => $confidence,
            'matched_symbols' => $matchedSymbols,
            'context_snippet' => $snippets[0] ?? null,
            'evidence' => [
                'families' => $families,
                'flags' => $flags,
                'contexts' => array_slice($snippets, 0, 12),
                'classification_source' => 'gkg_title',
            ],
        ];
    }
}
