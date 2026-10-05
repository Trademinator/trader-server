<x-owner.layout title="Access statistics">
    @if($geoStatus['status'] !== 'ready')
        <div class="owner-notice owner-warning">GeoIP database: {{ $geoStatus['status'] }}. Set <code>GEOIP_DATABASE_PATH</code> to a readable GeoLite2 City or GeoIP2 City MMDB file. Requests remain counted with an Unknown location until it is available.</div>
    @endif
    @unless(config('operations.access_enabled'))<div class="owner-notice owner-warning">Access recording is disabled. These are previously collected statistics.</div>@endunless
    <section class="owner-panel">
        <form class="owner-filters" method="GET" action="{{ route('owner.access') }}"><label>Days to report<input type="number" name="days" min="1" max="366" value="{{ $days }}"></label><button>Update report</button></form>
        <p class="owner-muted">Last {{ $days }} UTC calendar days, including today. Counts are grouped by UTC day; the day windows below use your selected display timezone. Counts cover application requests, including bots; static assets and health checks are excluded. GeoIP locations are approximate. Database built: <x-display-time :value="$geoStatus['built_at']" fallback="Unavailable" />.</p>
    </section>
    <div class="owner-grid">
        <div class="owner-stat"><span>Requests</span><strong>{{ \App\Helpers\Decimal::format($totals->requests) }}</strong><span>{{ \App\Helpers\Decimal::format($totals->authenticated) }} authenticated</span></div>
        <div class="owner-stat"><span>Daily IP visitors, summed</span><strong>{{ \App\Helpers\Decimal::format($visitors) }}</strong><span>One IP per day; shared networks and VPNs affect this count</span></div>
        <div class="owner-stat"><span>Average response time</span><strong>{{ $totals->requests ? \App\Helpers\Decimal::format($totals->duration_ms / $totals->requests) : 0 }} ms</strong></div>
        <div class="owner-stat"><span>Errors</span><strong>{{ \App\Helpers\Decimal::format($totals->server_errors) }}</strong><span>5xx server errors · {{ \App\Helpers\Decimal::format($totals->client_errors) }} 4xx responses</span></div>
    </div>
    <section class="owner-panel"><h2>Requests by country</h2><div class="owner-scroll"><table class="owner-table"><thead><tr><th>Country</th><th>Requests</th><th>Share</th></tr></thead><tbody>
        @forelse($countries as $country)<tr><td>{{ $country->country === 'ZZ' ? 'Unknown' : Locale::getDisplayRegion('_'.$country->country, 'en').' ('.$country->country.')' }}</td><td>{{ \App\Helpers\Decimal::format($country->requests) }}</td><td><progress aria-label="Requests from {{ $country->country }}" max="{{ max(1, $totals->requests) }}" value="{{ $country->requests }}"></progress> {{ $totals->requests ? \App\Helpers\Decimal::format(100 * $country->requests / $totals->requests, 1) : 0 }}%</td></tr>@empty<tr><td colspan="3">No recorded requests in this period.</td></tr>@endforelse
    </tbody></table></div></section>
    <section class="owner-panel"><h2>Requests by city</h2><div class="owner-scroll"><table class="owner-table"><thead><tr><th>Country</th><th>Province / region</th><th>City</th><th>Requests</th></tr></thead><tbody>
        @forelse($cities as $city)<tr><td>{{ $city->country === 'ZZ' ? 'Unknown' : $city->country }}</td><td>{{ $city->region ?: 'Unknown' }}</td><td>{{ $city->city }}</td><td>{{ \App\Helpers\Decimal::format($city->requests) }}</td></tr>@empty<tr><td colspan="4">No geographic statistics yet.</td></tr>@endforelse
    </tbody></table></div>{{ $cities->links() }}</section>
    <section class="owner-panel"><h2>Requests by route</h2><div class="owner-scroll"><table class="owner-table"><thead><tr><th>Route</th><th>Method</th><th>Requests</th></tr></thead><tbody>
        @forelse($routes as $route)<tr><td><code>{{ $route->route }}</code></td><td>{{ $route->method }}</td><td>{{ \App\Helpers\Decimal::format($route->requests) }}</td></tr>@empty<tr><td colspan="3">No route statistics yet.</td></tr>@endforelse
    </tbody></table></div>{{ $routes->links() }}</section>
    <section class="owner-panel"><h2>Daily requests</h2><div class="owner-scroll"><table class="owner-table"><thead><tr><th>Day window (<x-timezone-label />)</th><th>Requests</th><th>Relative volume</th></tr></thead><tbody>
        @forelse($daily as $day)<tr><td><x-display-time :value="$day->day" precision="minutes" /> → <x-display-time :value="\Carbon\CarbonImmutable::parse($day->day, 'UTC')->addDay()" precision="minutes" /></td><td>{{ \App\Helpers\Decimal::format($day->requests) }}</td><td><progress aria-label="Requests on {{ $day->day }}" max="{{ max(1, $daily->max('requests')) }}" value="{{ $day->requests }}"></progress></td></tr>@empty<tr><td colspan="3">No recorded activity.</td></tr>@endforelse
    </tbody></table></div></section>
    <p class="owner-muted">Raw IP addresses, request bodies, query strings and browser identifiers are not stored in these reports. Daily visitor hashes expire with the configured retention period.</p>
</x-owner.layout>
