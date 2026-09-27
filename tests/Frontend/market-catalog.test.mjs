import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const view = readFileSync(new URL('../../resources/views/markets/index.blade.php', import.meta.url), 'utf8');
const script = view.match(/<script>([\s\S]*?)<\/script>/)[1];

async function renderResponse(response) {
    const elements = new Map();
    class Option {
        constructor(label, value) { this.textContent = label; this.value = value; this.dataset = {}; }
    }
    for (const id of ['exchange', 'symbol', 'tick-size', 'subscribe', 'options-status']) {
        elements.set(`market-${id}`, {
            value: '', textContent: '', disabled: false, options: [], dataset: {},
            classList: { add() {}, remove() {}, toggle() {} },
            get selectedOptions() { return this.options.filter(option => option.value === this.value); },
            replaceChildren(...options) { this.options = options; },
            add(option) { this.options.push(option); },
            addEventListener() {},
        });
    }
    elements.get('market-exchange').value = 'binance';
    elements.get('market-exchange').dataset.optionsUrl = '/markets/options/__EXCHANGE__';
    runInNewContext(script, {
        document: { querySelectorAll: () => [], getElementById: id => elements.get(id) },
        Option, AbortController, fetch: async () => response,
    });
    await new Promise(resolve => setImmediate(resolve));
    return elements;
}

test('displays the server explanation and reference instead of hiding a failed catalogue as empty', async () => {
    const elements = await renderResponse({ ok: false, status: 422,
        json: async () => ({ message: 'API credentials are required.', reference: 'ref-123' }) });
    assert.equal(elements.get('market-options-status').textContent, 'API credentials are required. Reference: ref-123');
    assert.equal(elements.get('market-symbol').options[0].textContent, 'Could not load pairs');
    assert.equal(elements.get('market-subscribe').disabled, true);
});

test('handles a fatal server error with a non-JSON body', async () => {
    const elements = await renderResponse({ ok: false, status: 500, json: async () => { throw new SyntaxError('HTML'); } });
    assert.match(elements.get('market-options-status').textContent, /server log and PHP memory limit/);
    assert.equal(elements.get('market-symbol').disabled, true);
});

test('distinguishes a successfully loaded empty list from a failed request', async () => {
    const elements = await renderResponse({ ok: true, status: 200,
        json: async () => ({ symbols: [], periods: [{ value: '1m', label: '1 minute' }] }) });
    assert.match(elements.get('market-options-status').textContent, /responded successfully/);
    assert.equal(elements.get('market-symbol').options[0].textContent, 'No spot pairs available');
});

test('populates valid pairs and preserves the server price increment', async () => {
    const elements = await renderResponse({ ok: true, status: 200,
        json: async () => ({ symbols: [{ value: 'BTC/USDT', tick_size: '0.01' }], periods: [{ value: '1m', label: '1 minute' }] }) });
    assert.equal(elements.get('market-symbol').disabled, false);
    assert.equal(elements.get('market-symbol').options[1].value, 'BTC/USDT');
    assert.equal(elements.get('market-symbol').options[1].dataset.tickSize, '0.01');
});
