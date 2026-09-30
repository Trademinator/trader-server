import test from 'node:test';
import assert from 'node:assert/strict';
import { candleTrainingChartData, candleTrainingMove, nextCandleSelection } from '../../resources/js/components/candle-training-chart.js';

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
