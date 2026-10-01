import { chartTimeOptions, formatTimestamp, subscribeTimeDisplay } from './time-display.js';
export function formatPrice(value, tickSize = 0.00000001) {
    const decimals = Math.min(18, Math.max(0, Math.ceil(-Math.log10(tickSize)) + 2));
    return new Intl.NumberFormat('en', { maximumFractionDigits: decimals }).format(value);
}

export function chartData(evidence) {
    const candles = evidence.series ?? [];
    return {
        candles: candles.map(({ time, open, high, low, close }) => ({ time, open, high, low, close })),
        volume: candles.map(({ time, volume, open, close }) => ({
            time, value: volume, color: close >= open ? '#159b83' : '#d64a5e',
        })),
    };
}

export async function mountReviewChart(root) {
    const canvas = root.querySelector('[data-chart-canvas]');
    const status = root.querySelector('[data-chart-status]');
    const evidenceNotice = root.querySelector('[data-chart-evidence]');
    const freshness = root.querySelector('[data-chart-freshness]');
    const legend = root.querySelector('[data-chart-legend]');
    const refreshButton = root.querySelector('[data-chart-refresh]');
    const fitButton = root.querySelector('[data-chart-fit]');
    const automatic = root.querySelector('[data-chart-auto]');
    const tickSize = Number(root.dataset.tickSize) || 0.00000001;
    let evidence = JSON.parse(root.dataset.evidence);
    let chart, price, volume, library, themeObserver, timer, request, timeout, unsubscribeTime;
    let inspectedCandle, checkedAt, lastTimedStatus;
    let disposed = false;
    let stopped = false;
    let busy = false;
    let plottedPeriod = null;
    let hadCandles = false;

    const theme = () => {
        const dark = document.documentElement.classList.contains('dark');
        return {
            layout: { background: { type: 'solid', color: dark ? '#111827' : '#ffffff' }, textColor: dark ? '#e2e8f0' : '#263850', attributionLogo: true },
            grid: { vertLines: { color: dark ? '#233047' : '#edf1f7' }, horzLines: { color: dark ? '#233047' : '#edf1f7' } },
            rightPriceScale: { borderColor: dark ? '#64748b' : '#aebbc9' },
            timeScale: { borderColor: dark ? '#64748b' : '#aebbc9', timeVisible: true, secondsVisible: false },
        };
    };
    const showCandle = candle => {
        inspectedCandle = candle;
        legend.textContent = candle
            ? `${formatTimestamp(candle.time * 1000, { precision: 'minutes' })} · O ${formatPrice(candle.open, tickSize)} · H ${formatPrice(candle.high, tickSize)} · L ${formatPrice(candle.low, tickSize)} · C ${formatPrice(candle.close, tickSize)} ${root.dataset.quote} · Volume ${formatPrice(candle.volume)}`
            : 'Move over a candle to inspect its open, high, low, close and volume.';
    };
    const draw = () => {
        evidenceNotice.textContent = evidence.message;
        evidenceNotice.classList.toggle('guide-error', evidence.stale || evidence.continuous === false);
        const data = chartData(evidence);
        canvas.hidden = data.candles.length === 0;
        fitButton.disabled = data.candles.length === 0;
        if (data.candles.length && !chart) {
            chart = library.createChart(canvas, { autoSize: true, ...theme() });
            price = chart.addSeries(library.CandlestickSeries, {
                upColor: '#159b83', downColor: '#d64a5e', borderVisible: false,
                wickUpColor: '#159b83', wickDownColor: '#d64a5e',
                priceFormat: { type: 'custom', minMove: tickSize, formatter: value => formatPrice(value, tickSize) },
            });
            volume = chart.addSeries(library.HistogramSeries, { priceFormat: { type: 'volume' }, priceLineVisible: false, lastValueVisible: false }, 1);
            chart.panes()[1].setHeight(90);
            chart.subscribeCrosshairMove(param => {
                const candle = evidence.series.find(item => item.time === param.time);
                showCandle(candle ?? evidence.series.at(-1));
            });
            const renderTime = () => {
                chart.applyOptions(chartTimeOptions());
                showCandle(inspectedCandle ?? evidence.series.at(-1));
                showFreshness();
                if (checkedAt && status.textContent === lastTimedStatus) showChecked();
            };
            unsubscribeTime = subscribeTimeDisplay(renderTime);
            renderTime();
            themeObserver = new MutationObserver(() => chart?.applyOptions(theme()));
            themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        }
        if (chart) {
            const oldRange = chart.timeScale().getVisibleLogicalRange();
            price.setData(data.candles);
            volume.setData(data.volume);
            if (!hadCandles || plottedPeriod !== evidence.period) {
                chart.timeScale().fitContent();
            } else if (oldRange) {
                chart.timeScale().setVisibleLogicalRange(oldRange);
            }
        }
        hadCandles = data.candles.length > 0;
        plottedPeriod = evidence.period;
        showCandle(evidence.series.at(-1));
        showFreshness();
    };
    const showFreshness = () => {
        freshness.textContent = evidence.last_closed_at
            ? `${evidence.candles} closed ${evidence.period} candles · ${formatTimestamp(evidence.from, { precision: 'minutes' })}–${formatTimestamp(evidence.through, { precision: 'minutes' })} · Last candle closed ${formatTimestamp(evidence.last_closed_at)} · ${formatPrice(evidence.age_seconds / 60, 1)} minutes ago${evidence.stale ? ' · STALE HISTORY' : ''}${evidence.continuous === false ? ' · GAPS IN HISTORY; missing intervals are not filled' : ''}`
            : 'No closed candles available yet. Collection may still be pending or inactive.';
    };
    const showChecked = () => {
        status.textContent = `Checked ${formatTimestamp(checkedAt)}. Chart updated from stored data; recalculate the full review to update its explanation.`;
        lastTimedStatus = status.textContent;
    };
    const stopForChangedReview = () => {
        stopped = true;
        clearTimeout(timer);
        refreshButton.disabled = true;
        automatic.checked = false;
        automatic.disabled = true;
        document.querySelectorAll('[data-review-subscribe] button').forEach(button => { button.disabled = true; });
    };
    const schedule = () => {
        clearTimeout(timer);
        if (!disposed && !stopped && automatic.checked && !document.hidden) {
            timer = setTimeout(refresh, 60000);
        }
    };
    async function refresh() {
        if (busy || disposed || stopped) return;
        busy = true;
        refreshButton.disabled = true;
        status.textContent = 'Checking for newly collected closed candles…';
        request = new AbortController();
        timeout = setTimeout(() => request?.abort(), 15000);
        try {
            const response = await fetch(root.dataset.url, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: request.signal,
            });
            if ([401, 403, 404, 409, 419].includes(response.status) || response.redirected) {
                stopForChangedReview();
                throw new Error('The review or your session has changed. Reload the full review before subscribing.');
            }
            if (response.status === 429) throw new Error('Refresh limit reached. Wait a minute before retrying.');
            if (!response.ok) throw new Error('Market data could not be refreshed. Showing the last successful sample.');
            const data = await response.json();
            if (data.review_type !== root.dataset.reviewType) {
                stopForChangedReview();
                throw new Error('The preference assessment has changed. Reload the full review to see the current explanation.');
            }
            if (data.symbol !== root.dataset.symbol || !Array.isArray(data.evidence?.series)) {
                throw new Error('Unexpected chart response. Reload the full review.');
            }
            if (disposed) return;
            evidence = data.evidence;
            draw();
            checkedAt = data.checked_at;
            showChecked();
        } catch (error) {
            if (!disposed) {
                status.textContent = error.name === 'AbortError'
                    ? 'Refresh timed out. Showing the last successful sample; try again shortly.'
                    : error.message;
            }
        } finally {
            clearTimeout(timeout);
            request = null;
            busy = false;
            refreshButton.disabled = stopped || disposed;
            schedule();
        }
    }
    const onVisibility = () => { if (document.hidden) clearTimeout(timer); else schedule(); };
    const fit = () => chart?.timeScale().fitContent();
    const dispose = () => {
        disposed = true;
        clearTimeout(timer);
        clearTimeout(timeout);
        request?.abort();
        unsubscribeTime?.();
        themeObserver?.disconnect();
        chart?.remove();
        chart = null;
        refreshButton.removeEventListener('click', refresh);
        fitButton.removeEventListener('click', fit);
        automatic.removeEventListener('change', schedule);
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('pagehide', onPageHide);
    };
    const onPageHide = event => {
        // A bfcache page resumes with its existing chart and listeners.
        if (event.persisted) clearTimeout(timer); else dispose();
    };
    window.addEventListener('pagehide', onPageHide);
    try {
        library = await import('lightweight-charts');
        if (disposed) return dispose;
        draw();
        refreshButton.disabled = false;
        refreshButton.addEventListener('click', refresh);
        fitButton.addEventListener('click', fit);
        automatic.addEventListener('change', schedule);
        document.addEventListener('visibilitychange', onVisibility);
        status.textContent = 'Showing the stored sample used for this review. Automatic refresh checks every 60 seconds while this tab is visible.';
        schedule();
    } catch {
        dispose();
        canvas.hidden = true;
        automatic.checked = false;
        automatic.disabled = true;
        status.textContent = 'The chart could not load. You can still review the evidence and recent candle values below, and subscribe above.';
    }
    return dispose;
}
