import { chartTimeOptions, formatTimestamp, subscribeTimeDisplay } from './time-display.js';
import { chartData, formatPrice } from './market-review-chart.js';

export const OUTCOME_COLORS = {
    super_bear: '#8b1e2d', bear: '#f28b91', neutral: '#aeb4bf',
    bull: '#86cfa0', super_bull: '#176b3b',
};
export function outcomeLine(decision, future, label, source = 'human') {
    if (!decision || !future || !(label in OUTCOME_COLORS)) return null;
    return {
        color: source === 'computer' ? '#2563eb' : OUTCOME_COLORS[label],
        points: [{ time: decision.time, value: decision.close },
            { time: future.time, value: future.close }],
    };
}
export function trainingChartData(snapshot) {
    const series = (snapshot.series ?? []).filter(row => row.time * 1000 < snapshot.decision_at_ms)
        .map(row => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)])));
    const decision = series.at(-1) ?? null;
    const future = snapshot.future_candle
        ? Object.fromEntries(Object.entries(snapshot.future_candle).map(([key, value]) => [key, Number(value)])) : null;
    const forward = (snapshot.future_series ?? []).map(row =>
        Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)])));
    const horizon = Number(snapshot.horizon_candles);
    const validForward = decision && future && Number.isInteger(horizon) && horizon > 0
        && forward.length === horizon && forward.at(-1)?.time === future.time
        && forward.every((row, index) => index === 0 ? row.time > decision.time
            : row.time > forward[index - 1].time);
    const after = (snapshot.after_series ?? []).map(row =>
        Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)])));
    const validAfter = [];
    if (validForward) {
        let previousTime = future.time;
        for (const row of after) {
            if (row.time <= previousTime || !Number.isFinite(row.close)) break;
            validAfter.push(row);
            previousTime = row.time;
        }
    }
    // Preserve the true H-candle separation. Prefer similar context before t
    // and after t+H, bounded by the historical window already in the snapshot.
    const beforeCount = validForward ? Math.min(series.length - 1, Math.max(horizon, validAfter.length)) : series.length - 1;
    const before = validForward ? series.slice(-(beforeCount + 1)) : series;
    const extended = validForward ? [...before, ...forward, ...validAfter] : series;
    return { ...chartData({ series: extended }), series: extended, markers: [],
        assessmentTime: decision?.time ?? null, futureTime: validForward ? future.time : null,
        humanLine: outcomeLine(decision, validForward ? future : null, snapshot.label),
        computerLine: outcomeLine(decision, validForward ? future : null, snapshot.machine_outcome, 'computer') };
}

export function trainingChartTheme(dark = false) {
    return {
        layout: {
            // The frame owns the solid background. Keeping the chart transparent lets the
            // assessment band sit behind the candles instead of washing them out.
            background: { type: 'solid', color: 'transparent' },
            textColor: dark ? '#e5edf5' : '#172c43',
            attributionLogo: true,
        },
        grid: {
            vertLines: { color: dark ? '#263348' : '#edf1f5' },
            horzLines: { color: dark ? '#263348' : '#edf1f5' },
        },
        timeScale: { timeVisible: true, secondsVisible: false },
    };
}

export async function mountHumanTrainingChart(root) {
    const status = root.querySelector('[data-status]');
    const canvas = root.querySelector('[data-canvas]');
    const frame = root.querySelector('[data-chart-frame]');
    const band = root.querySelector('[data-assessment-band]');
    const assessmentLabel = root.querySelector('[data-assessment-label]');
    const futureBand = root.querySelector('[data-future-band]');
    const futureLabel = root.querySelector('[data-future-label]');
    const overlays = [...root.querySelectorAll('[data-outcome-overlay]')];
    let overlay = 'human';
    const legend = root.querySelector('[data-legend]');
    const fit = root.querySelector('[data-fit]');
    let chart, observer, resizeObserver, unsubscribeTime, humanLineSeries, computerLineSeries;
    let inspectedCandle;
    const data = trainingChartData(JSON.parse(root.dataset.snapshot));
    const smallestPrice = Math.min(...data.candles.map(candle => candle.low));
    const minMove = Number.isFinite(smallestPrice) && smallestPrice > 0
        ? 10 ** Math.max(-18, Math.min(-2, Math.floor(Math.log10(smallestPrice)) - 5)) : 0.00000001;
    const theme = () => trainingChartTheme(document.documentElement.classList.contains('dark'));
    const hideAssessment = () => {
        if (band) band.hidden = true;
        if (assessmentLabel) assessmentLabel.hidden = true;
        if (futureBand) futureBand.hidden = true;
        if (futureLabel) futureLabel.hidden = true;
    };
    const positionAssessment = () => {
        if (!chart || !frame || !band || !assessmentLabel || data.assessmentTime === null) {
            hideAssessment();
            return;
        }
        const x = chart.timeScale().timeToCoordinate(data.assessmentTime);
        if (x === null || !Number.isFinite(x)) {
            hideAssessment();
            return;
        }
        const index = data.series.findIndex(row => row.time === data.assessmentTime);
        const previousTime = index > 0 ? data.series[index - 1].time : null;
        const nextTime = index >= 0 ? data.series[index + 1]?.time : null;
        const neighbour = previousTime ?? nextTime;
        const neighbourX = neighbour == null ? null : chart.timeScale().timeToCoordinate(neighbour);
        const fallback = frame.clientWidth / Math.max(1, data.candles.length);
        const spacing = neighbourX !== null && Number.isFinite(neighbourX) ? Math.abs(x - neighbourX) : fallback;
        // A shade is one candle-cell wide, never a forced minimum of 10px.
        const width = Math.max(1, Math.min(72, spacing * 0.96));
        band.style.left = `${x - width / 2}px`;
        band.style.width = `${width}px`;
        band.hidden = false;
        assessmentLabel.style.left = `${Math.max(58, Math.min(frame.clientWidth - 58, x))}px`;
        assessmentLabel.hidden = false;
        if (futureBand && futureLabel && data.futureTime !== null) {
            const futureX = chart.timeScale().timeToCoordinate(data.futureTime);
            if (futureX !== null && Number.isFinite(futureX)) {
                futureBand.style.left = (futureX - width / 2) + 'px';
                futureBand.style.width = width + 'px';
                futureBand.hidden = false;
                futureLabel.style.left = Math.max(20, Math.min(frame.clientWidth - 20, futureX)) + 'px';
                futureLabel.hidden = false;
            }
        }
    };
    const scheduleAssessment = () => requestAnimationFrame(positionAssessment);
    const fitChart = () => {
        chart?.timeScale().fitContent();
        scheduleAssessment();
    };
    try {
        const library = await import('lightweight-charts');
        chart = library.createChart(canvas, { autoSize: true, ...theme() });
        const price = chart.addSeries(library.CandlestickSeries, { upColor: '#159b83', downColor: '#d64a5e', borderVisible: false, wickUpColor: '#159b83', wickDownColor: '#d64a5e',
            priceFormat: { type: 'custom', minMove, formatter: value => formatPrice(value, minMove) } });
        const volume = chart.addSeries(library.HistogramSeries, { priceFormat: { type: 'volume' }, priceLineVisible: false, lastValueVisible: false }, 1);
        price.setData(data.candles);
        volume.setData(data.volume);
        chart.panes()[1].setHeight(70);
        const lineStyle = library.LineStyle?.Dotted ?? 1;
        const lineOptions = { lineWidth: 2, lineStyle, priceLineVisible: false,
            lastValueVisible: false, crosshairMarkerVisible: false, autoscaleInfoProvider: () => null };
        humanLineSeries = chart.addSeries(library.LineSeries, { ...lineOptions,
            color: data.humanLine?.color ?? '#aeb4bf' });
        computerLineSeries = chart.addSeries(library.LineSeries, { ...lineOptions, color: '#2563eb' });
        const renderOverlays = () => {
            humanLineSeries.setData(overlay !== 'computer' ? data.humanLine?.points ?? [] : []);
            computerLineSeries.setData(overlay !== 'human' ? data.computerLine?.points ?? [] : []);
        };
        overlays.forEach(input => input.addEventListener('change', event => {
            overlay = event.target.value;
            renderOverlays();
        }));
        renderOverlays();
        const renderLegend = () => {
            const candle = inspectedCandle ?? data.series.at(-1);
            if (candle) legend.textContent = `${formatTimestamp(candle.time * 1000, { precision: 'minutes' })} · O ${formatPrice(candle.open, minMove)} · H ${formatPrice(candle.high, minMove)} · L ${formatPrice(candle.low, minMove)} · C ${formatPrice(candle.close, minMove)} · Volume ${formatPrice(candle.volume)}`;
        };
        chart.subscribeCrosshairMove(param => {
            inspectedCandle = data.series.find(row => row.time === param.time);
            renderLegend();
        });
        const renderTime = () => { chart.applyOptions(chartTimeOptions()); renderLegend(); };
        unsubscribeTime = subscribeTimeDisplay(renderTime);
        renderTime();
        chart.timeScale().subscribeVisibleLogicalRangeChange(scheduleAssessment);
        resizeObserver = new ResizeObserver(scheduleAssessment);
        resizeObserver.observe(frame);
        observer = new MutationObserver(() => {
            chart.applyOptions(theme());
            scheduleAssessment();
        });
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        fit.addEventListener('click', fitChart);
        fitChart();
        status.textContent = data.futureTime === null ? 'The H-period comparison candle is unavailable.' : 'Yellow = decision; grey = H-period comparison. No recorded five-class machine prediction is available unless its blue line appears.';
        window.addEventListener('pagehide', event => {
            if (event.persisted) return;
            unsubscribeTime?.();
            observer.disconnect();
            resizeObserver.disconnect();
            chart.timeScale().unsubscribeVisibleLogicalRangeChange(scheduleAssessment);
            chart.remove();
            fit.removeEventListener('click', fitChart);
        }, { once: true });
    } catch {
        unsubscribeTime?.();
        observer?.disconnect();
        resizeObserver?.disconnect();
        chart?.remove();
        hideAssessment();
        canvas.hidden = true;
        fit.disabled = true;
        status.textContent = 'The chart could not load. Recent candle values and frozen indicators remain available below.';
    }
}
