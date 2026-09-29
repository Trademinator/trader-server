<?php

namespace App\Http\Controllers\Owner;

use App\Domain\Features\FeatureEngine;
use App\Domain\Intelligence\ModelStore;
use App\Domain\Operations\GeoLocation;
use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketFeature;
use App\Models\MarketFeed;
use App\Models\MarketSubscription;
use App\Models\Ticker;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(GeoLocation $geo): View
    {
        $users = User::query()->toBase()->selectRaw('COUNT(*) AS total, COUNT(email_verified_at) AS verified, COUNT(suspended_at) AS suspended')->first();
        $subscriptions = MarketSubscription::query()->where('active', true)->count();
        $feeds = MarketFeed::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->orderBy('status')->get();
        $overdue = MarketFeed::query()->whereHas('market.subscriptions', fn ($q) => $q->where('active', true))
            ->where('next_pull_at', '<', now()->subMinutes(15))->count();
        $models = DB::table('intelligence_models')->join('intelligence_heads', 'intelligence_heads.model_id', '=', 'intelligence_models.model_id')
            ->selectRaw('status, COUNT(*) AS total')->groupBy('status')->orderBy('status')->get();
        $queues = DB::table('jobs')->selectRaw('queue, COUNT(*) AS total, MIN(created_at) AS oldest')->groupBy('queue')->get();
        $failed = DB::table('failed_jobs')->select('uuid', 'connection', 'queue', 'failed_at')->orderByDesc('failed_at')->limit(10)->get();
        $queueDriver = config('queue.default');
        $traffic = DB::table('access_daily_stats')->where('day', now('UTC')->toDateString())
            ->selectRaw('COALESCE(SUM(requests), 0) AS total, COALESCE(SUM(CASE WHEN status_code >= 500 THEN requests ELSE 0 END), 0) AS errors')->first();
        $latestPull = MarketFeed::query()->max('last_pulled_at');
        $latestModel = DB::table('intelligence_models')->max('created_at');
        $geoStatus = $geo->status();

        return view('owner.overview', compact('users', 'subscriptions', 'feeds', 'overdue', 'models', 'queues', 'failed', 'queueDriver', 'traffic', 'latestPull', 'latestModel', 'geoStatus'));
    }

    public function subscriptions(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', Rule::in(['active', 'inactive'])], 'user' => ['nullable', 'uuid']]);
        $items = MarketSubscription::query()->with(['user:user_id,name,email', 'market.exchange', 'market.feed'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->whereHas('market', fn ($market) => $market
                ->where('symbol', 'like', '%'.$term.'%')->orWhereHas('exchange', fn ($exchange) => $exchange->where('class', 'like', '%'.$term.'%'))))
            ->when($filters['user'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->where('active', $state === 'active'))
            ->orderByDesc('created_at')->orderBy('market_subscription_id')->paginate(50)->withQueryString();
        $exchanges = DB::table('market_subscriptions')->join('markets', 'markets.market_id', '=', 'market_subscriptions.market_id')
            ->join('exchanges', 'exchanges.exchange_id', '=', 'markets.exchange_id')->where('active', true)
            ->selectRaw('exchanges.class, COUNT(*) AS subscriptions, COUNT(DISTINCT market_subscriptions.user_id) AS users, COUNT(DISTINCT markets.market_id) AS markets')
            ->groupBy('exchanges.class')->orderByDesc('subscriptions')->paginate(20, ['*'], 'exchange_page');

        return view('owner.subscriptions', compact('items', 'exchanges'));
    }

    public function market(Market $market): View
    {
        $market->load('exchange', 'feed');
        $subscriptions = $market->subscriptions()->with('user:user_id,name,email')->orderByDesc('active')
            ->orderBy('market_subscription_id')->paginate(25);
        $period = $market->feed?->selected_period;
        $candles = $features = 0;
        $head = null;
        if ($period !== null) {
            $candles = Ticker::query()->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)->where('period', $period)->count();
            $features = MarketFeature::query()->where('exchange', $market->exchange->class)->where('symbol', $market->symbol)->where('period', $period)
                ->where('version', FeatureEngine::VERSION)->count();
            $head = DB::table('intelligence_heads')->where('market_key', ModelStore::marketKey($market->exchange->class, $market->symbol, $period))->value('model_id');
        }
        $backfill = $period === null ? null : DB::table('market_history_backfills')
            ->where('market_id', $market->market_id)->where('period', $period)->first();

        return view('owner.market', compact('market', 'subscriptions', 'period', 'candles', 'features', 'head', 'backfill'));
    }

    public function intelligence(Request $request): View
    {
        $filters = $request->validate(['history' => ['nullable', 'boolean'], 'status' => ['nullable', 'string', 'max:32']]);
        $query = DB::table('intelligence_models as models')->leftJoin('intelligence_heads as heads', 'heads.model_id', '=', 'models.model_id')
            ->select('models.model_id', 'models.dataset_id', 'models.status', 'models.created_at', 'models.report', 'heads.model_id as current_id')
            ->when(! ($filters['history'] ?? false), fn ($q) => $q->whereNotNull('heads.model_id'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('models.status', $status));
        $models = $query->orderByDesc('models.created_at')->orderBy('models.model_id')->paginate(25)->withQueryString();
        $models->through(function ($row) {
            $row->summary = json_decode($row->report, true, flags: JSON_THROW_ON_ERROR);
            unset($row->report);

            return $row;
        });

        return view('owner.intelligence', compact('models'));
    }

    public function model(string $model, ModelStore $store): View
    {
        abort_unless(Str::isUuid($model) && DB::table('intelligence_models')->where('model_id', $model)->exists(), 404);
        $report = $store->report($model);

        return view('owner.model', compact('report', 'model'));
    }

    public function access(Request $request, GeoLocation $geo): View
    {
        $filters = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:366']]);
        $days = min((int) ($filters['days'] ?? 30), config('operations.retention_days'));
        $from = now('UTC')->subDays($days - 1)->toDateString();
        $base = DB::table('access_daily_stats')->where('day', '>=', $from)->where('day', '<=', now('UTC')->toDateString());
        $totals = (clone $base)->selectRaw('COALESCE(SUM(requests),0) AS requests, COALESCE(SUM(duration_ms),0) AS duration_ms,
            COALESCE(SUM(CASE WHEN authenticated = 1 THEN requests ELSE 0 END),0) AS authenticated,
            COALESCE(SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN requests ELSE 0 END),0) AS client_errors,
            COALESCE(SUM(CASE WHEN status_code >= 500 THEN requests ELSE 0 END),0) AS server_errors')->first();
        $daily = (clone $base)->selectRaw('day, SUM(requests) AS requests')->groupBy('day')->orderBy('day')->get();
        $countries = (clone $base)->selectRaw('country, SUM(requests) AS requests')->groupBy('country')->orderByDesc('requests')->get();
        $cities = (clone $base)->selectRaw('country, region, city, SUM(requests) AS requests')->groupBy('country', 'region', 'city')
            ->orderByDesc('requests')->orderBy('country')->orderBy('region')->orderBy('city')->paginate(25, ['*'], 'cities_page')->withQueryString();
        $routes = (clone $base)->selectRaw('route, method, SUM(requests) AS requests')->groupBy('route', 'method')
            ->orderByDesc('requests')->orderBy('route')->orderBy('method')->paginate(25, ['*'], 'routes_page')->withQueryString();
        $visitors = DB::table('access_daily_visitors')->where('day', '>=', $from)->where('day', '<=', now('UTC')->toDateString())->count();
        $geoStatus = $geo->status();

        return view('owner.access', compact('days', 'totals', 'daily', 'countries', 'cities', 'routes', 'visitors', 'geoStatus'));
    }
}
