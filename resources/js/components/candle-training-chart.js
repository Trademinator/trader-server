import { chartTimeOptions, formatTimestamp, subscribeTimeDisplay } from './time-display.js';
import { chartData, formatPrice } from './market-review-chart.js';
import { historyPanDirection, mergeCandleHistory } from './candlestick-history.js';
import { candleTrainingHistoryError } from './candle-training-history-error.js';

const ACTIONS = ['buy', 'hold', 'sell'];
const LABEL_MILESTONE_TARGET = 750;

export function candleTrainingMilestone(count) {
    const value = Number(count);
    if (!Number.isFinite(value) || value < 100) return 'red';
    if (value < 300) return 'orange';
    if (value < LABEL_MILESTONE_TARGET) return 'green';
    return 'blue';
}

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

export function candleTimeAtLogicalIndex(series, logical) {
    if (typeof logical !== 'number' || !Number.isFinite(logical)) return null;
    const index = Math.round(logical);
    if (index < 0 || index >= series.length) return null;
    const time = Number(series[index]?.time);
    return Number.isFinite(time) ? time : null;
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

export function candleActionAllowed(candle, action, allowedActions = null) {
    if (!candle || !ACTIONS.includes(action)) return false;
    if (Array.isArray(allowedActions)) return allowedActions.includes(action);
    const open = Number(candle.open), close = Number(candle.close);
    if (!Number.isFinite(open) || !Number.isFinite(close)) return false;
    return action === 'hold' || (action === 'buy' && open > close) || (action === 'sell' && open < close);
}

export function candleMeasurementLine(first, second) {
    const move = candleTrainingMove(first, second);
    if (!move) return { points: [], color: '#64748b', direction: 'flat', text: '—' };
    const points = [...new Map([first, second].map(row => [Number(row.time), { time: Number(row.time), value: Number(row.close) }])).values()]
        .sort((a, b) => a.time - b.time);
    return { points, color: move.percent > 0 ? '#087b6b' : move.percent < 0 ? '#c33e50' : '#64748b',
        direction: move.percent > 0 ? 'up' : move.percent < 0 ? 'down' : 'flat',
        text: `${move.percent > 0 ? '+' : ''}${move.percent.toFixed(3)}%` };
}

export function prependCandleHistory(current, incoming, decisionAtMs) {
    return mergeCandleHistory(current, incoming, 'older', decisionAtMs);
}

export function appendCandleHistory(current, incoming, latestDecisionAtMs) {
    return mergeCandleHistory(current, incoming, 'newer', latestDecisionAtMs);
}

export function candleTrainingChartData(snapshot) {
    const series = (snapshot.series ?? []).filter(row => row.time * 1000 < snapshot.decision_at_ms)
        .map(row => Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)])));
    const labels = (snapshot.labels ?? []).filter(label => ACTIONS.includes(label.action));
    const markers = labels.map(label => actionMarker(label.time, label.action))
        .filter(marker => series.some(candle => candle.time === marker.time));

    return { ...chartData({ series }), series, labels, markers, decisions: snapshot.decisions ?? {},
        selectedAction: snapshot.selected_action ?? null, stats: snapshot.stats ?? null,
        allowedActions: snapshot.allowed_actions ?? {}, hasMore: snapshot.has_more === true,
        hasNewer: snapshot.has_newer === true, latestDecisionAtMs: Number(snapshot.latest_decision_at_ms ?? snapshot.decision_at_ms),
        takerFee: snapshot.taker_fee ?? null, decisionAtMs: Number(snapshot.decision_at_ms) };
}

export async function mountCandleTrainingChart(root, loadLibrary = () => import('lightweight-charts')) {
    const status = root.querySelector('[data-status]');
    const canvas = root.querySelector('[data-canvas]');
    const legend = root.querySelector('[data-legend]');
    const fit = root.querySelector('[data-fit]');
    const autoLabel = root.querySelector('[data-auto-label]');
    const deleteAllTraining = root.querySelector('[data-delete-all-training]');
    const submitLabels = root.querySelector('[data-submit-labels]');
    const pendingStatus = root.querySelector('[data-pending-status]');
    const menu = root.querySelector('[data-candle-menu]');
    const historyStatus = root.querySelector('[data-history-status]');
    const historyRetry = root.querySelector('[data-history-retry]');
    const tooltip = root.querySelector('[data-measure-tooltip]');
    const datasetSelect = root.querySelector('[data-candle-dataset]');
    let switchDataset;
    let chart, price, volume, measureLine, observer, markerPlugin, historyTimer, historyRequest, unsubscribeTime;
    let inspectedCandle;
    let loadingHistory = false, historyFailed = false, disposed = false, submittingLabels = false, autoLabelling = false;
    let autoLabelOffset = 0;
    let userInteracted = false;
    let lastVisibleRange = null, retryDirection = 'older';
    const data = candleTrainingChartData(JSON.parse(root.dataset.snapshot));
    const labels = new Map(data.labels.map(label => [Number(label.time), label.action]));
    const baselineLabels = new Map(labels);
    const stagedChanges = new Map();
    let deleteAllPending = false;
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
    const fitChart = () => {
        userInteracted = false;
        clearTimeout(historyTimer);
        chart?.timeScale().fitContent();
        lastVisibleRange = chart?.timeScale().getVisibleLogicalRange() ?? null;
    };
    const sortedMarkers = () => {
        const markers = [...labels.entries()].map(([time, action]) => actionMarker(time, action));
        if (selection[0] !== undefined) markers.push(selectionMarker(selection[0], 'A', 'aboveBar'));
        if (selection[1] !== undefined) markers.push(selectionMarker(selection[1], 'B', 'belowBar'));
        return markers.filter(marker => candles.has(Number(marker.time)))
            .sort((a, b) => Number(a.time) - Number(b.time) || String(a.id).localeCompare(String(b.id)));
    };
    const renderMarkers = () => markerPlugin?.setMarkers(sortedMarkers());
    const formatTime = time => formatTimestamp(Number(time) * 1000, { precision: 'minutes' });
    const renderMeasurement = () => {
        const first = selection[0] === undefined ? null : candles.get(selection[0]);
        const second = selection[1] === undefined ? null : candles.get(selection[1]);
        const a = root.querySelector('[data-measure-a]');
        const b = root.querySelector('[data-measure-b]');
        const move = root.querySelector('[data-measure-move]');
        const fee = root.querySelector('[data-measure-fee]');
        a.textContent = first ? `${formatTime(first.time)} · close ${formatPrice(first.close, minMove)}` : 'Click a candle';
        b.textContent = second ? `${formatTime(second.time)} · close ${formatPrice(second.close, minMove)}` : 'Click a second candle';
        const line = candleMeasurementLine(first, second);
        move.textContent = line.text;
        move.dataset.measureDirection = line.direction;
        if (measureLine) {
            measureLine.applyOptions({ color: line.color });
            measureLine.setData(line.points);
        }
        if (tooltip) tooltip.hidden = true;
        const result = candleTrainingMove(first, second, data.takerFee);
        if (!result) {
            fee.textContent = data.takerFee === null
                ? 'Published exchange taker fee unavailable.'
                : `Published taker fee ${(Number(data.takerFee) * 100).toFixed(3)}% per side.`;
            return;
        }
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
            const countNode = root.querySelector(`[data-stat-count="${action}"]`);
            const progress = root.querySelector(`[data-stat-progress="${action}"]`);
            if (countNode) countNode.textContent = String(count);
            if (progress) {
                const milestone = candleTrainingMilestone(count);
                progress.max = LABEL_MILESTONE_TARGET;
                progress.value = Math.min(Math.max(0, count), LABEL_MILESTONE_TARGET);
                progress.dataset.milestone = milestone;
                progress.title = `${count} labels · ${milestone.toUpperCase()} milestone`;
                progress.setAttribute('aria-label', `${action.toUpperCase()} label milestone progress: ${count} labels`);
            }
        }
        const totalNode = root.querySelector('[data-stat-total]');
        if (totalNode) totalNode.textContent = String(total);
    };
    const isDirty = () => deleteAllPending || stagedChanges.size > 0;
    const renderPending = () => {
        const dirty = isDirty();
        if (submitLabels) submitLabels.hidden = !dirty;
        if (pendingStatus) pendingStatus.textContent = dirty
            ? `${stagedChanges.size} staged candle change${stagedChanges.size === 1 ? '' : 's'}${deleteAllPending ? ' · all previously saved labels will be deleted' : ''}. Staged changes are not saved until Submit.`
            : 'No pending changes. Manual labels, deletions and auto-label suggestions stay only in this browser until Submit.';
    };
    const stageLabel = (time, decision, action) => {
        const labelTime = Number(time);
        const baseline = deleteAllPending ? null : (baselineLabels.get(labelTime) ?? null);
        if (action === null) labels.delete(labelTime);
        else labels.set(labelTime, action);
        if (action === baseline) stagedChanges.delete(Number(decision));
        else stagedChanges.set(Number(decision), { decision_at_ms: Number(decision), action });
        renderMarkers();
        renderPending();
    };
    switchDataset = () => {
        if (isDirty() && typeof window.confirm === 'function'
            && !window.confirm('Discard the unsubmitted Action Training changes and switch dataset?')) return;
        root.querySelector('[data-candle-dataset-form]')?.requestSubmit();
    };
    datasetSelect?.addEventListener('change', switchDataset);
    const renderMeasurementTooltip = param => {
        if (!tooltip) return;
        tooltip.hidden = true;
        if (!param.point || param.paneIndex !== 0 || selection.length < 2) return;
        const first = candles.get(selection[0]), second = candles.get(selection[1]);
        if (!first || !second) return;
        const x1 = chart.timeScale().timeToCoordinate(first.time), y1 = price.priceToCoordinate(first.close);
        const x2 = chart.timeScale().timeToCoordinate(second.time), y2 = price.priceToCoordinate(second.close);
        if ([x1, x2, y1, y2].some(value => value === null)) return;
        const dx = x2 - x1, dy = y2 - y1;
        const lengthSquared = dx * dx + dy * dy;
        const fraction = lengthSquared === 0 ? 0 : Math.max(0, Math.min(1,
            ((param.point.x - x1) * dx + (param.point.y - y1) * dy) / lengthSquared));
        if (Math.hypot(param.point.x - x1 - fraction * dx, param.point.y - y1 - fraction * dy) > 12) return;
        tooltip.textContent = `A → B: ${candleMeasurementLine(first, second).text}`;
        tooltip.hidden = false;
        tooltip.style.left = `${Math.max(0, Math.min(param.point.x + 12, canvas.clientWidth - tooltip.offsetWidth))}px`;
        tooltip.style.top = `${Math.max(0, param.point.y + canvas.offsetTop - tooltip.offsetHeight - 8)}px`;
    };
    const updateReplayNavigation = page => {
        const replayTime = root.querySelector('[data-replay-time]');
        if (replayTime) replayTime.textContent = formatTime(data.series.at(-1).time);
        for (const direction of ['previous', 'next']) {
            const link = root.querySelector(`[data-step-${direction}]`);
            if (!link) continue;
            const decision = page[`${direction}_decision_at_ms`];
            if (decision) {
                const url = new URL(root.dataset.replayUrl, window.location.href);
                url.searchParams.set('decision_at_ms', String(decision));
                link.href = url.toString();
                link.removeAttribute('aria-disabled');
                link.removeAttribute('tabindex');
            } else {
                link.removeAttribute('href');
                link.setAttribute('aria-disabled', 'true');
                link.setAttribute('tabindex', '-1');
            }
        }
    };
    const loadHistory = async (direction = 'older') => {
        const newer = direction === 'newer';
        if (loadingHistory || disposed || !(newer ? data.hasNewer : data.hasMore) || !chart) return;
        clearTimeout(historyTimer);
        loadingHistory = true;
        historyFailed = false;
        retryDirection = direction;
        historyRetry.hidden = true;
        historyStatus.textContent = `Loading ${direction} candles and your saved labels…`;
        historyRequest = new AbortController();
        const timeout = setTimeout(() => historyRequest?.abort(), 15000);
        try {
            const url = new URL(root.dataset.historyUrl, window.location.href);
            url.searchParams.set('decision_at_ms', String(data.decisionAtMs));
            url.searchParams.set(newer ? 'after_ms' : 'before_ms', String((newer ? data.series.at(-1) : data.series[0]).time * 1000));
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json' }, signal: historyRequest.signal });
            if (!response.ok || response.redirected) {
                throw new Error(await candleTrainingHistoryError(response));
            }
            const page = await response.json();
            if (!Array.isArray(page.series) || !Array.isArray(page.labels)
                || (newer && (!Number.isSafeInteger(page.decision_at_ms) || page.decision_at_ms > data.latestDecisionAtMs || page.decision_at_ms < data.decisionAtMs))) {
                throw new Error('Unexpected history response. Reload this page.');
            }
            if (disposed) return;
            const merged = newer ? appendCandleHistory(data.series, page.series, page.decision_at_ms)
                : prependCandleHistory(data.series, page.series, data.decisionAtMs);
            const range = chart.timeScale().getVisibleLogicalRange();
            data.series = merged.series;
            Object.assign(data, chartData({ series: data.series }));
            for (const candle of data.series) candles.set(candle.time, candle);
            Object.assign(data.decisions, page.decisions);
            Object.assign(data.allowedActions, page.allowed_actions);
            for (const label of page.labels) {
                const time = Number(label.time);
                const decision = Number(page.decisions?.[String(time)] ?? 0);
                if (!ACTIONS.includes(label.action) || !candles.has(time)) continue;
                baselineLabels.set(time, label.action);
                if (!deleteAllPending && !stagedChanges.has(decision)) labels.set(time, label.action);
            }
            if (newer) {
                data.decisionAtMs = page.decision_at_ms;
                data.hasNewer = page.has_more === true && merged.added > 0 && data.decisionAtMs < data.latestDecisionAtMs;
                updateReplayNavigation(page);
            } else {
                data.hasMore = page.has_more === true && merged.added > 0;
            }
            price.setData(data.candles);
            volume.setData(data.volume);
            renderMarkers();
            if (range) {
                const offset = newer ? 0 : merged.added;
                lastVisibleRange = { from: range.from + offset, to: range.to + offset };
                chart.timeScale().setVisibleLogicalRange(lastVisibleRange);
            }
            historyStatus.textContent = (newer ? data.hasNewer : data.hasMore)
                ? `${merged.added} ${direction} candles loaded with your saved labels.`
                : newer ? 'Newest available candle reached.' : 'Earliest available data reached.';
        } catch (error) {
            if (!disposed) {
                historyFailed = true;
                historyRetry.hidden = false;
                historyStatus.textContent = error.name === 'AbortError' ? 'History loading timed out. Please retry.' : error.message;
            }
        } finally {
            clearTimeout(timeout);
            historyRequest = null;
            loadingHistory = false;
        }
    };
    const retryHistory = () => loadHistory(retryDirection);
    const onVisibleRangeChange = range => {
        if (tooltip) tooltip.hidden = true;
        clearTimeout(historyTimer);
        const previousRange = lastVisibleRange;
        lastVisibleRange = range;
        if (!userInteracted || !range || !previousRange || loadingHistory || historyFailed || disposed) return;
        const direction = historyPanDirection(range, previousRange, data.series.length, data.hasMore, data.hasNewer);
        if (direction) historyTimer = setTimeout(() => loadHistory(direction), 180);
    };
    const onWheel = () => { userInteracted = true; closeMenu(); };
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
        const timeScale = chart.timeScale();
        const logical = typeof timeScale.coordinateToLogical === 'function'
            ? timeScale.coordinateToLogical(x)
            : null;
        const logicalTime = candleTimeAtLogicalIndex(data.series, logical);
        if (logicalTime !== null && candles.has(logicalTime)) return logicalTime;
        const direct = timeScale.coordinateToTime(x);
        if (typeof direct === 'number' && candles.has(Number(direct))) return Number(direct);
        let best = null;
        let distance = Infinity;
        for (const time of candles.keys()) {
            const coordinate = timeScale.timeToCoordinate(time);
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
        if (submittingLabels || !menu) return;
        const decision = data.decisions[String(time)];
        menuTime = Number(time);
        const current = labels.get(menuTime) ?? null;
        const trainable = Boolean(decision);
        menu.querySelector('[data-menu-title]').textContent = `${formatTime(menuTime)}${current ? ` · ${current.toUpperCase()}` : ' · unlabelled'}`;
        const remove = menu.querySelector('[data-menu-action="delete"]');
        const note = menu.querySelector('[data-menu-note]');
        if (remove) remove.hidden = current === null || !trainable;
        if (note) {
            note.textContent = trainable
                ? 'BUY on red bars; SELL on green bars. HOLD on any bar.'
                : 'This candle is visible for context but has no immutable training row in this dataset, so it cannot be labelled.';
        }
        menu.querySelectorAll('[data-menu-action]').forEach(button => {
            const action = button.dataset.menuAction;
            button.disabled = !trainable
                || (action !== 'delete' && !candleActionAllowed(candles.get(menuTime), action, data.allowedActions[String(menuTime)]));
        });
        menu.hidden = false;
        menu.style.left = `${Math.max(8, clientX)}px`;
        menu.style.top = `${Math.max(8, clientY)}px`;
        if (!trainable) {
            status.textContent = 'This candle is context-only in the frozen dataset and cannot be used as a training label.';
        }
        requestAnimationFrame(() => {
            const rect = menu.getBoundingClientRect();
            menu.style.left = `${Math.max(8, Math.min(clientX, window.innerWidth - rect.width - 8))}px`;
            menu.style.top = `${Math.max(8, Math.min(clientY, window.innerHeight - rect.height - 8))}px`;
        });
    };
    const requestLabel = action => {
        if (submittingLabels || menuTime === null || !data.decisions[String(menuTime)]) return;
        const labelTime = menuTime;
        const decision = Number(data.decisions[String(menuTime)]);
        const deleting = action === 'delete';
        if (!deleting && !candleActionAllowed(candles.get(labelTime), action, data.allowedActions[String(labelTime)])) return;
        stageLabel(labelTime, decision, deleting ? null : action);
        status.textContent = deleting
            ? 'Label deletion staged in this browser. Press Submit to store the reviewed result.'
            : `${action.toUpperCase()} staged in this browser. Press Submit to store the reviewed result.`;
        closeMenu();
    };
    const requestAutoLabels = async () => {
        if (submittingLabels || autoLabelling || disposed || !autoLabel) return;
        autoLabelling = true;
        autoLabel.disabled = true;
        if (submitLabels) submitLabels.disabled = true;
        if (deleteAllTraining) deleteAllTraining.disabled = true;
        status.textContent = 'Building auto-label suggestions for this frozen dataset…';
        let staged = 0;
        try {
            do {
                let response, payload;
                for (let retries = 0; ; retries++) {
                    if (disposed) return;
                    response = await fetch(root.dataset.autoUrl, {
                        method: 'POST', credentials: 'same-origin',
                        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf },
                        body: JSON.stringify({ include_existing: deleteAllPending, offset: autoLabelOffset }),
                    });
                    payload = await response.json().catch(() => ({}));
                    if (disposed) return;
                    if (response.status !== 429 || retries >= 3) break;
                    const seconds = Math.max(1, Math.min(60, Number(response.headers?.get('Retry-After')) || 5));
                    status.textContent = `Auto-label is waiting ${seconds}s before continuing…`;
                    await new Promise(resolve => window.setTimeout(resolve, seconds * 1000));
                }
                if (!response.ok) throw new Error(payload.message ?? Object.values(payload.errors ?? {}).flat()[0] ?? `Request failed (${response.status}).`);
                const nextOffset = payload.next_offset ?? null;
                if (nextOffset !== null && (!Number.isSafeInteger(nextOffset) || nextOffset <= autoLabelOffset)) {
                    throw new Error('Auto-label returned an invalid continuation position.');
                }
                for (const label of payload.labels ?? []) {
                    const time = Number(label.time), decision = Number(label.decision_at_ms);
                    if (!ACTIONS.includes(label.action) || !Number.isSafeInteger(decision) || stagedChanges.has(decision)) continue;
                    data.decisions[String(time)] = decision;
                    labels.set(time, label.action);
                    stagedChanges.set(decision, { decision_at_ms: decision, action: label.action });
                    staged++;
                }
                autoLabelOffset = nextOffset;
                renderMarkers();
                renderPending();
                status.textContent = `${payload.processed} of ${payload.total} candles checked; ${staged} new suggestions staged…`;
            } while (autoLabelOffset !== null);
            autoLabelOffset = 0;
            status.textContent = `${staged} auto-label suggestion${staged === 1 ? '' : 's'} staged for review. Nothing is stored until Submit.`;
        } catch (error) {
            const reason = error instanceof Error ? error.message : 'Auto-label suggestions could not be generated.';
            status.textContent = `${reason} Completed suggestions remain staged. Press Auto-label to resume.`;
        } finally {
            autoLabelling = false;
            autoLabel.disabled = false;
            if (submitLabels) submitLabels.disabled = false;
            if (deleteAllTraining) deleteAllTraining.disabled = false;
        }
    };
    const stageDeleteAll = () => {
        if (submittingLabels || autoLabelling || deleteAllPending) return;
        if (typeof window.confirm === 'function'
            && !window.confirm('Stage deletion of all your Action Training labels for this market and period? The database will not change until Submit.')) return;
        deleteAllPending = true;
        autoLabelOffset = 0;
        labels.clear();
        stagedChanges.clear();
        renderMarkers();
        renderPending();
        status.textContent = 'Deletion of all saved labels is staged only. Press Submit to commit it, or reload the page to discard it.';
    };
    const submitStagedLabels = async () => {
        if (submittingLabels || autoLabelling || disposed || !isDirty()) return;
        submittingLabels = true;
        if (submitLabels) submitLabels.disabled = true;
        if (autoLabel) autoLabel.disabled = true;
        if (deleteAllTraining) deleteAllTraining.disabled = true;
        const changes = [...stagedChanges.values()];
        const batchSize = Math.max(1, Math.min(50, Math.floor(Number(root.dataset.submitBatchSize)) || 50));
        const times = new Map(Object.entries(data.decisions).map(([time, decision]) => [Number(decision), Number(time)]));
        let submitted = 0, rateLimitRetries = 0;
        let message = 'Action Training labels submitted.';
        try {
            do {
                if (disposed) return;
                const batch = changes.slice(submitted, submitted + batchSize);
                status.textContent = `Submitting reviewed labels… ${submitted} of ${changes.length} saved.`;
                const response = await fetch(root.dataset.submitUrl, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf },
                    body: JSON.stringify({ delete_all: deleteAllPending, changes: batch }),
                });
                if (response.status === 429 && rateLimitRetries++ < 3) {
                    const seconds = Math.max(1, Math.min(60, Number(response.headers?.get('Retry-After')) || 5));
                    status.textContent = `Saved ${submitted} of ${changes.length} changes. Continuing in ${seconds} seconds…`;
                    await new Promise(resolve => window.setTimeout(resolve, seconds * 1000));
                    continue;
                }
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message ?? Object.values(payload.errors ?? {}).flat()[0] ?? `Request failed (${response.status}).`);
                if (disposed) return;
                if (deleteAllPending) baselineLabels.clear();
                deleteAllPending = false;
                for (const change of batch) {
                    const time = times.get(change.decision_at_ms);
                    if (time !== undefined) {
                        if (change.action === null) baselineLabels.delete(time);
                        else baselineLabels.set(time, change.action);
                    }
                    stagedChanges.delete(change.decision_at_ms);
                }
                submitted += batch.length;
                rateLimitRetries = 0;
                if (payload.stats) data.stats = payload.stats;
                message = payload.message ?? message;
                renderStats();
                renderPending();
            } while (submitted < changes.length || deleteAllPending);
            status.textContent = message;
        } catch (error) {
            const reason = error instanceof Error ? error.message : 'The staged Action Training labels could not be submitted.';
            status.textContent = `Saved ${submitted} of ${changes.length} changes. ${reason} Press Submit to retry the remaining changes.`;
        } finally {
            submittingLabels = false;
            if (submitLabels) submitLabels.disabled = false;
            if (autoLabel) autoLabel.disabled = false;
            if (deleteAllTraining) deleteAllTraining.disabled = false;
        }
    };
    const onBeforeUnload = event => {
        if (!isDirty()) return;
        event.preventDefault();
        event.returnValue = '';
    };
    const onContextMenu = event => {
        event.preventDefault();
        const time = nearestCandleTime(event.clientX);
        if (time === null) {
            closeMenu();
            status.textContent = 'No candle is under the pointer. Right-click directly over a candle to label it.';
            return;
        }
        openMenu(time, event.clientX, event.clientY);
    };
    const cancelLongPress = () => {
        if (longPressTimer !== null) clearTimeout(longPressTimer);
        longPressTimer = null;
        longPressStart = null;
    };
    const onPointerDown = event => {
        userInteracted = true;
        closeMenu();
        if (event.pointerType !== 'touch') return;
        cancelLongPress();
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
        const library = await loadLibrary();
        chart = library.createChart(canvas, { autoSize: true, ...theme() });
        price = chart.addSeries(library.CandlestickSeries, { upColor: '#159b83', downColor: '#d64a5e', borderVisible: false, wickUpColor: '#159b83', wickDownColor: '#d64a5e',
            priceFormat: { type: 'custom', minMove, formatter: value => formatPrice(value, minMove) } });
        volume = chart.addSeries(library.HistogramSeries, { priceFormat: { type: 'volume' }, priceLineVisible: false, lastValueVisible: false }, 1);
        measureLine = chart.addSeries(library.LineSeries, { lineWidth: 2, priceLineVisible: false, lastValueVisible: false,
            crosshairMarkerVisible: false, pointMarkersVisible: true, pointMarkersRadius: 4, autoscaleInfoProvider: () => null,
            priceFormat: { type: 'custom', minMove, formatter: value => formatPrice(value, minMove) } });
        price.setData(data.candles);
        volume.setData(data.volume);
        chart.panes()[1].setHeight(70);
        markerPlugin = library.createSeriesMarkers(price, sortedMarkers());
        const renderLegend = () => {
            const candle = inspectedCandle ?? data.series.at(-1);
            if (candle) legend.textContent = `${formatTime(candle.time)} · O ${formatPrice(candle.open, minMove)} · H ${formatPrice(candle.high, minMove)} · L ${formatPrice(candle.low, minMove)} · C ${formatPrice(candle.close, minMove)} · Volume ${formatPrice(candle.volume)}`;
        };
        chart.subscribeCrosshairMove(param => {
            renderMeasurementTooltip(param);
            inspectedCandle = candles.get(Number(param.time));
            renderLegend();
        });
        const renderTime = () => {
            chart.applyOptions(chartTimeOptions());
            renderLegend();
            renderMeasurement();
            const replayTime = root.querySelector('[data-replay-time]');
            if (replayTime && data.series.length) replayTime.textContent = formatTime(data.series.at(-1).time);
            if (menuTime !== null) {
                const current = labels.get(menuTime);
                menu.querySelector('[data-menu-title]').textContent = `${formatTime(menuTime)}${current ? ` · ${current.toUpperCase()}` : ' · unlabelled'}`;
            }
        };
        unsubscribeTime = subscribeTimeDisplay(renderTime);
        renderTime();
        const clickHandler = param => {
            if (suppressNextClick) { suppressNextClick = false; return; }
            if (param.time === undefined || param.time === null) return;
            selectForMeasurement(Number(param.time));
        };
        chart.subscribeClick(clickHandler);
        observer = new MutationObserver(() => chart.applyOptions(theme()));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        fit.addEventListener('click', fitChart);
        historyRetry.addEventListener('click', retryHistory);
        chart.timeScale().subscribeVisibleLogicalRangeChange(onVisibleRangeChange);
        canvas.addEventListener('contextmenu', onContextMenu, true);
        canvas.addEventListener('wheel', onWheel, { passive: true });
        canvas.addEventListener('pointerdown', onPointerDown);
        canvas.addEventListener('pointermove', onPointerMove);
        canvas.addEventListener('pointerup', cancelLongPress);
        canvas.addEventListener('pointercancel', cancelLongPress);
        document.addEventListener('pointerdown', onDocumentPointerDown);
        document.addEventListener('keydown', onKeyDown);
        menu?.querySelectorAll('[data-menu-action]').forEach(button => button.addEventListener('click', () => requestLabel(button.dataset.menuAction)));
        autoLabel?.addEventListener('click', requestAutoLabels);
        deleteAllTraining?.addEventListener('click', stageDeleteAll);
        submitLabels?.addEventListener('click', submitStagedLabels);
        window.addEventListener('beforeunload', onBeforeUnload);
        renderPending();
        fitChart();
        renderMeasurement();
        renderStats();
        status.textContent = 'Left-click selects A/B; hover the line to see the price move. Right-click or long-press a candle to stage BUY/HOLD/SELL. Nothing is stored until Submit.';
        window.addEventListener('pagehide', event => {
            if (event.persisted) return;
            disposed = true;
            clearTimeout(historyTimer);
            historyRequest?.abort();
            unsubscribeTime?.();
            observer.disconnect();
            chart.unsubscribeClick(clickHandler);
            chart.timeScale().unsubscribeVisibleLogicalRangeChange(onVisibleRangeChange);
            chart.remove();
            fit.removeEventListener('click', fitChart);
            historyRetry.removeEventListener('click', retryHistory);
            datasetSelect?.removeEventListener('change', switchDataset);
            autoLabel?.removeEventListener('click', requestAutoLabels);
            deleteAllTraining?.removeEventListener('click', stageDeleteAll);
            submitLabels?.removeEventListener('click', submitStagedLabels);
            window.removeEventListener('beforeunload', onBeforeUnload);
            canvas.removeEventListener('contextmenu', onContextMenu, true);
            canvas.removeEventListener('wheel', onWheel);
            canvas.removeEventListener('pointerdown', onPointerDown);
            canvas.removeEventListener('pointermove', onPointerMove);
            canvas.removeEventListener('pointerup', cancelLongPress);
            canvas.removeEventListener('pointercancel', cancelLongPress);
            document.removeEventListener('pointerdown', onDocumentPointerDown);
            document.removeEventListener('keydown', onKeyDown);
            cancelLongPress();
        }, { once: true });
    } catch {
        unsubscribeTime?.();
        observer?.disconnect();
        chart?.remove();
        canvas.hidden = true;
        fit.disabled = true;
        if (autoLabel) autoLabel.disabled = true;
        if (deleteAllTraining) deleteAllTraining.disabled = true;
        if (submitLabels) submitLabels.disabled = true;
        renderStats();
        renderMeasurement();
        status.textContent = 'The chart could not load. Reload this page to label candles. Replay navigation, candle values and indicators remain available.';
    }
}
