/** Read only validation guidance; do not expose arbitrary server-error bodies. */
export async function candleTrainingHistoryError(response) {
    if (response.status === 429 && !response.redirected) {
        return 'History limit reached. Wait a minute, then retry.';
    }

    const fallback = 'History could not load. Retry or reload this page.';
    if (response.redirected || response.status !== 422) return fallback;

    const payload = await response.json().catch(() => null);
    const errors = payload?.errors;
    const fieldMessages = errors && typeof errors === 'object' ? Object.values(errors).flat() : [];

    return [payload?.message, ...fieldMessages]
        .find(message => typeof message === 'string' && message.trim() !== '') ?? fallback;
}
