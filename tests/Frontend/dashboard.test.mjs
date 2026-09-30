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

async function searchHarness() {
    const { readFileSync } = await import('node:fs');
    const { runInNewContext } = await import('node:vm');
    const source = readFileSync(new URL('../../resources/js/components/dashboard.js', import.meta.url), 'utf8')
        .replace(/^import .*;\n/, '').replaceAll('export ', '');
    const element = () => ({ value: '', textContent: '', innerHTML: 'initial cards', listeners: {}, attributes: {},
        addEventListener(name, callback) { this.listeners[name] = callback; },
        removeEventListener(name) { delete this.listeners[name]; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
        querySelectorAll() { return []; }, replaceChildren() { this.innerHTML = ''; } });
    const input = element(), form = element(), results = element(), status = element(), attention = element();
    form.querySelector = () => input;
    const parts = { '[data-market-search]': form, '[data-market-results]': results, '[data-search-status]': status };
    const root = { dataset: { url: '/dashboard', selected: 'selected-subscription' }, querySelector: selector => parts[selector] };
    const pending = [], timers = new Map();
    let timerId = 0;
    const context = { URL, AbortController, Number, Error,
        window: { location: { href: 'https://trademinator.test/dashboard' }, addEventListener() {} },
        document: { querySelector: () => attention },
        setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout(id) { timers.delete(id); },
        fetch(url, options) { return new Promise(resolve => pending.push({ url, options, resolve })); } };
    runInNewContext(source, context);
    const dispose = context.mountDashboardMarkets(root);
    const submit = value => {
        input.value = value;
        let prevented = false;
        const promise = form.listeners.submit({ preventDefault() { prevented = true; } });
        assert.equal(prevented, true);
        return promise;
    };
    const respond = (index, data, options = {}) => pending[index].resolve({ ok: true, status: 200, json: async () => data, ...options });
    return { input, form, results, status, attention, pending, timers, submit, respond, dispose };
}

test('searches and clears all followed markets without navigating or reloading', async () => {
    const view = await searchHarness();
    const first = view.submit(' eth/us ');
    assert.equal(view.pending[0].url.searchParams.get('q'), 'eth/us');
    assert.equal(view.pending[0].url.searchParams.get('page'), '1');
    assert.equal(view.pending[0].url.searchParams.get('subscription'), 'selected-subscription');
    view.respond(0, { html: 'ETH/USDT card', count: 1, attention_count: 0 });
    await first;
    assert.equal(view.results.innerHTML, 'ETH/USDT card');
    assert.equal(view.status.textContent, '1 matching market');
    assert.equal(view.attention.textContent, 0);
    const clear = view.submit('');
    view.respond(1, { html: 'all cards', count: 27 });
    await clear;
    assert.equal(view.pending[1].url.searchParams.get('q'), '');
    assert.equal(view.results.innerHTML, 'all cards');
    assert.equal(view.status.textContent, '27 matching markets');
    view.dispose();
});

test('ignores out-of-order searches, preserves results on failure and clears them on lost access', async () => {
    const view = await searchHarness();
    const old = view.submit('btc');
    const latest = view.submit('coinbase');
    assert.equal(view.pending[0].options.signal.aborted, true);
    view.respond(1, { html: 'new results', count: 2 });
    await latest;
    view.respond(0, { html: 'stale results', count: 1 });
    await old;
    assert.equal(view.results.innerHTML, 'new results');
    const failed = view.submit('kraken');
    view.respond(2, {}, { ok: false, status: 503 });
    await failed;
    assert.equal(view.results.innerHTML, 'new results');
    assert.match(view.status.textContent, /Search failed/);
    const revoked = view.submit('kraken');
    view.respond(3, {}, { ok: false, status: 401 });
    await revoked;
    assert.equal(view.results.innerHTML, '');
    assert.match(view.status.textContent, /session has changed/);
    view.dispose();
});

test('debounces typing and fetches the chosen results page without navigation', async () => {
    const view = await searchHarness();
    view.input.value = 'b'; view.input.listeners.input();
    view.input.value = 'bi'; view.input.listeners.input();
    view.input.value = 'bit'; view.input.listeners.input();
    assert.equal(view.pending.length, 0);
    assert.equal([...view.timers.values()].filter(timer => timer.delay === 180).length, 1);
    const delayed = [...view.timers.values()].find(timer => timer.delay === 180).callback();
    assert.equal(view.pending[0].url.searchParams.get('q'), 'bit');
    view.respond(0, { html: 'first page', count: 30 });
    await delayed;
    let prevented = false;
    const page = view.results.listeners.click({ button: 0,
        target: { closest: () => ({ href: 'https://trademinator.test/dashboard?page=2&q=bit' }) },
        preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(view.pending[1].url.searchParams.get('page'), '2');
    assert.equal(view.pending[1].url.searchParams.get('q'), 'bit');
    view.respond(1, { html: 'second page', count: 30 });
    await page;
    assert.equal(view.results.innerHTML, 'second page');
    view.dispose();
});
