<?php

namespace App\Domain\MarketEvents;

use App\Models\MarketEventCandidate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class GdeltMarketEventCollector
{
    public function __construct(private GdeltClient $client, private GdeltForkClassifier $classifier) {}

    public function collect(bool $force = false): int
    {
        if (! config('gdelt.enabled')) {
            return 0;
        }

        $batch = $this->client->latestGkg();
        $cacheKey = (string) config('gdelt.processed_cache_key', 'trademinator:gdelt:last-processed-gkg');
        if (! $force && hash_equals((string) Cache::get($cacheKey, ''), $batch['md5'])) {
            return 0;
        }

        $articles = $this->client->candidateArticles($batch);
        $symbols = $this->activeBaseSymbols();
        $stored = 0;

        foreach ($articles as $article) {
            $url = trim((string) ($article['url'] ?? ''));
            $title = trim((string) ($article['title'] ?? ''));
            if ($url === '' || $title === '' || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                continue;
            }

            $classification = $this->classifier->classify($title, [], $symbols);
            if ($classification['confidence'] < (float) config('gdelt.minimum_confidence', 0.45)) {
                continue;
            }

            $candidate = MarketEventCandidate::query()->firstOrNew(['source_hash' => hash('sha256', $url)]);
            $candidate->fill([
                'provider' => 'gdelt',
                'source_url' => $url,
                'source_domain' => $this->nullableString($article['domain'] ?? null),
                'source_title' => $title,
                'source_seen_at' => $this->seenAt($article['seendate'] ?? null),
                'source_language' => $this->nullableString($article['language'] ?? null),
                'source_country' => null,
                'context_snippet' => null,
                'event_type' => $classification['event_type'],
                'matched_symbols' => $classification['matched_symbols'],
                'machine_confidence' => $classification['confidence'],
                'machine_evidence' => [
                    ...$classification['evidence'],
                    'provider' => 'gdelt',
                    'gkg_batch' => $batch['file'],
                    'gkg_md5' => $batch['md5'],
                    'gkg_record_id' => $this->nullableString($article['gkg_record_id'] ?? null),
                    'gkg_themes' => $this->nullableString($article['gkg_themes'] ?? null),
                    'retrieved_at' => now('UTC')->toIso8601String(),
                ],
            ])->save();
            $stored++;
        }

        Cache::forever($cacheKey, $batch['md5']);

        return $stored;
    }

    /** @return list<string> */
    private function activeBaseSymbols(): array
    {
        return DB::table('market_subscriptions as subscriptions')
            ->join('markets', 'markets.market_id', '=', 'subscriptions.market_id')
            ->where('subscriptions.active', true)
            ->distinct()->pluck('markets.symbol')
            ->map(static fn (string $symbol): string => strtoupper(Str::before($symbol, '/')))
            ->filter(static fn (string $symbol): bool => $symbol !== '')
            ->unique()->values()->all();
    }

    private function seenAt(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        foreach (['YmdHis', 'Ymd\\THis\\Z'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat($format, $value, 'UTC');
                if ($parsed !== false) {
                    return $parsed;
                }
            } catch (Throwable) {
                // Try the next known GDELT date format.
            }
        }
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
