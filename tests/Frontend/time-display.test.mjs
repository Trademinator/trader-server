import test from 'node:test';
import assert from 'node:assert/strict';
import { chartTimeOptions, configureTimeDisplay, displayTimezone, formatTimestamp, localTimeInstants, mountTimeDisplay, setTimeMode, timestampMilliseconds } from '../../resources/js/components/time-display.js';

for (const [utc, zone, expected] of [
    ['2026-03-08T06:59:00Z', 'America/Toronto', '2026-03-08 01:59:00 UTC-05:00'],
    ['2026-03-08T07:00:00Z', 'America/Toronto', '2026-03-08 03:00:00 UTC-04:00'],
    ['2026-11-01T05:30:00Z', 'America/Toronto', '2026-11-01 01:30:00 UTC-04:00'],
    ['2026-11-01T06:30:00Z', 'America/Toronto', '2026-11-01 01:30:00 UTC-05:00'],
    ['2026-10-01T20:00:00Z', 'Asia/Kathmandu', '2026-10-02 01:45:00 UTC+05:45'],
]) {
    test(`formats ${utc} in ${zone} with the correct date and offset`, () => {
        assert.equal(formatTimestamp(utc, { timezone: zone }), expected);
    });
}

test('reads legacy naive UTC strings independently of the browser timezone and keeps missing values missing', () => {
    configureTimeDisplay({ timezone: 'Pacific/Auckland' });
    assert.equal(timestampMilliseconds('2026-10-01 12:00 UTC'), Date.parse('2026-10-01T12:00:00Z'));
    assert.equal(timestampMilliseconds('2026-10-01 12:00:00'), Date.parse('2026-10-01T12:00:00Z'));
    assert.equal(timestampMilliseconds('2026-10-01'), Date.parse('2026-10-01T00:00:00Z'));
    for (const value of [null, undefined, '', 'invalid']) assert.equal(formatTimestamp(value), 'Not available');
    assert.equal(formatTimestamp(0, { timezone: 'UTC' }), '1970-01-01 00:00:00 UTC');
});

test('defaults to the browser timezone, honors a saved timezone, and remembers the mode separately per account', () => {
    const data = new Map();
    const storage = { getItem: key => data.get(key), setItem: (key, value) => data.set(key, value) };
    configureTimeDisplay({ browserTimezone: 'America/Toronto', user: 'one', storage });
    assert.equal(displayTimezone(), 'America/Toronto');
    setTimeMode('utc');
    configureTimeDisplay({ timezone: 'Asia/Tokyo', user: 'one', storage });
    assert.equal(displayTimezone(), 'UTC');
    configureTimeDisplay({ timezone: 'Asia/Kathmandu', user: 'two', storage });
    assert.equal(displayTimezone(), 'Asia/Kathmandu');
    configureTimeDisplay({ timezone: 'invalid', browserTimezone: 'America/Toronto', storage: { getItem() { throw new Error('blocked'); }, setItem() { throw new Error('blocked'); } } });
    assert.equal(displayTimezone(), 'America/Toronto');
    assert.doesNotThrow(() => setTimeMode('utc'));
    assert.equal(displayTimezone(), 'UTC');
});

test('formats chart ticks and the crosshair using the selected zone without shifting the input timestamps', () => {
    configureTimeDisplay({ timezone: 'America/Toronto' });
    const times = [Date.parse('2026-11-01T05:30:00Z') / 1000, Date.parse('2026-11-01T06:30:00Z') / 1000];
    const local = chartTimeOptions();
    assert.equal(local.localization.timeFormatter(times[0]), '2026-11-01 01:30 UTC-04:00');
    assert.equal(local.localization.timeFormatter(times[1]), '2026-11-01 01:30 UTC-05:00');
    assert.equal(local.timeScale.tickMarkFormatter(times[0], 3), '01:30');
    setTimeMode('utc');
    const utc = chartTimeOptions();
    assert.equal(utc.localization.timeFormatter(times[0]), '2026-11-01 05:30 UTC');
    assert.equal(utc.timeScale.tickMarkFormatter(times[1], 3), '06:30');
    assert.equal(times[1] - times[0], 3600);
});

test('resolves browser timezone aliases to names supported by the server', () => {
    configureTimeDisplay({ browserTimezone: 'Asia/Calcutta', supportedTimezones: ['UTC', 'Asia/Kolkata'] });
    assert.equal(displayTimezone(), 'Asia/Kolkata');
    assert.equal(formatTimestamp('2026-10-01T00:00:00Z'), '2026-10-01 05:30:00 UTC+05:30');
});

test('resolves local expiry input including fractional offsets and detects skipped or repeated wall times', () => {
    assert.deepEqual(localTimeInstants('2026-10-01T09:00', 'America/Toronto'), [Date.parse('2026-10-01T13:00:00Z')]);
    assert.deepEqual(localTimeInstants('2026-10-01T09:00', 'Asia/Kathmandu'), [Date.parse('2026-10-01T03:15:00Z')]);
    assert.deepEqual(localTimeInstants('2026-10-01T09:00', 'UTC'), [Date.parse('2026-10-01T09:00:00Z')]);
    assert.deepEqual(localTimeInstants('2026-03-08T02:30', 'America/Toronto'), []);
    assert.deepEqual(localTimeInstants('2026-11-01T01:30', 'America/Toronto'), [Date.parse('2026-11-01T05:30:00Z'), Date.parse('2026-11-01T06:30:00Z')]);
});

test('the switch updates existing and AJAX timestamps, input values, accessibility states and other tabs', t => {
    const listeners = new Map();
    const storageData = new Map();
    let mutation, dispose;
    const window = { localStorage: { getItem: key => storageData.get(key), setItem: (key, value) => storageData.set(key, value) },
        addEventListener: (name, listener) => listeners.set(name, listener), removeEventListener: name => listeners.delete(name) };
    const originals = new Map(['window', 'MutationObserver'].map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
    Object.assign(globalThis, { window, MutationObserver: class { constructor(callback) { mutation = callback; } observe() {} disconnect() {} } });
    t.after(() => {
        dispose?.();
        for (const [key, descriptor] of originals) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor); else delete globalThis[key];
        }
    });
    const time = () => ({ nodeType: 1, dataset: { timePrecision: 'minutes' }, textContent: '', getAttribute: () => '2026-10-01T13:00:00Z',
        matches: selector => selector === '[data-display-time]', querySelectorAll: () => [] });
    const first = time(), zoneLabel = { textContent: '' };
    const buttons = ['local', 'utc'].map(mode => ({ dataset: { timeMode: mode }, setAttribute(name, value) { this[name] = value; } }));
    const control = { dataset: { timezone: 'America/Toronto', user: 'fixture' }, querySelectorAll: () => buttons,
        addEventListener: (name, callback) => listeners.set(`control.${name}`, callback), removeEventListener() {} };
    const zoneInput = { value: 'America/Toronto' };
    const input = { value: '2026-10-01T09:00', form: { querySelector: () => zoneInput }, setCustomValidity(message) { this.error = message; }, addEventListener() {}, removeEventListener() {} };
    const root = { querySelector: () => control, querySelectorAll: selector => ({
        '[data-display-time]': [first], '[data-timezone-label]': [zoneLabel], '[data-time-input]': [input],
    })[selector] ?? [] };
    dispose = mountTimeDisplay(root);
    assert.equal(first.textContent, '2026-10-01 09:00 UTC-04:00');
    assert.equal(buttons[0]['aria-pressed'], 'true');
    listeners.get('control.click')({ target: { closest: () => buttons[1] } });
    assert.equal(first.textContent, '2026-10-01 13:00 UTC');
    assert.equal(input.value, '2026-10-01T13:00');
    assert.equal(zoneInput.value, 'UTC');
    assert.equal(zoneLabel.textContent, 'UTC');
    assert.equal(buttons[1]['aria-pressed'], 'true');
    const incoming = time();
    mutation([{ addedNodes: [incoming] }]);
    assert.equal(incoming.textContent, '2026-10-01 13:00 UTC');
    storageData.set('trademinator.time-display.fixture', 'local');
    listeners.get('storage')({ key: 'trademinator.time-display.fixture' });
    assert.equal(first.textContent, '2026-10-01 09:00 UTC-04:00');
    assert.equal(input.value, '2026-10-01T09:00');
    assert.equal(input.error, '');
    dispose();
    assert.equal(listeners.has('storage'), false);
});
