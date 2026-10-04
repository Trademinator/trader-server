import assert from 'node:assert/strict';
import { test } from 'node:test';
import { candleTrainingHistoryError } from '../../resources/js/components/candle-training-history-error.js';

const fallback = 'History could not load. Retry or reload this page.';
const response = (payload, status = 422, redirected = false) => ({
    status, redirected, json: async () => payload,
});

test('preserves the owner recovery message and command line breaks', async () => {
    const message = "This history no longer matches the frozen dataset.\n\n"
        + "php artisan trademinator:build-features 'kraken' 'BTC/USD' '1m' &&\n"
        + "php artisan trademinator:knn-build 'kraken' 'BTC/USD' '1m' --schema='technical'";
    assert.equal(await candleTrainingHistoryError(response({ message, errors: { after_ms: [message] } })), message);
});

test('displays contact-the-owner guidance unchanged', async () => {
    const message = 'This history no longer matches the frozen dataset. Choose another dataset or contact the server owner to rebuild it.';
    assert.equal(await candleTrainingHistoryError(response({ message })), message);
});

test('can use a field validation error when message is missing or blank', async () => {
    for (const message of [undefined, '', '   ', 422, null]) {
        assert.equal(await candleTrainingHistoryError(response({ message, errors: { before_ms: ['Contact the server owner.'] } })),
            'Contact the server owner.');
    }
});

test('retains the existing throttle guidance', async () => {
    assert.equal(await candleTrainingHistoryError(response({}, 429)),
        'History limit reached. Wait a minute, then retry.');
});

test('does not parse redirects or expose non-validation error bodies', async () => {
    for (const [status, redirected] of [[200, true], [422, true], [429, true], [401, false], [403, false], [500, false]]) {
        assert.equal(await candleTrainingHistoryError({ status, redirected,
            json: async () => { assert.fail('This response body must not be parsed.'); } }), fallback);
    }
});

test('keeps the fallback for malformed JSON', async () => {
    assert.equal(await candleTrainingHistoryError({ status: 422, redirected: false,
        json: async () => { throw new SyntaxError('HTML, not JSON'); } }), fallback);
});

test('keeps the fallback for empty or malformed validation payloads', async () => {
    for (const payload of [null, [], '', {}, { message: 422 }, { message: ' ', errors: {} },
        { errors: 'not a message bag' }, { errors: { after_ms: [null, 0, {}] } }]) {
        assert.equal(await candleTrainingHistoryError(response(payload)), fallback);
    }
});
