<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Domain\MarketSuggestions\Questionnaire;
use App\Models\Exchange;
use App\Models\MarketPreferenceProfile;
use App\Models\MarketSubscription;
use App\Models\User;
use App\Repositories\ExchangeRepository;
use Database\Seeders\ExchangeSeeder;
use Tests\Support\FixtureBinance;
use Tests\TestCase;

$probe = new class('testProbe') extends TestCase
{
    public function test_probe(): void {}

    public function runProbe(): void
    {
        $this->setUp(); // Mandatory in-memory guard; never use a deployment database.
        $path = tempnam(sys_get_temp_dir(), 'binance-catalogue-');
        try {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
            $this->seed(ExchangeSeeder::class);
            $user = User::factory()->create();
            $this->actingAs($user)->get(route('markets.index'))->assertOk()
                ->assertSee('<option value="binance"', false)
                ->assertDontSee('<option value="apex"', false);
            $listPeak = memory_get_peak_usage(true);
            FixtureBinance::writeResponse($path, 5000);
            FixtureBinance::$responsePath = $path;
            $exchange = Exchange::query()->where('class', 'binance')->firstOrFail();
            $exchange->update(['config' => json_encode([
                'apiKey' => 'fixture-key', 'secret' => 'fixture-secret',
                'options' => ['fetchMarkets' => ['types' => ['spot', 'linear', 'inverse']], 'loadAllOptions' => true],
            ])]);
            $originalConfig = $exchange->config;
            $repository = new class extends ExchangeRepository
            {
                public function setExchange(Exchange $exchange, array $extraSettings = [])
                {
                    $this->exchange = $exchange;
                    $this->ccxtExchange = new FixtureBinance(json_decode($exchange->config, true));
                }
            };
            app()->instance(ExchangeRepository::class, $repository);
            $this->getJson(route('markets.options', 'binance'))->assertOk()
                ->assertJsonCount(5000, 'symbols')->assertJsonPath('symbols.0.value', 'BTC/USDT')
                ->assertJsonPath('symbols.0.tick_size', '0.01');
            $this->post(route('markets.store'), ['exchange' => 'binance', 'symbol' => 'BTC/USDT'])
                ->assertRedirect(route('markets.index'));
            $this->assertSame(1, MarketSubscription::query()->count());
            $this->assertSame(1, FixtureBinance::$requests);
            $this->assertSame($originalConfig, $exchange->fresh()->config);
            $client = (new ReflectionProperty(ExchangeRepository::class, 'ccxtExchange'))->getValue($repository);
            $this->assertEmpty($client->markets);
            $this->assertNull($client->last_json_response);
            $this->assertNull($client->last_http_response);
            MarketPreferenceProfile::query()->create(['user_id' => $user->user_id, 'answers' => array_replace(Questionnaire::defaults(), [
                'country' => 'CA', 'region' => 'ON', 'exchange' => 'binance', 'holdings' => [['asset' => 'USDT', 'band' => '100_500']],
            ])]);
            // Missing regional/history evidence stays exploratory; no claim of Ontario eligibility.
            $this->get(route('markets.suggestions', ['show' => 1]))->assertOk()->assertSee('Explore only');
            $this->assertSame(1, MarketSubscription::query()->count());
            $this->assertSame(1, FixtureBinance::$requests);
            echo json_encode(['exchanges' => Exchange::query()->count(), 'pairs' => 5000,
                'list_peak_bytes' => $listPeak, 'peak_bytes' => memory_get_peak_usage(true),
                'memory_limit' => ini_get('memory_limit'), 'requests' => FixtureBinance::$requests], JSON_THROW_ON_ERROR);
        } finally {
            unlink($path);
            // This standalone probe exits here; PHP releases its in-memory DB.
        }
    }
};
$probe->runProbe();
