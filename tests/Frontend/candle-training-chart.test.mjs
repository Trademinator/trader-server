import test from 'node:test';
import assert from 'node:assert/strict';
import { candleTrainingChartData } from '../../resources/js/components/candle-training-chart.js';

const candle = time => ({ time, open: '10.5', high: '12', low: '9', close: '11', volume: '100' });

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
