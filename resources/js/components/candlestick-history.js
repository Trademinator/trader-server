function normalizeCandle(row) {
    return Object.fromEntries(Object.entries(row).map(([key, value]) => [key, Number(value)]));
}

export function mergeCandleHistory(current, incoming, direction, cutoffMs) {
    const firstTime = current[0]?.time ?? Infinity;
    const lastTime = current.at(-1)?.time ?? -Infinity;
    const candidates = incoming.filter(row => {
        const time = Number(row.time);
        if (!Number.isFinite(time) || time * 1000 >= cutoffMs) return false;
        return direction === 'newer' ? time > lastTime : time < firstTime;
    }).map(normalizeCandle);

    const combined = direction === 'newer' ? [...current, ...candidates] : [...candidates, ...current];
    const series = [...new Map(combined.map(row => [Number(row.time), row])).values()]
        .sort((a, b) => a.time - b.time);

    return { series, added: series.length - current.length };
}

export function historyPanDirection(range, previousRange, length, hasOlder, hasNewer, edgePadding = 5) {
    if (!range || !previousRange || length < 1) return null;
    const movement = range.from + range.to - previousRange.from - previousRange.to;
    if (movement < 0 && range.from < edgePadding && hasOlder) return 'older';
    if (movement > 0 && range.to > length - edgePadding - 1 && hasNewer) return 'newer';

    return null;
}
