import test from 'node:test';
import assert from 'node:assert/strict';
import { trainingChartData, trainingChartTheme, observedCloseMove } from '../../resources/js/components/human-training-chart.js';

const candle = time => ({ time, open: '10.5', high: '12', low: '9', close: '11', volume: '100' });

test('training chart excludes candles starting at or beyond the decision cutoff', () => {
    const data = trainingChartData({ decision_at_ms: 120000, series: [candle(0), candle(60), candle(120), candle(180)] });
    assert.deepEqual(data.candles.map(row => row.time), [0, 60]);
    assert.equal(data.candles[0].open, 10.5);
    assert.deepEqual(data.markers, []);
    assert.equal(data.assessmentTime, 60);
});

test('assessment always tracks the final visible closed candle', () => {
    const data = trainingChartData({ decision_at_ms: 180000, series: [candle(0), candle(60), candle(120), candle(180)] });
    assert.equal(data.assessmentTime, 120);
    assert.equal(data.series.at(-1).time, data.assessmentTime);
});

test('five human outcome classes draw distinct close-to-close lines', () => {
    const labels = ['super_bear','bear','neutral','bull','super_bull'];
    const colors = new Set();
    for (const label of labels) {
        const data = trainingChartData({ decision_at_ms: 120000, series: [candle(0), candle(60)],
            future_candle: candle(180), future_series: [candle(120), candle(180)], horizon_candles: 2, label, machine_outcome: 'bear' });
        assert.equal(data.assessmentTime, 60);
        assert.equal(data.futureTime, 180);
        assert.deepEqual(data.humanLine.points.map(point => point.time), [60,180]);
        assert.equal(data.computerLine.color, '#2563eb');
        colors.add(data.humanLine.color);
    }
    assert.equal(colors.size, 5);
    assert.equal(trainingChartData({ decision_at_ms: 120000, series: [candle(60)], label: 'skip' }).humanLine, null);
});

test('chart background stays transparent so the assessment shading can render behind the candle', () => {
    assert.equal(trainingChartTheme(false).layout.background.color, 'transparent');
    assert.equal(trainingChartTheme(true).layout.background.color, 'transparent');
});

test('H=4 renders all intervening candles instead of adjacent decision and future', () => {
    const data = trainingChartData({ decision_at_ms: 120000,
        series: [candle(0), candle(60)], horizon_candles: 4,
        future_series: [candle(120), candle(180), candle(240), candle(300)],
        future_candle: candle(300), label: 'bull' });
    assert.deepEqual(data.candles.map(c => c.time), [0,60,120,180,240,300]);
    assert.equal(data.assessmentTime, 60);
    assert.equal(data.futureTime, 300);
    assert.deepEqual(data.humanLine.points.map(p => p.time), [60,300]);
});
test('missing H intermediate candles cannot be misrepresented as adjacent endpoints', () => {
    const data = trainingChartData({ decision_at_ms: 120000,
        series: [candle(0), candle(60)], horizon_candles: 4,
        future_series: [candle(300)], future_candle: candle(300), label: 'bull' });
    assert.equal(data.futureTime, null);
    assert.equal(data.humanLine, null);
});

test('H=20 includes genuine candles beyond H and balances chart context', () => {
    const c = time => ({ ...candle(time), close: '11' });
    const history = Array.from({length:90}, (_,i) => c(i*60));
    const future = Array.from({length:20}, (_,i) => c((90+i)*60));
    const after = Array.from({length:20}, (_,i) => c((110+i)*60));
    const data = trainingChartData({ decision_at_ms: 90*60000, series: history,
        horizon_candles:20, future_series:future, future_candle:future.at(-1),
        after_series:after, label:'bull' });
    assert.equal(data.candles.length, 61);
    assert.equal(data.candles[20].time, data.assessmentTime);
    assert.equal(data.candles[40].time, data.futureTime);
    assert.equal(data.candles.at(-1).time, after.at(-1).time);
});

test('observed dotted line has signed close-to-close percentages independent of annotations', () => {
    const rising = observedCloseMove({ time: 60, close: 100 }, { time: 180, close: 105 });
    const falling = observedCloseMove({ time: 60, close: 100 }, { time: 180, close: 98 });
    const flat = observedCloseMove({ time: 60, close: 100 }, { time: 180, close: 100 });
    assert.equal(rising.text, '+5.000%');
    assert.equal(falling.text, '-2.000%');
    assert.equal(flat.text, '0.000%');
    assert.deepEqual(rising.points, [{ time: 60, value: 100 }, { time: 180, value: 105 }]);
    assert.equal(observedCloseMove({ time: 60, close: 0 }, { time: 180, close: 105 }), null);
    const data = trainingChartData({ decision_at_ms: 120000, series: [candle(0), candle(60)],
        horizon_candles: 2, future_series: [candle(120), candle(180)],
        future_candle: candle(180), label: null, machine_outcome: null });
    assert.ok(data.observedMove);
    assert.equal(data.humanLine, null);
    assert.equal(data.computerLine, null);
});
