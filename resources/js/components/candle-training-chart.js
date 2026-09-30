import { chartData, formatPrice } from './market-review-chart.js';

const ACTIONS = ['buy', 'hold', 'sell'];

function actionMarker(time, action) {
    return {
        id: `human-${time}`,
        time: Number(time),
        position: action === 'buy' ? 'belowBar' : 'aboveBar',
        color: action === 'buy' ? '#087b6b' : action === 'sell' ? '#c33e50' : '#64748b',
        shape: action === 'buy' ? 'arrowUp' : action === 'sell' ? 'arrowDown' : 'circle',
        text: action.toUpperCase(),
        size: 1,
    };
}

function selectionMarker(time, label, position) {
    return {
        id: `measure-${label}-${time}`,
        time: Number(time),
        position,
        color: label === 'A' ? '#7c3aed' : '#ea580c',
        shape: 'square',
        text: label,
        size: 1.2,
    };
}

export function nextCandleSelection(current, time) {
    const value = Number(time);
    if (!Number.isFinite(value)) return [...current];
    if (current.length === 0) return [value];
    if (current.length === 1) return [current[0], value];
    return [current[1], value];
}

export function candleTrainingMove(first, second, takerFee = null) {
    if (!first || !second) return null;
    const from = Number(first.close);
    const to = Number(second.close);
    if (!Number.isFinite(from) || !Number.isFinite(to) || from <= 0) return null;
    const percent = ((to - from) / from) * 100;
    const magnitude = Math.abs(percent);
    const fee = takerFee === null || takerFee === '' ? null : Number(takerFee);
    const validFee = Number.isFinite(fee) && fee >= 0 ? fee : null;
    const perSidePercent = validFee === null ? null : validFee * 100;
    const roundTripPercent = validFee === null ? null : validFee * 200;
    let comparison = null;
    if (roundTripPercent !== null) {
        const delta = magnitude - roundTripPercent;
        comparison = Math.abs(delta) < 1e-12 ? 'equal' : delta > 0 ? 'greater' : 'less';
    }

    return { percent, magnitude, perSidePercent, roundTripPercent, comparison };
}

export function candleTrainingChartData(snapshot) {
    const series = (snapshot.series ?? []).filter(row => row.time * 1000 < snapshot.decision_at_ms)
        .map(row => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)])));
    const labels = (snapshot.labels ?? []).filter(label => ACTIONS.includes(label.action));
    const markers = labels.map(label => actionMarker(label.time, label.action))
        .filter(marker => series.some(candle => candle.time === marker.time));

    return { ...chartData({ series }), series, labels, markers, decisions: snapshot.decisions ?? {},
        selectedAction: snapshot.selected_action ?? null, stats: snapshot.stats ?? null,
        takerFee: snapshot.taker_fee ?? null, decisionAtMs: Number(snapshot.decision_at_ms) };
}

export async function mountCandleTrainingChart(root) {
    const status = root.querySelector('[data-status]');
    const canvas = root.querySelector('[data-canvas]');
    const legend = root.querySelector('[data-legend]');
    const fit = root.querySelector('[data-fit]');
    const menu = root.querySelector('[data-candle-menu]');
    let chart, observer, markerPlugin;
    const data = candleTrainingChartData(JSON.parse(root.dataset.snapshot));
    const labels = new Map(data.labels.map(label => [Number(label.time), label.action]));
    const candles = new Map(data.series.map(candle => [Number(candle.time), candle]));
    let selection = [];
    let menuTime = null;
    let suppressNextClick = false;
    let longPressTimer = null;
    let longPressStart = null;
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
    const sortedMarkers = () => {
        const markers = [...labels.entries()].map(([time, action]) => actionMarker(time, action));
        if (selection[0] !== undefined) markers.push(selectionMarker(selection[0], 'A', 'aboveBar'));
        if (selection[1] !== undefined) markers.push(selectionMarker(selection[1], 'B', 'belowBar'));
        return markers.filter(marker => candles.has(Number(marker.time)))
            .sort((a, b) => Number(a.time) - Number(b.time) || String(a.id).localeCompare(String(b.id)));
    };
    const renderMarkers = () => markerPlugin?.setMarkers(sortedMarkers());
    const formatTime = time => new Date(Number(time) * 1000).toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
    const renderMeasurement = () => {
        const first = selection[0] === undefined ? null : candles.get(selection[0]);
        const second = selection[1] === undefined ? null : candles.get(selection[1]);
        const a = root.querySelector('[data-measure-a]');
        const b = root.querySelector('[data-measure-b]');
        const move = root.querySelector('[data-measure-move]');
        const fee = root.querySelector('[data-measure-fee]');
        a.textContent = first ? `${formatTime(first.time)} · close ${formatPrice(first.close, minMove)}` : 'Click a candle';
        b.textContent = second ? `${formatTime(second.time)} · close ${formatPrice(second.close, minMove)}` : 'Click a second candle';
        const result = candleTrainingMove(first, second, data.takerFee);
        if (!result) {
            move.textContent = '—';
            fee.textContent = data.takerFee === null
                ? 'Published exchange taker fee unavailable.'
                : `Published taker fee ${(Number(data.takerFee) * 100).toFixed(3)}% per side.`;
            return;
        }
        const sign = result.percent > 0 ? '+' : '';
        move.textContent = `${sign}${result.percent.toFixed(3)}% close-to-close`;
        if (result.roundTripPercent === null) {
            fee.textContent = 'Published exchange taker fee unavailable; fee comparison cannot be made.';
            return;
        }
        const relation = result.comparison === 'greater' ? 'greater than' : result.comparison === 'less' ? 'less than' : 'equal to';
        fee.textContent = `Move magnitude ${result.magnitude.toFixed(3)}% is ${relation} the approximate ${result.roundTripPercent.toFixed(3)}% two-taker-trade fee (${result.perSidePercent.toFixed(3)}% per side). Spread, slippage and account discounts are not included.`;
    };
    const renderStats = () => {
        if (!data.stats) return;
        let total = 0;
        for (const action of ACTIONS) total += Number(data.stats.counts?.[action] ?? 0);
        data.stats.total = total;
        for (const action of ACTIONS) {
            const count = Number(data.stats.counts?.[action] ?? 0);
            const percent = total === 0 ? 0 : count * 100 / total;
            const countNode = root.querySelector(`[data-stat-count="${action}"]`);
            const percentNode = root.querySelector(`[data-stat-percent="${action}"]`);
            const progress = root.querySelector(`[data-stat-progress="${action}"]`);
            if (countNode) countNode.textContent = String(count);
            if (percentNode) percentNode.textContent = `${percent.toFixed(1)}%`;
            if (progress) { progress.max = Math.max(1, total); progress.value = count; }
        }
        const minimum = Math.min(...ACTIONS.map(action => Number(data.stats.counts?.[action] ?? 0)));
        const least = ACTIONS.filter(action => Number(data.stats.counts?.[action] ?? 0) === minimum).map(action => action.toUpperCase());
        const balanced = root.querySelector('[data-balanced-samples]');
        const hint = root.querySelector('[data-balance-hint]');
        const totalNode = root.querySelector('[data-stat-total]');
        if (balanced) balanced.textContent = String(minimum * ACTIONS.length);
        if (totalNode) totalNode.textContent = String(total);
        if (hint) hint.textContent = total === 0 ? 'No labels yet.' : `Least represented: ${least.join(', ')}. Model training uses equal counts from all three classes; do not force a label just to balance the totals.`;
    };
    const adjustStats = (oldAction, newAction) => {
        if (!data.stats || oldAction === newAction) return;
        if (oldAction && ACTIONS.includes(oldAction)) data.stats.counts[oldAction] = Math.max(0, Number(data.stats.counts[oldAction] ?? 0) - 1);
        if (newAction && ACTIONS.includes(newAction)) data.stats.counts[newAction] = Number(data.stats.counts[newAction] ?? 0) + 1;
        renderStats();
    };
    const selectForMeasurement = time => {
        if (!candles.has(Number(time))) return;
        selection = nextCandleSelection(selection, Number(time));
        renderMarkers();
        renderMeasurement();
    };
    const nearestCandleTime = clientX => {
        if (!chart) return null;
        const rect = canvas.getBoundingClientRect();
        const x = clientX - rect.left;
        const direct = chart.timeScale().coordinateToTime(x);
        if (typeof direct === 'number' && candles.has(Number(direct))) return Number(direct);
        let best = null;
        let distance = Infinity;
        for (const time of candles.keys()) {
            const coordinate = chart.timeScale().timeToCoordinate(time);
            if (coordinate === null) continue;
            const candidate = Math.abs(coordinate - x);
            if (candidate < distance) { distance = candidate; best = time; }
        }
        return distance <= 16 ? best : null;
    };
    const closeMenu = () => {
        if (!menu) return;
        menu.hidden = true;
        menuTime = null;
    };
    const openMenu = (time, clientX, clientY) => {
        const decision = data.decisions[String(time)];
        if (!decision) {
            status.textContent = 'This candle is visible for context but has no immutable training row in this dataset.';
            closeMenu();
            return;
        }
        menuTime = Number(time);
        const current = labels.get(menuTime) ?? null;
        menu.querySelector('[data-menu-title]').textContent = `${formatTime(menuTime)}${current ? ` · ${current.toUpperCase()}` : ' · unlabelled'}`;
        const remove = menu.querySelector('[data-menu-action="delete"]');
        if (remove) remove.hidden = current === null;
        menu.hidden = false;
        menu.style.left = `${Math.max(8, clientX)}px`;
        menu.style.top = `${Math.max(8, clientY)}px`;
        requestAnimationFrame(() => {
            const rect = menu.getBoundingClientRect();
            menu.style.left = `${Math.max(8, Math.min(clientX, window.innerWidth - rect.width - 8))}px`;
            menu.style.top = `${Math.max(8, Math.min(clientY, window.innerHeight - rect.height - 8))}px`;
        });
    };
    const requestLabel = async action => {
        if (menuTime === null || !data.decisions[String(menuTime)]) return;
        const decision = Number(data.decisions[String(menuTime)]);
        const deleting = action === 'delete';
        const oldAction = labels.get(menuTime) ?? null;
        const buttons = [...menu.querySelectorAll('button')];
        buttons.forEach(button => { button.disabled = true; });
        status.textContent = deleting ? 'Removing human label…' : `Saving ${action.toUpperCase()}…`;
        try {
            const response = await fetch(deleting ? root.dataset.deleteUrl : root.dataset.updateUrl, {
                method: deleting ? 'DELETE' : 'PUT',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf },
                body: JSON.stringify(deleting ? { decision_at_ms: decision } : { decision_at_ms: decision, action }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const message = payload.message ?? Object.values(payload.errors ?? {}).flat()[0] ?? `Request failed (${response.status}).`;
                throw new Error(message);
            }
            if (deleting) labels.delete(menuTime);
            else labels.set(menuTime, action);
            adjustStats(oldAction, deleting ? null : action);
            renderMarkers();
            if (decision === data.decisionAtMs) {
                root.querySelectorAll('[data-current-action]').forEach(button => {
                    button.setAttribute('aria-pressed', !deleting && button.value === action ? 'true' : 'false');
                });
                const copy = root.querySelector('[data-current-label-copy]');
                if (copy) copy.textContent = deleting ? 'This candle is currently unlabelled.' : `Current label: ${action.toUpperCase()}. Choose another action to change it.`;
            }
            status.textContent = payload.message ?? (deleting ? 'Candle label removed.' : `Candle marked ${action.toUpperCase()}.`);
            closeMenu();
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'The human label could not be saved.';
        } finally {
            buttons.forEach(button => { button.disabled = false; });
        }
    };
    const onContextMenu = event => {
        const time = nearestCandleTime(event.clientX);
        if (time === null) return;
        event.preventDefault();
        openMenu(time, event.clientX, event.clientY);
    };
    const cancelLongPress = () => {
        if (longPressTimer !== null) clearTimeout(longPressTimer);
        longPressTimer = null;
        longPressStart = null;
    };
    const onPointerDown = event => {
        if (event.pointerType !== 'touch') return;
        longPressStart = { x: event.clientX, y: event.clientY };
        longPressTimer = window.setTimeout(() => {
            const time = nearestCandleTime(event.clientX);
            if (time !== null) {
                suppressNextClick = true;
                openMenu(time, event.clientX, event.clientY);
            }
            cancelLongPress();
        }, 600);
    };
    const onPointerMove = event => {
        if (!longPressStart) return;
        if (Math.hypot(event.clientX - longPressStart.x, event.clientY - longPressStart.y) > 10) cancelLongPress();
    };
    const onDocumentPointerDown = event => {
        if (menu && !menu.hidden && !menu.contains(event.target)) closeMenu();
    };
    const onKeyDown = event => { if (event.key === 'Escape') closeMenu(); };
    try {
        const library = await import('lightweight-charts');
        chart = library.createChart(canvas, { autoSize: true, ...theme() });
        const price = chart.addSeries(library.CandlestickSeries, { upColor: '#159b83', downColor: '#d64a5e', borderVisible: false, wickUpColor: '#159b83', wickDownColor: '#d64a5e',
            priceFormat: { type: 'custom', minMove, formatter: value => formatPrice(value, minMove) } });
        const volume = chart.addSeries(library.HistogramSeries, { priceFormat: { type: 'volume' }, priceLineVisible: false, lastValueVisible: false }, 1);
        price.setData(data.candles);
        volume.setData(data.volume);
        chart.panes()[1].setHeight(70);
        markerPlugin = library.createSeriesMarkers(price, sortedMarkers());
        chart.subscribeCrosshairMove(param => {
            const candle = data.series.find(row => row.time === param.time) ?? data.series.at(-1);
            if (candle) legend.textContent = `${new Date(candle.time * 1000).toISOString().slice(0, 16)} UTC · O ${formatPrice(candle.open, minMove)} · H ${formatPrice(candle.high, minMove)} · L ${formatPrice(candle.low, minMove)} · C ${formatPrice(candle.close, minMove)} · Volume ${formatPrice(candle.volume)}`;
        });
        const clickHandler = param => {
            if (suppressNextClick) { suppressNextClick = false; return; }
            if (param.time === undefined || param.time === null) return;
            selectForMeasurement(Number(param.time));
        };
        chart.subscribeClick(clickHandler);
        observer = new MutationObserver(() => chart.applyOptions(theme()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        fit.addEventListener('click', fitChart);
        canvas.addEventListener('contextmenu', onContextMenu);
        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', cancelLongPress);
        canvas.addEventListener('pointercancel', cancelLongPress);
        document.addEventListener('pointerdown', onDocumentPointerDown);
        document.addEventListener('keydown', onKeyDown);
        menu?.querySelectorAll('[data-menu-action]').forEach(button => button.addEventListener('click', () => requestLabel(button.dataset.menuAction)));
        fitChart();
        renderMeasurement();
        renderStats();
        status.textContent = `${data.candles.length} historical closed candles. Left-click selects A/B measurements; right-click a candle for BUY/HOLD/SELL/Delete. Long-press opens the same menu on touch devices.`;
        window.addEventListener('pagehide', event => {
            if (event.persisted) return;
            observer.disconnect();
            chart.unsubscribeClick(clickHandler);
            chart.remove();
            fit.removeEventListener('click', fitChart);
            canvas.removeEventListener('contextmenu', onContextMenu);
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerup', cancelLongPress);
            canvas.removeEventListener('pointercancel', cancelLongPress);
            document.removeEventListener('pointerdown', onDocumentPointerDown);
            document.removeEventListener('keydown', onKeyDown);
            cancelLongPress();
        }, { once: true });
    } catch {
        observer?.disconnect();
        chart?.remove();
        canvas.hidden = true;
        fit.disabled = true;
        renderStats();
        renderMeasurement();
        status.textContent = 'The chart could not load. Replay navigation, candle values, indicators and action buttons remain available.';
    }
}
