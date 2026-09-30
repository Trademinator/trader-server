import { chartData, formatPrice } from './market-review-chart.js';

export function trainingChartData(snapshot) {
    const series = (snapshot.series ?? []).filter(row => row.time * 1000 < snapshot.decision_at_ms)
        .map(row => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)])));
    const label = snapshot.label;
    const last = series.at(-1);
    const buy = ['bull', 'super_bull'].includes(label);
    const sell = ['bear', 'super_bear'].includes(label);
    const markers = last && (buy || sell || label === 'hold') ? [{
        time: last.time, position: buy ? 'belowBar' : 'aboveBar',
        color: buy ? '#087b6b' : sell ? '#c33e50' : '#64748b',
        shape: buy ? 'arrowUp' : sell ? 'arrowDown' : 'circle',
        text: `Human: ${label.replaceAll('_', ' ').toUpperCase()}`,
    }] : [];
    return { ...chartData({ series }), series, markers };
}

export async function mountHumanTrainingChart(root) {
    const status = root.querySelector('[data-status]');
    const canvas = root.querySelector('[data-canvas]');
    const legend = root.querySelector('[data-legend]');
    const fit = root.querySelector('[data-fit]');
    let chart, observer;
    const data = trainingChartData(JSON.parse(root.dataset.snapshot));
    const smallestPrice = Math.min(...data.candles.map(candle => candle.low));
    const minMove = Number.isFinite(smallestPrice) && smallestPrice > 0
        ? 10 ** Math.max(-18, Math.min(-2, Math.floor(Math.log10(smallestPrice)) - 5)) : 0.00000001;
    const theme = () => {
        const dark = document.documentElement.classList.contains('dark');
        return { layout: { background: { type: 'solid', color: dark ? '#111827' : '#ffffff' }, textColor: dark ? '#e5edf5' : '#172c43', attributionLogo: true },
            grid: { vertLines: { color: dark ? '#263348' : '#edf1f5' }, horzLines: { color: dark ? '#263348' : '#edf1f5' } },
            timeScale: { timeVisible: true, secondsVisible: false } };
    };
    const fitChart = () => chart?.timeScale().fitContent();
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
        chart.subscribeCrosshairMove(param => {
            const candle = data.series.find(row => row.time === param.time) ?? data.series.at(-1);
            if (candle) legend.textContent = `${new Date(candle.time * 1000).toISOString().slice(0, 16)} UTC · O ${formatPrice(candle.open, minMove)} · H ${formatPrice(candle.high, minMove)} · L ${formatPrice(candle.low, minMove)} · C ${formatPrice(candle.close, minMove)} · Volume ${formatPrice(candle.volume)}`;
        });
        observer = new MutationObserver(() => chart.applyOptions(theme()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        fit.addEventListener('click', fitChart);
        fitChart();
        status.textContent = `${data.candles.length} historical closed candles. Future candles are hidden; automatic refresh is disabled.`;
        window.addEventListener('pagehide', event => {
            if (event.persisted) return;
            observer.disconnect();
            chart.remove();
            fit.removeEventListener('click', fitChart);
        }, { once: true });
    } catch {
        observer?.disconnect();
        chart?.remove();
        canvas.hidden = true;
        fit.disabled = true;
        status.textContent = 'The chart could not load. Recent candle values and frozen indicators remain available below.';
    }
}
