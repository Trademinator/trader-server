import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chartData, formatPrice } from '../../resources/js/components/market-review-chart.js';

test('keeps UTC seconds and raw quote prices separate from inverse risk evidence', () => {
    const data = chartData({ inverse: true, series: [
        { time: 1790467200, open: 100, high: 103, low: 98, close: 102, volume: 50 },
        { time: 1790553600, open: 102, high: 103, low: 99, close: 101, volume: 0 },
    ] });
    assert.deepEqual(data.candles, [
        { time: 1790467200, open: 100, high: 103, low: 98, close: 102 },
        { time: 1790553600, open: 102, high: 103, low: 99, close: 101 },
    ]);
    assert.deepEqual(data.volume.map(({ time, value }) => ({ time, value })), [
        { time: 1790467200, value: 50 }, { time: 1790553600, value: 0 },
    ]);
    assert.notEqual(data.volume[0].color, data.volume[1].color);
});

test('does not manufacture candles for unavailable history', () => {
    assert.deepEqual(chartData({ known: false, series: [] }), { candles: [], volume: [] });
});

test('preserves tiny prices and zero volume in chart labels', () => {
    assert.equal(formatPrice(0.000000000000000001, 0.000000000000000001), '0.000000000000000001');
    assert.equal(formatPrice(0), '0');
    assert.equal(formatPrice(12345.67, 0.01), '12,345.67');
});

// Exercise the real view controller with the chart library as the external boundary.
async function chartHarness(series = []) {
    const { readFileSync } = await import('node:fs');
    const { runInNewContext } = await import('node:vm');
    const source = readFileSync(new URL('../../resources/js/components/market-review-chart.js', import.meta.url), 'utf8')
        .replaceAll('export ', '').replace("await import('lightweight-charts')", 'await loadChartLibrary()');
    const elements = new Map();
    const element = () => ({ textContent: '', disabled: true, checked: true, hidden: true,
        classList: { toggle() {} }, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        removeEventListener(name) { delete this.listeners[name]; },
    });
    const root = { dataset: { reviewType: 'preference', tickSize: '0.01', quote: 'CAD', symbol: 'BTC/CAD', url: '/review', evidence: JSON.stringify({
        series, period: '1d', candles: series.length, message: 'Sample status', age_seconds: 120,
        last_closed_at: series.length ? '2026-09-27T00:00:00Z' : null,
        from: '2026-09-01', through: '2026-09-27',
    }) }, querySelector(selector) { if (!elements.has(selector)) elements.set(selector, element()); return elements.get(selector); } };
    const subscribe = { disabled: false };
    const seriesData = [];
    const chart = { addSeries() { const state = { data: [] }; seriesData.push(state); return { setData(data) { state.data = data; } }; },
        panes: () => [{}, { setHeight() {} }], subscribeCrosshairMove() {}, applyOptions() {}, remove() {},
        timeScale: () => ({ fitContent() {}, getVisibleLogicalRange() { return null; }, setVisibleLogicalRange() {} }),
    };
    let response = { ok: true, json: async () => ({ review_type: 'preference', symbol: 'BTC/CAD', checked_at: '2026-09-27T00:03:00Z', evidence: JSON.parse(root.dataset.evidence) }) };
    let scheduled = 0;
    const context = {
        Intl, Math, Number, JSON, Date, Array, Error, AbortController,
        loadChartLibrary: async () => ({ createChart: () => chart, CandlestickSeries: 'candle', HistogramSeries: 'volume' }),
        document: { hidden: false, documentElement: { classList: { contains: () => false } },
            querySelectorAll: () => [subscribe], addEventListener() {}, removeEventListener() {} },
        window: { addEventListener() {}, removeEventListener() {} },
        MutationObserver: class { observe() {} disconnect() {} },
        setTimeout() { scheduled++; return scheduled; }, clearTimeout() {},
        fetch: async () => response,
    };
    runInNewContext(source, context);
    const dispose = await context.mountReviewChart(root);
    return { root, elements, subscribe, seriesData, dispose, setResponse(value) { response = value; },
        refresh: () => elements.get('[data-chart-refresh]').listeners.click(), scheduled: () => scheduled };
}

test('renders the candle and volume series and preserves a readable empty state', async () => {
    const populated = await chartHarness([{ time: 1790467200, open: 100, high: 103, low: 98, close: 102, volume: 50 }]);
    assert.equal(populated.elements.get('[data-chart-canvas]').hidden, false);
    assert.equal(populated.seriesData[0].data[0].close, 102);
    assert.equal(populated.seriesData[1].data[0].value, 50);
    assert.match(populated.elements.get('[data-chart-legend]').textContent, /C 102 CAD/);
    populated.dispose();
    const empty = await chartHarness();
    assert.equal(empty.elements.get('[data-chart-canvas]').hidden, true);
    assert.match(empty.elements.get('[data-chart-freshness]').textContent, /No closed candles/);
    assert.equal(empty.subscribe.disabled, false);
    empty.dispose();
});

test('refreshes data, keeps the last sample on an outage, and stops when preferences become obsolete', async () => {
    const harness = await chartHarness([{ time: 1790467200, open: 100, high: 103, low: 98, close: 102, volume: 50 }]);
    await harness.refresh();
    assert.match(harness.elements.get('[data-chart-status]').textContent, /Checked/);
    harness.setResponse({ ok: false, status: 503 });
    await harness.refresh();
    assert.match(harness.elements.get('[data-chart-status]').textContent, /last successful sample/);
    assert.equal(harness.subscribe.disabled, false);
    assert.equal(harness.seriesData[0].data[0].close, 102);
    harness.setResponse({ ok: false, status: 409 });
    await harness.refresh();
    assert.equal(harness.subscribe.disabled, true);
    assert.equal(harness.elements.get('[data-chart-refresh]').disabled, true);
    assert.equal(harness.elements.get('[data-chart-auto]').checked, false);
    assert.match(harness.elements.get('[data-chart-status]').textContent, /Reload the full review/);
    const scheduled = harness.scheduled();
    await harness.refresh();
    assert.equal(harness.scheduled(), scheduled);
    harness.dispose();
});

test('stops an old preference review when refresh now returns a technical review', async () => {
    const harness = await chartHarness();
    harness.setResponse({ ok: true, json: async () => ({ review_type: 'technical', symbol: 'BTC/CAD', evidence: { series: [] } }) });
    await harness.refresh();
    assert.equal(harness.subscribe.disabled, true);
    assert.equal(harness.elements.get('[data-chart-refresh]').disabled, true);
    assert.equal(harness.elements.get('[data-chart-auto]').checked, false);
    assert.match(harness.elements.get('[data-chart-status]').textContent, /preference assessment has changed/);
    harness.dispose();
});
