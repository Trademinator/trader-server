import { test } from 'node:test';
import assert from 'node:assert/strict';
import { signalMarkers } from '../../resources/js/components/dashboard.js';
import { chartData, formatPrice } from '../../resources/js/components/market-review-chart.js';

const series = [60, 120, 240].map(time => ({ time, open: 100, high: 103, low: 98, close: 102, volume: 50 }));

test('places observations no earlier than recording time, keeps gaps and distinguishes HOLD from waiting', () => {
    const markers = signalMarkers({ series, signals: [
        { id: 'buy', action: 'buy', reason: 'supported', decision_at_ms: 60000, recorded_at_ms: 61000 },
        { id: 'sell', action: 'sell', reason: 'supported', recorded_at_ms: 150000 },
        { id: 'hold', action: 'hodl', reason: 'supported', recorded_at_ms: 120000 },
        { id: 'wait', action: 'hodl', reason: 'weak_consensus', recorded_at_ms: 240000 },
        { id: 'future', action: 'buy', reason: 'supported', recorded_at_ms: 241000 },
    ] });
    assert.deepEqual(markers.map(({ id, time, text, shape }) => ({ id, time, text, shape })), [
        { id: 'buy', time: 120, text: 'BUY', shape: 'arrowUp' },
        { id: 'hold', time: 120, text: 'HOLD', shape: 'circle' },
        { id: 'sell', time: 240, text: 'SELL', shape: 'arrowDown' },
        { id: 'wait', time: 240, text: 'WAIT', shape: 'square' },
    ]);
    assert.deepEqual(signalMarkers({ series: [], signals: [{ recorded_at_ms: 60000 }] }), []);
});

async function harness() {
    const { readFileSync } = await import('node:fs');
    const { runInNewContext } = await import('node:vm');
    const source = readFileSync(new URL('../../resources/js/components/dashboard.js', import.meta.url), 'utf8')
        .replace(/^import .*;\n/, '').replaceAll('export ', '').replace("await import('lightweight-charts')", 'await loadChartLibrary()');
    const elements = new Map();
    const root = { dataset: { chart: JSON.stringify({ series, signals: [], period: '1m', checked_at_ms: 300000, stale: false }),
        subscription: 'own-subscription', url: '/chart', tickSize: '0.01' },
        querySelector(selector) {
            if (!elements.has(selector)) elements.set(selector, { checked: true, hidden: false, disabled: false, textContent: '', listeners: {},
                addEventListener(name, callback) { this.listeners[name] = callback; }, removeEventListener(name) { delete this.listeners[name]; } });
            return elements.get(selector);
        } };
    const states = [];
    const chart = { addSeries() { const state = { data: [] }; states.push(state); return { setData(data) { state.data = data; } }; },
        panes: () => [{}, { setHeight() {} }], subscribeCrosshairMove() {}, applyOptions() {}, remove() {},
        timeScale: () => ({ fitContent() {}, getVisibleLogicalRange: () => null, setVisibleLogicalRange() {} }) };
    let response = { ok: true, json: async () => ({ subscription_id: 'own-subscription', chart: JSON.parse(root.dataset.chart) }) };
    let scheduled = 0;
    const context = { chartData, formatPrice, Intl, Math, Number, JSON, Date, Array, Error, AbortController,
        loadChartLibrary: async () => ({ createChart: () => chart, createSeriesMarkers: () => ({ setMarkers() {} }) }),
        document: { hidden: false, documentElement: { classList: { contains: () => false } }, addEventListener() {}, removeEventListener() {} },
        window: { addEventListener() {}, removeEventListener() {} }, MutationObserver: class { observe() {} disconnect() {} },
        setTimeout() { return ++scheduled; }, clearTimeout() {}, fetch: async () => response };
    runInNewContext(source, context);
    const dispose = await context.mountDashboardChart(root);
    return { elements, states, dispose, setResponse(value) { response = value; },
        refresh: () => elements.get('[data-refresh]').listeners.click(), scheduled: () => scheduled };
}

test('refreshes safely, retains the previous chart during outages and clears it after subscription revocation', async () => {
    const view = await harness();
    assert.equal(view.states[0].data.length, 3);
    await view.refresh();
    assert.match(view.elements.get('[data-status]').textContent, /Checked/);
    view.setResponse({ ok: false, status: 503 });
    await view.refresh();
    assert.equal(view.states[0].data.length, 3);
    assert.match(view.elements.get('[data-status]').textContent, /last successful sample/);
    view.setResponse({ ok: false, status: 404 });
    await view.refresh();
    assert.equal(view.states[0].data.length, 0);
    assert.equal(view.states[1].data.length, 0);
    assert.equal(view.elements.get('[data-refresh]').disabled, true);
    assert.equal(view.elements.get('[data-auto]').checked, false);
    const scheduled = view.scheduled();
    await view.refresh();
    assert.equal(view.scheduled(), scheduled);
    view.dispose();
});

test('rejects a refresh response for another subscription', async () => {
    const view = await harness();
    view.setResponse({ ok: true, json: async () => ({ subscription_id: 'other', chart: { series: [] } }) });
    await view.refresh();
    assert.match(view.elements.get('[data-status]').textContent, /Unexpected chart response/);
    assert.equal(view.states[0].data.length, 3);
    view.dispose();
});
