import test from 'node:test';
import assert from 'node:assert/strict';
import { appendCandleHistory, candleActionAllowed, candleMeasurementLine, candleTrainingChartData, candleTrainingMove, nextCandleSelection, prependCandleHistory } from '../../resources/js/components/candle-training-chart.js';

const candle = (time, close = '11') => ({ time, open: '10.5', high: '12', low: '9', close, volume: '100' });

test('Candle Training hides future candles and renders only explicit BUY HOLD SELL markers', () => {
    const data = candleTrainingChartData({
        decision_at_ms: 4000,
        series: [candle(1), candle(2), candle(3), candle(4)],
        labels: [{ time: 1, action: 'buy' }, { time: 2, action: 'hold' }, { time: 3, action: 'sell' }],
        decisions: { 1: 2000, 2: 3000, 3: 4000 },
    });

    assert.deepEqual(data.series.map(row => row.time), [1, 2, 3]);
    assert.deepEqual(data.markers.map(marker => marker.text), ['BUY', 'HOLD', 'SELL']);
    assert.equal(data.decisions['3'], 4000);
});

test('an unlabelled selected candle does not become HOLD', () => {
    const data = candleTrainingChartData({ decision_at_ms: 3000, series: [candle(1), candle(2)], labels: [] });
    assert.equal(data.markers.length, 0);
});

test('A B measurement shifts the previous B to A on the third click', () => {
    let selection = nextCandleSelection([], 10);
    assert.deepEqual(selection, [10]);
    selection = nextCandleSelection(selection, 20);
    assert.deepEqual(selection, [10, 20]);
    selection = nextCandleSelection(selection, 30);
    assert.deepEqual(selection, [20, 30]);
});

test('close-to-close movement is compared with the approximate two-taker-trade fee', () => {
    const result = candleTrainingMove(candle(1, '100'), candle(2, '100.15'), 0.001);
    assert.ok(result);
    assert.ok(Math.abs(result.percent - 0.15) < 1e-9);
    assert.equal(result.perSidePercent, 0.1);
    assert.equal(result.roundTripPercent, 0.2);
    assert.equal(result.comparison, 'less');

    const larger = candleTrainingMove(candle(1, '100'), candle(2, '100.5'), 0.001);
    assert.equal(larger.comparison, 'greater');
});

test('the candle menu permits BUY on red, SELL on green and HOLD on every bar', () => {
    for (const [close, expected] of [['10', ['buy', 'hold']], ['11', ['hold', 'sell']], ['10.5', ['hold']]]) {
        assert.deepEqual(['buy', 'hold', 'sell'].filter(action => candleActionAllowed(candle(1, close), action)), expected);
    }
    assert.equal(candleActionAllowed(null, 'hold'), false);
    assert.equal(candleActionAllowed(candle(1), 'unknown'), false);
    // Decimal prices can differ beyond JS precision; the server's exact comparison wins.
    assert.equal(candleActionAllowed(candle(1, '10.5'), 'buy', ['buy', 'hold']), true);
    assert.equal(candleActionAllowed(candle(1, '10'), 'buy', ['hold']), false);
});

test('the A B line carries the signed percentage, direction colour and sorted endpoints', () => {
    assert.deepEqual(candleMeasurementLine(candle(1, '100'), candle(3, '110')), {
        points: [{ time: 1, value: 100 }, { time: 3, value: 110 }], color: '#087b6b', direction: 'up', text: '+10.000%',
    });
    assert.deepEqual(candleMeasurementLine(candle(3, '100'), candle(1, '90')), {
        points: [{ time: 1, value: 90 }, { time: 3, value: 100 }], color: '#c33e50', direction: 'down', text: '-10.000%',
    });
    assert.equal(candleMeasurementLine(candle(1, '100'), candle(2, '100')).color, '#64748b');
    assert.deepEqual(candleMeasurementLine(candle(1), candle(1)).points, [{ time: 1, value: 11 }]);
    assert.deepEqual(candleMeasurementLine(candle(1), null).points, []);
});

test('older pages preserve existing candles, deduplicate overlaps and never append future data', () => {
    const current = [candle(3), candle(4)];
    const merged = prependCandleHistory(current, [candle(2), candle(1), candle(2), candle(3, '999'), candle(5)], 5000);
    assert.deepEqual(merged.series.map(row => row.time), [1, 2, 3, 4]);
    assert.equal(merged.series[2].close, '11');
    assert.equal(merged.added, 2);
    assert.equal(prependCandleHistory(merged.series, [candle(1), candle(2)], 5000).added, 0);
});

// Small chart/DOM adapters exercise the mounted component without another test dependency.
async function mountedChart(t, fetchResponse, snapshot = {}) {
    const { mountCandleTrainingChart } = await import('../../resources/js/components/candle-training-chart.js');
    class Element {
        constructor() { this.listeners = new Map(); this.dataset = {}; this.style = {}; this.hidden = false; this.disabled = false; this.textContent = ''; }
        addEventListener(name, callback) { this.listeners.set(name, callback); }
        removeEventListener(name) { this.listeners.delete(name); }
        trigger(name, event = {}) { return this.listeners.get(name)?.(event); }
        getBoundingClientRect() { return { left: 0, top: 0, width: 220, height: 120 }; }
        contains(target) { return target === this; }
        setAttribute(name, value) { this[name] = value; }
        removeAttribute(name) { delete this[name]; }
    }
    const keys = ['status', 'canvas', 'legend', 'fit', 'candle-menu', 'menu-title', 'history-status', 'history-retry',
        'measure-tooltip', 'candle-dataset', 'candle-dataset-form', 'measure-a', 'measure-b', 'measure-move',
        'measure-fee', 'balanced-samples', 'balance-hint', 'stat-total', 'replay-time', 'step-previous', 'step-next'];
    const nodes = new Map(keys.map(key => [`[data-${key}]`, new Element()]));
    const buttons = ['buy', 'hold', 'sell', 'delete'].map(action => {
        const button = new Element();
        button.dataset.menuAction = action;
        nodes.set(`[data-menu-action="${action}"]`, button);
        return button;
    });
    for (const action of ['buy', 'hold', 'sell']) {
        for (const key of ['count', 'percent', 'progress']) nodes.set(`[data-stat-${key}="${action}"]`, new Element());
    }
    const root = new Element();
    root.querySelector = selector => nodes.get(selector) ?? null;
    const menu = nodes.get('[data-candle-menu]');
    menu.hidden = true;
    menu.querySelector = root.querySelector;
    menu.querySelectorAll = () => buttons;
    let switched = false;
    nodes.get('[data-candle-dataset-form]').requestSubmit = () => { switched = true; };
    root.dataset = { replayUrl: '/training', historyUrl: '/history', updateUrl: '/labels', deleteUrl: '/labels', csrf: 'fixture',
        snapshot: JSON.stringify({ decision_at_ms: 5000, series: [candle(3, '10'), candle(4, '11')], has_more: true,
            labels: [], decisions: { 3: 4000, 4: 5000 }, allowed_actions: { 3: ['buy', 'hold'], 4: ['hold', 'sell'] },
            stats: { counts: { buy: 1, hold: 0, sell: 0 } }, ...snapshot }) };
    const document = new Element();
    document.documentElement = { classList: { contains: () => false } };
    const window = new Element();
    window.location = { href: 'http://localhost/training' };
    window.innerWidth = 1000;
    window.innerHeight = 800;
    t.mock.timers.enable({ apis: ['setTimeout'] });
    window.setTimeout = globalThis.setTimeout;
    const originals = new Map(['document', 'window', 'MutationObserver', 'requestAnimationFrame'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    Object.assign(globalThis, { document, window, MutationObserver: class { observe() {} disconnect() {} }, requestAnimationFrame: callback => callback() });
    const requests = [];
    t.mock.method(globalThis, 'fetch', async (url, options) => { requests.push({ url: String(url), options }); return fetchResponse(url, options); });
    const plots = [];
    const scale = { range: { from: 0, to: 1 }, getVisibleLogicalRange() { return this.range; },
        setVisibleLogicalRange(range) { this.range = range; }, fitContent() {},
        timeToCoordinate: time => Number(time) * 10, coordinateToTime: x => x / 10,
        subscribeVisibleLogicalRangeChange(callback) { this.onRange = callback; }, unsubscribeVisibleLogicalRangeChange() {} };
    const chart = { timeScale: () => scale, panes: () => [{}, { setHeight() {} }], applyOptions() {}, remove() {},
        addSeries() { const series = { data: [], options: {}, setData(data) { this.data = data; }, applyOptions(options) { this.options = options; }, priceToCoordinate: value => value }; plots.push(series); return series; },
        subscribeCrosshairMove(callback) { this.onCrosshair = callback; }, subscribeClick(callback) { this.onClick = callback; }, unsubscribeClick() {} };
    const markers = { data: [], setMarkers(data) { this.data = data; } };
    await mountCandleTrainingChart(root, () => ({ createChart: () => chart, createSeriesMarkers: (_price, initial) => { markers.data = initial; return markers; } }));
    t.after(() => {
        window.trigger('pagehide', { persisted: false });
        for (const [key, descriptor] of originals) { if (descriptor) Object.defineProperty(globalThis, key, descriptor); else delete globalThis[key]; }
    });
    return { root, nodes, chart, scale, plots, markers, document, requests, switched: () => switched };
}

const flushRequests = () => new Promise(resolve => setImmediate(resolve));

test('dragging prepends history with markers, preserves the viewport and prevents duplicate requests', async t => {
    let resolvePage;
    const mounted = await mountedChart(t, () => new Promise(resolve => { resolvePage = resolve; }));
    const { nodes, chart, scale, plots, markers, requests } = mounted;
    scale.onRange({ from: 0, to: 1 });
    t.mock.timers.tick(180);
    assert.equal(requests.length, 0, 'initial fit must not start downloading the entire dataset');

    nodes.get('[data-canvas]').trigger('pointerdown', { pointerType: 'mouse' });
    scale.range = { from: -2, to: 0 };
    scale.onRange(scale.range);
    t.mock.timers.tick(180);
    assert.equal(requests.length, 1);
    assert.equal(new URL(requests[0].url).searchParams.get('before_ms'), '3000');
    scale.range = { from: -3, to: -1 };
    scale.onRange(scale.range);
    t.mock.timers.tick(180);
    assert.equal(requests.length, 1);
    resolvePage({ ok: true, json: async () => ({ series: [candle(1, '10'), candle(2)], labels: [{ time: 1, action: 'buy' }],
        decisions: { 1: 2000, 2: 3000 }, allowed_actions: { 1: ['buy', 'hold'], 2: ['hold', 'sell'] }, has_more: false }) });
    await flushRequests();

    assert.deepEqual(plots[0].data.map(row => row.time), [1, 2, 3, 4]);
    assert.deepEqual(scale.range, { from: -1, to: 1 }, 'use the latest viewport, including movement while waiting');
    assert.ok(markers.data.some(marker => marker.id === 'human-1' && marker.text === 'BUY'));
    scale.onRange({ from: -1, to: 1 });
    t.mock.timers.tick(180);
    assert.equal(requests.length, 1, 'stop fetching at the dataset boundary');
    chart.onClick({ time: 1 });
    chart.onClick({ time: 4 });
    assert.deepEqual(plots[2].data, [{ time: 1, value: 10 }, { time: 4, value: 11 }]);
    assert.equal(nodes.get('[data-measure-move]').textContent, '+10.000%');
    assert.equal(plots[2].options.color, '#087b6b');
    chart.onCrosshair({ point: { x: 25, y: 10.5 }, paneIndex: 0 });
    assert.equal(nodes.get('[data-measure-tooltip]').textContent, 'A → B: +10.000%');
    assert.equal(nodes.get('[data-measure-tooltip]').hidden, false);
    nodes.get('[data-candle-dataset]').trigger('change');
    assert.equal(mounted.switched(), true);
});

test('failed history loads preserve the chart and offer an explicit retry', async t => {
    let attempt = 0;
    const { nodes, plots, requests } = await mountedChart(t, async () => ++attempt === 1
        ? { ok: false, status: 429 }
        : { ok: true, json: async () => ({ series: [candle(2)], labels: [], decisions: { 2: 3000 }, has_more: false }) });
    await nodes.get('[data-history-retry]').trigger('click');
    assert.deepEqual(plots[0].data.map(row => row.time), [3, 4]);
    assert.equal(nodes.get('[data-history-retry]').hidden, false);
    assert.match(nodes.get('[data-history-status]').textContent, /Wait a minute/);
    await nodes.get('[data-history-retry]').trigger('click');
    assert.equal(requests.length, 2);
    assert.equal(nodes.get('[data-history-retry]').hidden, true);
    assert.deepEqual(plots[0].data.map(row => row.time), [2, 3, 4]);
});

test('menu rules persist after a save and closing the menu cannot move the label to another candle', async t => {
    let resolveSave;
    const { nodes, document, markers, requests } = await mountedChart(t, () => new Promise(resolve => { resolveSave = resolve; }));
    const canvas = nodes.get('[data-canvas]');
    canvas.trigger('contextmenu', { clientX: 30, clientY: 20, preventDefault() {} });
    assert.equal(nodes.get('[data-menu-action="sell"]').disabled, true);
    assert.equal(nodes.get('[data-menu-action="buy"]').disabled, false);
    const pending = nodes.get('[data-menu-action="hold"]').trigger('click');
    document.trigger('pointerdown', { target: canvas });
    resolveSave({ ok: true, json: async () => ({ action: 'hold', message: 'Candle marked HOLD.' }) });
    await pending;

    assert.equal(JSON.parse(requests[0].options.body).decision_at_ms, 4000);
    assert.ok(markers.data.some(marker => marker.id === 'human-3' && marker.text === 'HOLD'));
    assert.ok(markers.data.every(marker => marker.time !== 0));
    assert.equal(nodes.get('[data-menu-action="sell"]').disabled, true);
    assert.equal(nodes.get('[data-stat-count="hold"]').textContent, '1');
    canvas.trigger('contextmenu', { clientX: 40, clientY: 20, preventDefault() {} });
    assert.equal(nodes.get('[data-menu-action="buy"]').disabled, true);
    assert.equal(nodes.get('[data-menu-action="sell"]').disabled, false);
});


test('newer pages preserve existing data and cannot exceed the frozen dataset cutoff', () => {
    const merged = appendCandleHistory([candle(3), candle(4)], [candle(5), candle(4, '999'), candle(5), candle(6), candle(7)], 7000);
    assert.deepEqual(merged.series.map(row => row.time), [3, 4, 5, 6]);
    assert.equal(merged.series[1].close, '11');
    assert.equal(merged.added, 2);
});

test('forward dragging loads labels once, preserves the viewport and stops at the newest available candle', async t => {
    let resolvePage;
    const { nodes, scale, plots, markers, requests } = await mountedChart(t, () => new Promise(resolve => { resolvePage = resolve; }),
        { has_more: false, has_newer: true, latest_decision_at_ms: 7000 });
    nodes.get('[data-canvas]').trigger('pointerdown', { pointerType: 'mouse' });
    scale.range = { from: 1, to: 3 };
    scale.onRange(scale.range);
    t.mock.timers.tick(180);
    assert.equal(requests.length, 1);
    assert.equal(new URL(requests[0].url).searchParams.get('after_ms'), '4000');
    assert.equal(new URL(requests[0].url).searchParams.has('before_ms'), false);
    scale.range = { from: 2, to: 4 };
    scale.onRange(scale.range);
    t.mock.timers.tick(180);
    assert.equal(requests.length, 1);
    resolvePage({ ok: true, json: async () => ({ series: [candle(5), candle(6, '10')], labels: [{ time: 5, action: 'sell' }],
        decisions: { 5: 6000, 6: 7000 }, allowed_actions: { 5: ['hold', 'sell'], 6: ['buy', 'hold'] },
        decision_at_ms: 7000, has_more: false, previous_decision_at_ms: 4000, next_decision_at_ms: null }) });
    await flushRequests();

    assert.deepEqual(plots[0].data.map(row => row.time), [3, 4, 5, 6]);
    assert.deepEqual(scale.range, { from: 2, to: 4 });
    assert.ok(markers.data.some(marker => marker.id === 'human-5' && marker.text === 'SELL'));
    assert.equal(nodes.get('[data-step-next]')['aria-disabled'], 'true');
    assert.match(nodes.get('[data-step-previous]').href, /decision_at_ms=4000/);
    assert.match(nodes.get('[data-replay-time]').textContent, /UTC/);
    assert.equal(nodes.get('[data-history-status]').textContent, 'Newest available candle reached.');
    scale.onRange({ from: 4, to: 6 });
    t.mock.timers.tick(180);
    assert.equal(requests.length, 1);
    nodes.get('[data-canvas]').trigger('contextmenu', { clientX: 60, clientY: 20, preventDefault() {} });
    assert.equal(nodes.get('[data-menu-action="buy"]').disabled, false);
    assert.equal(nodes.get('[data-menu-action="sell"]').disabled, true);
});

test('a failed forward request retries in the same direction and rejects data beyond the opening cutoff', async t => {
    const { nodes, scale, plots, requests } = await mountedChart(t, async () => ({ ok: true,
        json: async () => ({ series: [candle(7)], labels: [], decision_at_ms: 8000, has_more: false }) }),
    { has_more: false, has_newer: true, latest_decision_at_ms: 7000 });
    nodes.get('[data-canvas]').trigger('pointerdown', { pointerType: 'mouse' });
    scale.range = { from: 1, to: 3 };
    scale.onRange(scale.range);
    t.mock.timers.tick(180);
    await flushRequests();

    assert.deepEqual(plots[0].data.map(row => row.time), [3, 4]);
    assert.equal(nodes.get('[data-history-retry]').hidden, false);
    await nodes.get('[data-history-retry]').trigger('click');
    assert.equal(requests.length, 2);
    assert.ok(requests.every(request => new URL(request.url).searchParams.get('after_ms') === '4000'));
    assert.deepEqual(plots[0].data.map(row => row.time), [3, 4]);
});
