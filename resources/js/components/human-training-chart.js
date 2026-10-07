import { chartTimeOptions, formatTimestamp, subscribeTimeDisplay } from './time-display.js';
import { chartData, formatPrice } from './market-review-chart.js';

export function trainingChartData(snapshot) {
    const series = (snapshot.series ?? []).filter(row => row.time * 1000 < snapshot.decision_at_ms)
        .map(row => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)])));
    const label = snapshot.label;
    const last = series.at(-1);
    const buy = ['bull', 'super_bull'].includes(label);
    const sell = ['bear', 'super_bear'].includes(label);
    const markers = last && (buy || sell || ['neutral', 'hold'].includes(label)) ? [{
        time: last.time, position: buy ? 'belowBar' : 'aboveBar',
        color: buy ? '#087b6b' : sell ? '#c33e50' : '#64748b',
        shape: buy ? 'arrowUp' : sell ? 'arrowDown' : 'circle',
        text: `Human: ${label.replaceAll('_', ' ').toUpperCase()}`,
    }] : [];
    return { ...chartData({ series }), series, markers, assessmentTime: last?.time ?? null };
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
    const legend = root.querySelector('[data-legend]');
    const fit = root.querySelector('[data-fit]');
    let chart, observer, resizeObserver, unsubscribeTime;
    let inspectedCandle;
    const data = trainingChartData(JSON.parse(root.dataset.snapshot));
    const smallestPrice = Math.min(...data.candles.map(candle => candle.low));
    const minMove = Number.isFinite(smallestPrice) && smallestPrice > 0
        ? 10 ** Math.max(-18, Math.min(-2, Math.floor(Math.log10(smallestPrice)) - 5)) : 0.00000001;
    const theme = () => trainingChartTheme(document.documentElement.classList.contains('dark'));
    const hideAssessment = () => {
        if (band) band.hidden = true;
        if (assessmentLabel) assessmentLabel.hidden = true;
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
        const previousTime = data.series.at(-2)?.time;
        const previousX = previousTime === undefined ? null : chart.timeScale().timeToCoordinate(previousTime);
        const fallback = frame.clientWidth / Math.max(1, data.candles.length);
        const spacing = previousX !== null && Number.isFinite(previousX) ? Math.abs(x - previousX) : fallback;
        const width = Math.max(10, Math.min(72, spacing * 0.86));
        band.style.left = `${x - width / 2}px`;
        band.style.width = `${width}px`;
        band.hidden = false;
        assessmentLabel.style.left = `${Math.max(58, Math.min(frame.clientWidth - 58, x))}px`;
        assessmentLabel.hidden = false;
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
        library.createSeriesMarkers(price, data.markers);
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
        status.textContent = `${data.candles.length} historical closed candles. The shaded candle is the decision candle; assess the trend starting immediately after it. Future candles are hidden.`;
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
