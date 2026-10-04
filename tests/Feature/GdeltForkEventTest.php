<?php

use App\Models\Exchange;
use App\Models\Market;
use App\Models\MarketEventCandidate;
use App\Models\MarketSubscription;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'gdelt.enabled' => true,
        'gdelt.http_attempts' => 1,
        'gdelt.minimum_confidence' => 0.45,
        'gdelt.processed_cache_key' => 'test:gdelt:last-processed',
    ]);
    Cache::forget('test:gdelt:last-processed');
});

function gdeltGkgZip(array $rows): string
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gdelt-test-'.bin2hex(random_bytes(6)).'.zip';
    $archive = new \PharData($path);
    $archive->addFromString('20261004024500.gkg.csv', implode("\n", $rows)."\n");
    unset($archive);
    $body = file_get_contents($path);
    @unlink($path);

    if (! is_string($body)) {
        throw new RuntimeException('Unable to create GDELT test archive.');
    }

    return $body;
}

function gdeltGkgRow(string $url, string $title, string $themes = 'ECON_BITCOIN'): string
{
    $columns = array_fill(0, 27, '');
    $columns[0] = '20261004024500-1';
    $columns[1] = '20261004024500';
    $columns[2] = '1';
    $columns[3] = 'news.example';
    $columns[4] = $url;
    $columns[8] = $themes;
    $columns[25] = 'srclc:eng;';
    $columns[26] = '<PAGE_TITLE>'.htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</PAGE_TITLE>';

    return implode("\t", $columns);
}

function fakeLatestGkg(string $zip, ?int &$downloads = null): void
{
    $downloads = 0;
    $size = strlen($zip);
    $md5 = md5($zip);
    Http::fake(function (Request $request) use ($zip, $size, $md5, &$downloads) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/gdeltv2/lastupdate.txt') {
            return Http::response("100 aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa http://data.gdeltproject.org/gdeltv2/x.export.CSV.zip\n"
                ."100 bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb http://data.gdeltproject.org/gdeltv2/x.mentions.CSV.zip\n"
                .$size.' '.$md5." http://data.gdeltproject.org/gdeltv2/20261004024500.gkg.csv.zip\n", 200,
                ['Content-Type' => 'text/plain']);
        }
        if ($path === '/gdeltv2/20261004024500.gkg.csv.zip') {
            $downloads++;
            return Http::response($zip, 200, ['Content-Type' => 'application/zip']);
        }

        return Http::response([], 404);
    });
}

it('discovers classifies and deduplicates GDELT GKG fork candidates', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Test', 'class' => 'test', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'BTC/CAD', 'tick_size' => '0.01']);
    MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);

    $source = 'https://news.example/btc-split';
    $zip = gdeltGkgZip([gdeltGkgRow($source, 'BTC chain split creates NEWBTC for holders')]);
    fakeLatestGkg($zip, $downloads);

    $this->artisan('trademinator:collect-market-events')->assertSuccessful();
    $this->artisan('trademinator:collect-market-events')->assertSuccessful();

    expect(MarketEventCandidate::query()->count())->toBe(1);
    $candidate = MarketEventCandidate::query()->firstOrFail();
    expect($candidate->event_type)->toBe('chain_split')
        ->and($candidate->matched_symbols)->toContain('BTC')
        ->and($candidate->owner_decision)->toBeNull()
        ->and((float) $candidate->machine_confidence)->toBeGreaterThanOrEqual(0.60)
        ->and($candidate->machine_evidence['classification_source'])->toBe('gkg_title')
        ->and($candidate->machine_evidence['gkg_batch'])->toBe('20261004024500.gkg.csv.zip');

    expect($downloads)->toBe(1);
});

it('keeps an ambiguous hard fork as unknown for owner investigation', function () {
    $user = User::factory()->create();
    $exchange = Exchange::query()->create(['name' => 'Test', 'class' => 'test', 'config' => '{}']);
    $market = Market::query()->create(['exchange_id' => $exchange->exchange_id, 'symbol' => 'ADA/CAD', 'tick_size' => '0.0001']);
    MarketSubscription::query()->create(['user_id' => $user->user_id, 'market_id' => $market->market_id, 'active' => true]);

    fakeLatestGkg(gdeltGkgZip([gdeltGkgRow('https://news.example/ada-fork', 'ADA hard fork scheduled for October')]));
    $this->artisan('trademinator:collect-market-events')->assertSuccessful();

    $candidate = MarketEventCandidate::query()->firstOrFail();
    expect($candidate->event_type)->toBe('unknown')
        ->and($candidate->matched_symbols)->toContain('ADA')
        ->and((float) $candidate->machine_confidence)->toBeGreaterThanOrEqual(0.45);
});

it('rejects a GDELT GKG archive that fails lastupdate integrity metadata', function () {
    $zip = gdeltGkgZip([gdeltGkgRow('https://news.example/btc-fork', 'BTC hard fork announced')]);
    Http::fake(function (Request $request) use ($zip) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/gdeltv2/lastupdate.txt') {
            return Http::response(strlen($zip).' '.str_repeat('0', 32).' http://data.gdeltproject.org/gdeltv2/20261004024500.gkg.csv.zip');
        }
        if (str_ends_with($path, '.gkg.csv.zip')) {
            return Http::response($zip, 200, ['Content-Type' => 'application/zip']);
        }

        return Http::response([], 404);
    });

    $this->artisan('trademinator:collect-market-events')
        ->expectsOutputToContain('GDELT event discovery failed:')
        ->assertFailed();
    expect(MarketEventCandidate::query()->count())->toBe(0);
});

it('fails cleanly when GDELT lastupdate is unavailable', function () {
    Http::fake(['data.gdeltproject.org/gdeltv2/lastupdate.txt' => Http::response('temporary', 503)]);

    $this->artisan('trademinator:collect-market-events')
        ->expectsOutputToContain('GDELT event discovery failed:')
        ->assertFailed();
});
