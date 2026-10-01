import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clientMarkers } from '../../resources/js/components/dashboard.js';

const series = [60, 120, 240].map(time => ({ time, open: 100, high: 103, low: 98, close: 102, volume: 50 }));

test('draws Client decisions and fills no earlier than their reported time', () => {
    const markers = clientMarkers({ series, client_events: [
        { id: 'acted', event: 'acted', side: 'buy', occurred_at_ms: 61000 },
        { id: 'fill', event: 'fill', side: 'sell', occurred_at_ms: 150000, price: 101.25 },
        { id: 'future', event: 'fill', side: 'buy', occurred_at_ms: 241000, price: 99 },
    ] }, 0.01);

    assert.deepEqual(markers.map(({ id, time, text, shape }) => ({ id, time, text, shape })), [
        { id: 'client-acted', time: 120, text: 'C BUY', shape: 'arrowUp' },
        { id: 'client-fill', time: 240, text: 'FILL SELL 101.25', shape: 'square' },
    ]);
});
