<?php

namespace App\Domain\MarketEvents;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use PharData;
use RuntimeException;
use Throwable;

final class GdeltClient
{
    /** @return array{url:string,file:string,size:int,md5:string} */
    public function latestGkg(): array
    {
        $response = $this->request()->get((string) config('gdelt.lastupdate_url'))->throw();
        $lines = preg_split('/\R/u', trim($response->body())) ?: [];

        foreach ($lines as $line) {
            if (! preg_match('/^(\d+)\s+([a-f0-9]{32})\s+(https?:\/\/\S+\.gkg\.csv\.zip)$/i', trim($line), $matches)) {
                continue;
            }

            $size = (int) $matches[1];
            if ($size < 1 || $size > (int) config('gdelt.max_archive_bytes', 16_777_216)) {
                throw new RuntimeException('GDELT GKG archive size is outside the configured safety limit.');
            }

            $url = $this->safeDataUrl($matches[3]);

            return [
                'url' => $url,
                'file' => basename((string) parse_url($url, PHP_URL_PATH)),
                'size' => $size,
                'md5' => strtolower($matches[2]),
            ];
        }

        throw new RuntimeException('GDELT lastupdate.txt did not contain a GKG archive entry.');
    }

    /**
     * @param array{url:string,file:string,size:int,md5:string} $batch
     * @return list<array<string, mixed>>
     */
    public function candidateArticles(array $batch): array
    {
        $response = $this->request(download: true)->get($batch['url'])->throw();
        $archive = $response->body();
        if (strlen($archive) !== $batch['size']) {
            throw new RuntimeException('Downloaded GDELT GKG archive size does not match lastupdate.txt.');
        }
        if (! hash_equals($batch['md5'], md5($archive))) {
            throw new RuntimeException('Downloaded GDELT GKG archive failed its MD5 verification.');
        }

        return $this->readCandidates($archive);
    }

    /** @return list<array<string, mixed>> */
    private function readCandidates(string $archive): array
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'trademinator-gdelt-'.bin2hex(random_bytes(8)).'.zip';
        if (file_put_contents($tmp, $archive, LOCK_EX) !== strlen($archive)) {
            throw new RuntimeException('Unable to stage the GDELT GKG archive.');
        }

        try {
            try {
                $zip = new PharData($tmp);
            } catch (Throwable $error) {
                throw new RuntimeException('GDELT returned an unreadable GKG ZIP archive.', previous: $error);
            }

            $entry = null;
            foreach ($zip as $file) {
                if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.gkg.csv')) {
                    $entry = $file;
                    break;
                }
            }
            if ($entry === null) {
                throw new RuntimeException('GDELT GKG ZIP archive does not contain a .gkg.csv file.');
            }
            if ($entry->getSize() > (int) config('gdelt.max_gkg_bytes', 134_217_728)) {
                throw new RuntimeException('GDELT GKG file exceeds the configured uncompressed safety limit.');
            }

            $articles = [];
            $stream = $entry->openFile('r');
            while (! $stream->eof()) {
                $line = $stream->fgets();
                if (! is_string($line) || trim($line) === '') {
                    continue;
                }
                $article = $this->parseRecord($line);
                if ($article === null) {
                    continue;
                }
                $articles[hash('sha256', $article['url'])] = $article;
            }

            return array_values($articles);
        } finally {
            @unlink($tmp);
        }
    }

    /** @return array<string, mixed>|null */
    private function parseRecord(string $line): ?array
    {
        $columns = explode("\t", rtrim($line, "\r\n"));
        if (count($columns) < 27) {
            return null;
        }

        $url = trim($columns[4] ?? '');
        $title = $this->pageTitle($columns[26] ?? '');
        if ($title === null || $url === '' || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return null;
        }

        $themes = trim($columns[8] ?? '');
        if (! $this->looksLikeMarketEvent($title, $themes)) {
            return null;
        }

        $domain = trim($columns[3] ?? '');
        $language = null;
        if (preg_match('/(?:^|;)srclc:([a-z]{2,3})(?:;|$)/i', (string) ($columns[25] ?? ''), $match)) {
            $language = strtolower($match[1]);
        }

        return [
            'url' => $url,
            'title' => $title,
            'domain' => $domain !== '' ? $domain : parse_url($url, PHP_URL_HOST),
            'seendate' => trim($columns[1] ?? ''),
            'language' => $language,
            'sourcecountry' => null,
            'gkg_record_id' => trim($columns[0] ?? ''),
            'gkg_themes' => $themes,
        ];
    }

    private function pageTitle(string $extras): ?string
    {
        if (! preg_match('/<PAGE_TITLE>(.*?)<\/PAGE_TITLE>/su', $extras, $match)) {
            return null;
        }

        $title = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $title === '' ? null : mb_substr($title, 0, 1000);
    }

    private function looksLikeMarketEvent(string $title, string $themes): bool
    {
        $title = mb_strtolower($title);
        $context = $title.' '.mb_strtolower($themes);

        foreach (['hard fork', 'chain split', 'blockchain split', 'network fork', 'emergency fork',
            'token migration', 'token swap', 'mainnet migration', 'codebase fork', 'forked from bitcoin', 'fork of bitcoin'] as $phrase) {
            if (str_contains($title, $phrase)) {
                return true;
            }
        }
        if (preg_match('/\bforks?\b|\bforked\b|\bforking\b/u', $title) === 1) {
            return true;
        }

        $conditional = collect(['snapshot', 'airdrop', 'holders will receive', 'holders receive',
            'network upgrade', 'protocol upgrade', 'consensus upgrade', 'activation block', 'activation height'])
            ->contains(static fn (string $phrase): bool => str_contains($title, $phrase));
        if (! $conditional) {
            return false;
        }

        return preg_match('/\b(bitcoin|ethereum|crypto|cryptocurrency|blockchain|token|coin|mainnet)\b/u', $context) === 1
            || str_contains($context, 'econ_bitcoin') || str_contains($context, 'cryptocurr');
    }

    private function safeDataUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($host !== 'data.gdeltproject.org' || ! str_starts_with($path, '/gdeltv2/') || ! str_ends_with(strtolower($path), '.gkg.csv.zip')) {
            throw new RuntimeException('GDELT lastupdate.txt returned an unexpected archive URL.');
        }

        return 'https://data.gdeltproject.org'.$path;
    }

    private function request(bool $download = false): PendingRequest
    {
        return Http::withUserAgent('Trademinator/1.0 (+https://github.com/Trademinator/trader-server)')
            ->connectTimeout((int) config('gdelt.connect_timeout_seconds', 5))
            ->timeout((int) config($download ? 'gdelt.download_timeout_seconds' : 'gdelt.timeout_seconds', $download ? 60 : 30))
            ->retry(
                (int) config('gdelt.http_attempts', 3),
                (int) config('gdelt.retry_delay_ms', 1000),
                throw: false,
            );
    }
}
