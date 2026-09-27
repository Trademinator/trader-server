<x-layouts.app :title="__('Help me choose pairs')">
    <style>
        .pair-guide { max-width:1120px; margin:0 auto; color:#172033; line-height:1.55; }
        .dark .pair-guide { color:#f1f5f9; }
        .pair-guide a { color:#0756b9; text-decoration:underline; text-underline-offset:3px; }
        .dark .pair-guide a { color:#93c5fd; }
        .pair-guide h1 { font-size:1.8rem; font-weight:750; }
        .pair-guide h2 { font-size:1.2rem; font-weight:750; margin-bottom:10px; }
        .pair-guide h3, .pair-guide legend { font-size:1.05rem; font-weight:700; }
        .pair-guide p { margin:8px 0; }
        .pair-guide .guide-hero { background:#0a438e; color:white; padding:24px; border-radius:16px; margin:18px 0; }
        .pair-guide .guide-panel { border:1px solid #aebbc9; padding:22px; border-radius:14px; margin:18px 0; background:#fff; }
        .dark .pair-guide .guide-panel { background:#111827; border-color:#64748b; }
        .pair-guide .guide-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; margin:14px 0; }
        .pair-guide label { display:block; font-weight:650; }
        .pair-guide input:not([type=checkbox]), .pair-guide select { display:block; width:100%; margin-top:6px; min-height:44px; padding:9px 10px; border:2px solid #94a3b8; border-radius:8px; background:white; color:#172033; }
        .pair-guide input[type=checkbox] { width:18px; height:18px; margin:3px 8px 0 0; flex:none; }
        .pair-guide .guide-check { display:flex; align-items:flex-start; margin:14px 0; }
        .pair-guide .guide-help { font-size:.9rem; color:#40536c; font-weight:400; }
        .dark .pair-guide .guide-help { color:#cbd5e1; }
        .pair-guide .guide-button { display:inline-block; padding:11px 18px; border-radius:8px; background:#0756b9; color:#fff; text-decoration:none; font-weight:700; }
        .dark .pair-guide a.guide-button { color:#fff; }
        .pair-guide .guide-delete { padding:10px 16px; border:2px solid #b42318; border-radius:8px; color:#b42318; font-weight:650; }
        .dark .pair-guide .guide-delete { color:#fda4af; border-color:#fda4af; }
        .pair-guide :focus-visible { outline:3px solid #e8a800; outline-offset:3px; }
        .pair-guide .guide-notice { padding:14px; border-radius:9px; background:#e6f0ff; color:#163e70; margin:14px 0; }
        .pair-guide .guide-error { background:#fce7e7; color:#8d1919; }
        .pair-guide .guide-badge { display:inline-block; border-radius:6px; padding:3px 9px; background:#fff0cd; color:#644000; font-size:.85rem; font-weight:700; }
        .pair-guide .guide-badge.is-screened { background:#d1f3e1; color:#125437; }
        .pair-guide ul { list-style:disc; padding-left:22px; margin:8px 0 16px; }
        .pair-guide li { margin:5px 0; }
        .pair-guide summary { cursor:pointer; font-weight:700; padding:10px 0; }
        .pair-guide fieldset { min-width:0; }
        .pair-guide .guide-inline { display:flex; flex-wrap:wrap; gap:12px; align-items:center; }
        @media(max-width:650px) { .pair-guide .guide-grid { grid-template-columns:1fr; } .pair-guide .guide-panel { padding:16px; } }
    </style>
    <section class="pair-guide">
        <a href="{{ route('markets.index') }}">← Your markets</a>
        <header class="guide-hero">
            <h1>Help me choose pairs</h1>
            <p>Tell us what matters to you. Compare a few spot pairs, understand why they appear, and make your own choice.</p>
            <p>These are markets to follow. Subscribing collects price data; it does not place a trade or promise a profit.</p>
        </header>
        @if (session('status'))<p role="status" class="guide-notice">{{ session('status') }}</p>@endif
        @if ($failure)<p role="alert" class="guide-notice guide-error">{{ $failure }}</p>@endif
        @if ($errors->any())
            <div role="alert" class="guide-notice guide-error"><strong>Please check your answers.</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if ($results !== null)
            <section id="pair-results" aria-labelledby="results-heading" class="guide-panel">
                <h2 id="results-heading">Pairs that fit your preferences</h2>
                <p>{{ collect($exchanges)->firstWhere('value', $answers['exchange'])['label'] ?? $answers['exchange'] }} · {{ $results['generated_at'] }}</p>
                <p class="guide-notice">{{ $results['access']['message'] }}</p>
                @if ($results['access']['source'])<a href="{{ $results['access']['source'] }}" target="_blank" rel="noopener noreferrer">Regional review source</a>@endif
                <p class="guide-help">Ranked by funding and goal match, then historical screening. “Explore only” means important evidence or answers are missing. Neither label is a buy/sell signal. Account fees, order-book depth and execution costs are not verified.</p>
                @forelse ($results['items'] as $item)
                    <article class="guide-panel" aria-label="{{ $item['symbol'] }} suggestion">
                        <div class="guide-inline"><h3>{{ $item['symbol'] }}</h3><span class="guide-badge {{ $item['explore'] ? '' : 'is-screened' }}">{{ $item['explore'] ? 'Explore only' : 'Matches preference screens' }}</span></div>
                        <p><strong>Why it appears</strong></p>
                        <ul>@foreach ($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                        @if ($item['evidence']['known'])
                            <p><strong>Historical evidence:</strong> {{ $item['evidence']['candles'] }} completed {{ $item['evidence']['period'] }} candles, {{ $item['evidence']['from'] }}–{{ $item['evidence']['through'] }} UTC, measured in {{ $item['risk_currency'] }}.</p>
                            <p>Largest close-to-close decline from a prior peak: {{ number_format($item['evidence']['drawdown'] * 100, 1) }}%. Largest absolute move over the chosen holding window: {{ number_format($item['evidence']['largest_move'] * 100, 1) }}%.</p>
                            <p class="guide-help">These describe this sample only. They omit extremes between closes and do not limit future losses. Candle volume is not a measure of order-book depth.</p>
                        @endif
                        <details><summary>What to check before trading</summary><ul>@foreach ($item['cautions'] as $caution)<li>{{ $caution }}</li>@endforeach</ul></details>
                        @if ($item['subscribed'])
                            <p><strong>Already subscribed</strong> · Your existing subscription is unchanged.</p>
                        @else
                            <a class="guide-button" href="{{ route('markets.index', ['exchange' => $answers['exchange'], 'symbol' => $item['symbol']]) }}">Review {{ $item['symbol'] }}</a>
                            <p class="guide-help">Review opens the market form. You must press Subscribe there to confirm.</p>
                        @endif
                    </article>
                @empty
                    <p class="guide-notice">No suitable matches were found with these answers and the available evidence. No subscription was created.</p>
                @endforelse
                @foreach ($results['notes'] as $note)<p class="guide-help">{{ $note }}</p>@endforeach
                @if ($results['excluded'])<details><summary>Why other pairs were left out</summary><ul>@foreach ($results['excluded'] as $reason => $count)<li>{{ $reason }}: {{ $count }} {{ $count === 1 ? 'pair' : 'pairs' }}.</li>@endforeach</ul></details>@endif
                <p class="guide-help">{{ $results['catalogue_count'] }} catalogue pairs; {{ $results['considered'] }} candidates received detailed checks. History comes from data already stored by this server. You can subscribe to an exploratory pair to begin collection, then revisit these suggestions.</p>
            </section>
        @endif
        <form method="POST" action="{{ route('markets.preferences.store') }}" class="guide-panel">
            @csrf @method('PUT')
            <h2>{{ $hasProfile ? 'Your preferences' : 'Start with your situation' }}</h2>
            <p class="guide-help">This feature is optional. We save these answers privately to your account so you can return and refine them. Approximate ranges are enough. No API keys, wallet addresses or identity documents are requested.</p>
            <div class="guide-grid">
                <label>Country of residence<select name="country" required><option value="">Choose your country</option>@foreach ($countries as $code => $name)<option value="{{ $code }}" @selected(old('country', $answers['country']) === $code)>{{ $name }}</option>@endforeach</select></label>
                <label>Province / state<input name="region" maxlength="60" list="guide-provinces" value="{{ old('region', $answers['region']) }}" placeholder="For example, ON"><span class="guide-help">Required for Canada. Choose your two-letter province code.</span><datalist id="guide-provinces">@foreach ($provinces as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</datalist></label>
                <label>Exchange you already use<select name="exchange" required><option value="">Choose one exchange</option>@foreach ($exchanges as $exchange)<option value="{{ $exchange['value'] }}" @selected(old('exchange', $answers['exchange']) === $exchange['value'])>{{ $exchange['label'] }}</option>@endforeach</select><span class="guide-help">Compare one exchange at a time. No account opening or transfers are assumed.</span></label>
                <label>Currency for your approximate values<input name="reference_currency" required maxlength="16" value="{{ old('reference_currency', $answers['reference_currency']) }}" placeholder="CAD, USD or BTC" autocapitalize="characters"><span class="guide-help">All value ranges below use this unit. USD and USDT are different assets.</span></label>
            </div>
            <input type="hidden" name="access_confirmed" value="0">
            <label class="guide-check"><input type="checkbox" name="access_confirmed" value="1" @checked(old('access_confirmed', $answers['access_confirmed']))>I have checked that I can use this exchange from my country and province/state. Individual pair availability still needs checking.</label>
            <fieldset>
                <legend>What do you hold on this exchange?</legend>
                <p class="guide-help">Include fiat or crypto, up to five assets. Leave blank if you are just exploring. Enter each asset once; values are approximate in your reference currency.</p>
                @for ($i = 0; $i < 5; $i++)
                    <div class="guide-grid">
                        <label>Asset {{ $i + 1 }}<input name="holdings[{{ $i }}][asset]" maxlength="16" placeholder="For example, CAD or BTC" value="{{ old('holdings.'.$i.'.asset', $answers['holdings'][$i]['asset'] ?? '') }}" autocapitalize="characters"></label>
                        <label>Approximate value<select name="holdings[{{ $i }}][band]">@foreach ($bands as $key => $band)<option value="{{ $key }}" @selected(old('holdings.'.$i.'.band', $answers['holdings'][$i]['band'] ?? 'unsure') === $key)>{{ $band[0] }}</option>@endforeach</select></label>
                    </div>
                @endfor
            </fieldset>
            <div class="guide-grid">
                <label>Amount you would consider allocating to spot trading<select name="allocation">@foreach ($bands as $key => $band)<option value="{{ $key }}" @selected(old('allocation', $answers['allocation']) === $key)>{{ $band[0] }}</option>@endforeach</select><span class="guide-help">This does not reserve money or set an order size.</span></label>
                <label>What is your main goal?<select name="goal">@foreach ($choices['goal'] as $key => $label)<option value="{{ $key }}" @selected(old('goal', $answers['goal']) === $key)>{{ $label }}</option>@endforeach</select></label>
                <label>Asset you want to accumulate, if applicable<input name="target_asset" maxlength="16" value="{{ old('target_asset', $answers['target_asset']) }}" placeholder="For example, BTC" autocapitalize="characters"></label>
                <label>How comfortable are you with losses?<select name="risk">@foreach ($choices['risk'] as $key => $label)<option value="{{ $key }}" @selected(old('risk', $answers['risk']) === $key)>{{ $label }}</option>@endforeach</select><span class="guide-help">These preferences are not guaranteed loss limits.</span></label>
            </div>
            <details @if ($errors->any()) open @endif>
                <summary>Refine your suggestions — time, experience and preferences</summary>
                <p class="guide-help">You can keep “Not sure” where offered; the results will explain what remains unknown.</p>
                <div class="guide-grid">
                    @foreach (['loss_impact' => 'Could losing this allocation affect essential expenses?', 'money_needed' => 'When might you need this money?', 'horizon' => 'How long would you normally hold a trade?', 'experience' => 'How much spot-trading experience do you have?', 'monitoring' => 'How often could you check your trades?', 'conversions' => 'Are you willing to convert assets?'] as $field => $question)
                        <label>{{ $question }}<select name="{{ $field }}">@foreach ($choices[$field] as $key => $label)<option value="{{ $key }}" @selected(old($field, $answers[$field]) === $key)>{{ $label }}</option>@endforeach</select></label>
                    @endforeach
                </div>
                <p class="guide-help">Your holding horizon helps assess price history. The shared feed’s candle period is still selected automatically.</p>
                <label>Assets to exclude<input name="excluded_assets" maxlength="200" value="{{ old('excluded_assets', $answers['excluded_assets']) }}" placeholder="Comma-separated asset symbols"></label>
                <input type="hidden" name="exclude_stablecoins" value="0">
                <label class="guide-check"><input type="checkbox" name="exclude_stablecoins" value="1" @checked(old('exclude_stablecoins', $answers['exclude_stablecoins']))>Exclude known stablecoins. The list is maintained by the site and may not include every stablecoin.</label>
            </details>
            <button type="submit" class="guide-button">Save preferences and find pairs</button>
            <p class="guide-help">Saving does not change your subscriptions. No pair is selected for you.</p>
        </form>
        @if ($hasProfile)
            <form method="POST" action="{{ route('markets.preferences.destroy') }}" class="guide-panel">
                @csrf @method('DELETE')
                <h2>Remove your answers</h2><p>Delete the saved questionnaire at any time. Your market subscriptions will remain as they are.</p>
                <button type="submit" class="guide-delete">Delete my saved answers</button>
            </form>
        @endif
    </section>
</x-layouts.app>
