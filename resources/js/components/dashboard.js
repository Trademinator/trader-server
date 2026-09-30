import { chartData, formatPrice } from './market-review-chart.js';

export function mountDashboardMarkets(root) {
    const form = root.querySelector('[data-market-search]');
    const input = form.querySelector('input');
    const results = root.querySelector('[data-market-results]');
    const status = root.querySelector('[data-search-status]');
    let timer, request, revision = 0, disposed = false;
    const logos = () => results.querySelectorAll('.dashboard-exchange-logo img').forEach(image => {
        const fallback = () => image.remove();
        image.addEventListener('error', fallback, { once: true });
        if (image.complete && image.naturalWidth === 0) fallback();
    });
    async function search(page = 1) {
        const current = ++revision;
        request?.abort();
        const controller = new AbortController();
        request = controller;
        const timeout = setTimeout(() => controller.abort(), 15000);
        const url = new URL(root.dataset.url, window.location.href);
        url.searchParams.set('q', input.value.trim());
        url.searchParams.set('page', page);
        if (root.dataset.selected) url.searchParams.set('subscription', root.dataset.selected);
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Searching your followed markets…';
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: request.signal });
            if ([401, 403, 419].includes(response.status) || response.redirected) {
                if (current === revision) results.replaceChildren();
                throw new Error('Your session has changed. Reload the dashboard.');
            }
            if (!response.ok) throw new Error('Search failed. Your previous results are still shown; try again.');
            const data = await response.json();
            if (disposed || current !== revision) return;
            if (typeof data.html !== 'string' || !Number.isInteger(data.count)) throw new Error('Unexpected search response. Try again.');
            results.innerHTML = data.html;
            logos();
            status.textContent = `${data.count} matching market${data.count === 1 ? '' : 's'}`;
            const attention = document.querySelector('[data-attention-count]');
            if (attention && Number.isInteger(data.attention_count)) attention.textContent = data.attention_count;
        } catch (error) {
            if (!disposed && current === revision) status.textContent = error.name === 'AbortError'
                ? 'Search timed out. Try again.' : error.message;
        } finally {
            clearTimeout(timeout);
            if (current === revision) results.removeAttribute('aria-busy');
        }
    }
    const changed = () => {
        clearTimeout(timer);
        ++revision;
        request?.abort();
        timer = setTimeout(() => search(), 180);
    };
    const submit = event => { event.preventDefault(); clearTimeout(timer); return search(); };
    const paginate = event => {
        const link = event.target.closest('[data-market-pagination] a');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        event.preventDefault();
        clearTimeout(timer);
        return search(new URL(link.href).searchParams.get('page') ?? 1);
    };
    const dispose = () => {
        disposed = true;
        ++revision;
        clearTimeout(timer);
        request?.abort();
        input.removeEventListener('input', changed);
        form.removeEventListener('submit', submit);
        results.removeEventListener('click', paginate);
    };
    input.addEventListener('input', changed);
    form.addEventListener('submit', submit);
    results.addEventListener('click', paginate);
    window.addEventListener('pagehide', event => { if (!event.persisted) dispose(); }, { once: true });
    logos();
    return dispose;
}

// Never draw a decision on an earlier candle than its actual recording time.
export function signalMarkers(chart) {
    const candles = chart.series ?? [];
    return (chart.signals ?? []).flatMap(signal => {
        const candle = candles.find(row => row.time * 1000 >= signal.recorded_at_ms);
        if (!candle) return [];
        const supported = signal.reason === 'supported';
        const buy = supported && signal.action === 'buy';
        const sell = supported && signal.action === 'sell';
        return [{ time: candle.time, position: buy ? 'belowBar' : 'aboveBar',
            color: buy ? '#087b6b' : sell ? '#c33e50' : '#64748b',
            shape: buy ? 'arrowUp' : sell ? 'arrowDown' : supported ? 'circle' : 'square',
            text: buy ? 'BUY' : sell ? 'SELL' : supported ? 'HOLD' : 'WAIT', id: signal.id }];
    }).sort((a, b) => a.time - b.time);
}

export async function mountDashboardChart(root) {
    const canvas = root.querySelector('[data-canvas]');
    const status = root.querySelector('[data-status]');
    const legend = root.querySelector('[data-legend]');
    const refreshButton = root.querySelector('[data-refresh]');
    const fitButton = root.querySelector('[data-fit]');
    const showMarkers = root.querySelector('[data-markers]');
    const automatic = root.querySelector('[data-auto]');
    let data = JSON.parse(root.dataset.chart);
    let chart, price, volume, markers, observer, timer, request, timeout;
    let disposed = false, stopped = false, busy = false, previousPeriod = null;
    const tickSize = Number(root.dataset.tickSize) || 0.00000001;
    const theme = () => {
        const dark = document.documentElement.classList.contains('dark');
        return { layout: { background: { type: 'solid', color: dark ? '#111827' : '#ffffff' }, textColor: dark ? '#e5edf5' : '#172c43', attributionLogo: true },
            grid: { vertLines: { color: dark ? '#263348' : '#edf1f5' }, horzLines: { color: dark ? '#263348' : '#edf1f5' } },
            timeScale: { timeVisible: true, secondsVisible: false } };
    };
    const updateMarkers = () => markers?.setMarkers(showMarkers.checked ? signalMarkers(data) : []);
    const draw = () => {
        const values = chartData(data);
        const range = chart.timeScale().getVisibleLogicalRange();
        price.setData(values.candles);
        volume.setData(values.volume);
        updateMarkers();
        if (previousPeriod !== data.period || range === null) chart.timeScale().fitContent();
        else chart.timeScale().setVisibleLogicalRange(range);
        previousPeriod = data.period;
        canvas.hidden = values.candles.length === 0;
        const latest = data.series.at(-1);
        legend.textContent = latest ? `Latest closed price ${formatPrice(latest.close, tickSize)} · ${data.series.length} candles · ${data.period}` : 'No closed candles collected yet.';
        status.textContent = `${data.stale ? 'History is stale or awaiting collection. ' : 'Stored closed candles are current. '}${data.gaps ? 'Gaps in history; missing intervals are not filled. ' : ''}${data.invalid_candles ? 'Invalid candles were omitted. ' : ''}Checked ${new Date(data.checked_at_ms).toISOString().replace('T', ' ').slice(0, 19)} UTC.`;
    };
    const schedule = () => {
        clearTimeout(timer);
        if (!disposed && !stopped && automatic.checked && !document.hidden) timer = setTimeout(refresh, 60000);
    };
    async function refresh() {
        if (disposed || stopped || busy) return;
        busy = true;
        refreshButton.disabled = true;
        request = new AbortController();
        timeout = setTimeout(() => request?.abort(), 15000);
        try {
            const response = await fetch(root.dataset.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: request.signal });
            if ([401, 403, 404, 419].includes(response.status) || response.redirected) {
                stopped = true;
                automatic.checked = false;
                automatic.disabled = true;
                price.setData([]);
                volume.setData([]);
                markers.setMarkers([]);
                throw new Error('Your session or active subscription has changed. Reload the dashboard.');
            }
            if (response.status === 429) throw new Error('Refresh limit reached. Try again in a minute.');
            if (!response.ok) throw new Error('Refresh failed. Showing the last successful sample.');
            const result = await response.json();
            if (result.subscription_id !== root.dataset.subscription || !Array.isArray(result.chart?.series)) throw new Error('Unexpected chart response. Reload the dashboard.');
            if (disposed) return;
            data = result.chart;
            draw();
        } catch (error) {
            if (!disposed) status.textContent = error.name === 'AbortError' ? 'Refresh timed out. Showing the last successful sample.' : error.message;
        } finally {
            clearTimeout(timeout);
            request = null;
            busy = false;
            refreshButton.disabled = stopped || disposed;
            schedule();
        }
    }
    const visibility = () => { if (document.hidden) clearTimeout(timer); else schedule(); };
    const fit = () => chart?.timeScale().fitContent();
    const dispose = () => {
        disposed = true;
        clearTimeout(timer);
        clearTimeout(timeout);
        request?.abort();
        observer?.disconnect();
        chart?.remove();
        refreshButton.removeEventListener('click', refresh);
        fitButton.removeEventListener('click', fit);
        showMarkers.removeEventListener('change', updateMarkers);
        automatic.removeEventListener('change', schedule);
        document.removeEventListener('visibilitychange', visibility);
        window.removeEventListener('pagehide', pagehide);
    };
    const pagehide = event => { if (event.persisted) clearTimeout(timer); else dispose(); };
    window.addEventListener('pagehide', pagehide);
    try {
        const library = await import('lightweight-charts');
        if (disposed) return dispose;
        chart = library.createChart(canvas, { autoSize: true, ...theme() });
        price = chart.addSeries(library.CandlestickSeries, { upColor: '#159b83', downColor: '#d64a5e', borderVisible: false, wickUpColor: '#159b83', wickDownColor: '#d64a5e', priceFormat: { type: 'custom', minMove: tickSize, formatter: value => formatPrice(value, tickSize) } });
        volume = chart.addSeries(library.HistogramSeries, { priceFormat: { type: 'volume' }, priceLineVisible: false, lastValueVisible: false }, 1);
        chart.panes()[1].setHeight(80);
        markers = library.createSeriesMarkers(price, []);
        chart.subscribeCrosshairMove(({ time }) => {
            const candle = data.series.find(row => row.time === time);
            if (candle) legend.textContent = `${new Date(candle.time * 1000).toISOString().slice(0, 16)} UTC · O ${formatPrice(candle.open, tickSize)} H ${formatPrice(candle.high, tickSize)} L ${formatPrice(candle.low, tickSize)} C ${formatPrice(candle.close, tickSize)}`;
        });
        observer = new MutationObserver(() => chart?.applyOptions(theme()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        draw();
        refreshButton.addEventListener('click', refresh);
        fitButton.addEventListener('click', fit);
        showMarkers.addEventListener('change', updateMarkers);
        automatic.addEventListener('change', schedule);
        document.addEventListener('visibilitychange', visibility);
        schedule();
    } catch {
        dispose();
        canvas.hidden = true;
        status.textContent = 'The chart could not load. The candle table and recorded signal journal remain available.';
    }
    return dispose;
}

export async function loadDashboardSuggestions(root) {
    const abort = new AbortController();
    const timeout = setTimeout(() => abort.abort(), 30000);
    try {
        const response = await fetch(root.dataset.url, { headers: { Accept: 'text/html' }, credentials: 'same-origin', cache: 'no-store', signal: abort.signal });
        if (!response.ok || response.redirected) throw new Error('Unavailable');
        // Same-origin, authenticated Blade output; all variable content is escaped server-side.
        root.innerHTML = await response.text();
    } catch {
        root.querySelector('[role="status"]').textContent = 'Suggestions could not load. Open pair suggestions to try again.';
    } finally {
        clearTimeout(timeout);
    }
}
