import test from 'node:test';
import assert from 'node:assert/strict';
import { trainingChartData, trainingChartTheme } from '../../resources/js/components/human-training-chart.js';

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

test('saved human labels have their own provenance and marker direction', () => {
    for (const [label, shape] of [['bull', 'arrowUp'], ['super_bull', 'arrowUp'], ['bear', 'arrowDown'], ['super_bear', 'arrowDown'], ['neutral', 'circle']]) {
        const data = trainingChartData({ decision_at_ms: 120000, series: [candle(60)], label });
        assert.equal(data.markers[0].shape, shape);
        assert.equal(data.markers[0].time, 60);
        assert.match(data.markers[0].text, /^Human:/);
    }
    assert.deepEqual(trainingChartData({ decision_at_ms: 120000, series: [candle(60)], label: 'skip' }).markers, []);
});

test('chart background stays transparent so the assessment shading can render behind the candle', () => {
    assert.equal(trainingChartTheme(false).layout.background.color, 'transparent');
    assert.equal(trainingChartTheme(true).layout.background.color, 'transparent');
});
