const listeners = new Set();
const formatters = new Map();
let localTimezone = 'UTC';
let mode = 'local';
let preferenceStorage = null;
let storageKey = '';

function validTimezone(value) {
    try {
        if (!value) return null;
        new Intl.DateTimeFormat('en', { timeZone: value }).format(0);
        return value;
    } catch { return null; }
}

export function configureTimeDisplay({ timezone, user = 'guest', storage = null, browserTimezone, supportedTimezones = [] } = {}) {
    localTimezone = validTimezone(timezone) ?? validTimezone(browserTimezone)
        ?? validTimezone(Intl.DateTimeFormat().resolvedOptions().timeZone) ?? 'UTC';
    if (supportedTimezones.length && !supportedTimezones.includes(localTimezone)) {
        const canonical = new Intl.DateTimeFormat('en', { timeZone: localTimezone }).resolvedOptions().timeZone;
        localTimezone = supportedTimezones.find(zone => validTimezone(zone)
            && new Intl.DateTimeFormat('en', { timeZone: zone }).resolvedOptions().timeZone === canonical) ?? 'UTC';
    }
    preferenceStorage = storage;
    storageKey = `trademinator.time-display.${user}`;
    try { mode = storage?.getItem(storageKey) === 'utc' ? 'utc' : 'local'; }
    catch { mode = 'local'; }
    listeners.forEach(listener => listener());
}

export function displayTimezone() {
    return mode === 'utc' ? 'UTC' : localTimezone;
}

export function setTimeMode(value) {
    mode = value === 'utc' ? 'utc' : 'local';
    try { preferenceStorage?.setItem(storageKey, mode); } catch { /* Display still works without storage. */ }
    listeners.forEach(listener => listener());
}

export function subscribeTimeDisplay(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** Naive strings in existing server payloads always represent UTC, never browser time. */
export function timestampMilliseconds(value) {
    if (value === null || value === undefined || value === '') return NaN;
    if (typeof value === 'number') return value;
    if (value instanceof Date) return value.getTime();
    const text = String(value).trim().replace(/ UTC$/, 'Z').replace(' ', 'T');
    return Date.parse(/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)?$/.test(text)
        ? (text.includes('T') ? text : `${text}T00:00:00`) + 'Z' : text);
}

function partsAt(value, timezone) {
    if (!formatters.has(timezone)) {
        formatters.set(timezone, new Intl.DateTimeFormat('en-CA-u-ca-gregory-nu-latn', {
            timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23', timeZoneName: 'longOffset',
        }));
    }
    return Object.fromEntries(formatters.get(timezone).formatToParts(value).map(part => [part.type, part.value]));
}

export function formatTimestamp(value, { precision = 'seconds', timezone = displayTimezone(), suffix = true } = {}) {
    const milliseconds = timestampMilliseconds(value);
    if (!Number.isFinite(milliseconds)) return 'Not available';
    const p = partsAt(milliseconds, timezone);
    const date = `${p.year}-${p.month}-${p.day}`;
    const clock = `${p.hour}:${p.minute}${precision === 'minutes' ? '' : `:${p.second}`}`;
    const text = precision === 'date' ? date : precision === 'time' ? clock : `${date} ${clock}`;
    const offset = p.timeZoneName.replace('GMT', 'UTC').replace('UTC+00:00', 'UTC');
    return suffix ? `${text} ${offset}` : text;
}

/** Keep chart coordinates and markers in real UTC seconds, including across a DST fold. */
export function chartTimeOptions() {
    const timezone = displayTimezone();
    const milliseconds = time => typeof time === 'number' ? time * 1000
        : timestampMilliseconds(typeof time === 'string' ? time
            : `${time.year}-${String(time.month).padStart(2, '0')}-${String(time.day).padStart(2, '0')}`);
    return {
        localization: { timeFormatter: time => formatTimestamp(milliseconds(time), { timezone, precision: 'minutes' }) },
        timeScale: { tickMarkFormatter: (time, type) => {
            const p = partsAt(milliseconds(time), timezone);
            if (type === 0) return p.year;
            if (type === 1) return `${p.year}-${p.month}`;
            if (type === 2) return `${p.month}-${p.day}`;
            return `${p.hour}:${p.minute}${type === 4 ? `:${p.second}` : ''}`;
        } },
    };
}

export function renderTimeElements(root) {
    const render = element => {
        const text = formatTimestamp(element.getAttribute('datetime'), { precision: element.dataset.timePrecision });
        if (element.textContent !== text) element.textContent = text;
    };
    if (root.matches?.('[data-display-time]')) render(root);
    root.querySelectorAll('[data-display-time]').forEach(render);
    const label = element => { if (element.textContent !== displayTimezone()) element.textContent = displayTimezone(); };
    if (root.matches?.('[data-timezone-label]')) label(root);
    root.querySelectorAll('[data-timezone-label]').forEach(label);
}

/** Return every matching instant: zero for a DST gap, two for a repeated clock time. */
export function localTimeInstants(value, timezone) {
    const wall = timestampMilliseconds(value);
    if (!Number.isFinite(wall)) return [];
    const normalized = formatTimestamp(wall, { timezone: 'UTC', suffix: false });
    const offsets = new Set();
    for (let hours = -36; hours <= 36; hours += 6) {
        const sample = wall + hours * 3600000;
        offsets.add(timestampMilliseconds(formatTimestamp(sample, { timezone, suffix: false })) - sample);
    }
    return [...offsets].map(offset => wall - offset)
        .filter(instant => formatTimestamp(instant, { timezone, suffix: false }) === normalized).sort((a, b) => a - b);
}

export function mountTimeDisplay(root = document) {
    const control = root.querySelector('[data-time-display]');
    if (!control) return () => {};
    let storage;
    try { storage = window.localStorage; } catch { /* Restricted browsers can still switch. */ }
    configureTimeDisplay({ timezone: control.dataset.timezone, user: control.dataset.user, storage,
        supportedTimezones: JSON.parse(control.dataset.timezones ?? '[]') });
    const inputs = [...root.querySelectorAll('[data-time-input]')];
    const validateInput = input => {
        const zone = input.form.querySelector('[data-input-timezone]').value;
        const matches = input.value ? localTimeInstants(input.value, zone) : [];
        input.setCustomValidity(input.value && matches.length !== 1
            ? 'This clock time is skipped or repeated by a timezone change. Choose another time, or switch to UTC.' : '');
    };
    const render = () => {
        renderTimeElements(root);
        control.querySelectorAll('[data-time-mode]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.timeMode === mode));
        });
        inputs.forEach(input => {
            const zoneInput = input.form.querySelector('[data-input-timezone]');
            if (input.value && zoneInput.value !== displayTimezone()) {
                const matches = localTimeInstants(input.value, zoneInput.value);
                if (matches.length === 1) input.value = formatTimestamp(matches[0], { suffix: false, precision: 'minutes' }).replace(' ', 'T');
            }
            zoneInput.value = displayTimezone();
            validateInput(input);
        });
    };
    const click = event => {
        const button = event.target.closest('[data-time-mode]');
        if (button) setTimeMode(button.dataset.timeMode);
    };
    const inputChanged = event => validateInput(event.target);
    const sync = () => {
        if (!storage) return;
        try { mode = storage?.getItem(storageKey) === 'utc' ? 'utc' : 'local'; } catch { return; }
        listeners.forEach(listener => listener());
    };
    const storageChanged = event => { if (event.key === storageKey || event.key === null) sync(); };
    const pageShown = event => { if (event.persisted) sync(); };
    control.addEventListener('click', click);
    inputs.forEach(input => input.addEventListener('input', inputChanged));
    window.addEventListener('storage', storageChanged);
    window.addEventListener('pageshow', pageShown);
    const unsubscribe = subscribeTimeDisplay(render);
    const observer = new MutationObserver(records => {
        records.forEach(record => record.addedNodes.forEach(node => {
            if (node.nodeType === 1) renderTimeElements(node);
        }));
    });
    observer.observe(root.body ?? root, { childList: true, subtree: true });
    render();
    return () => {
        unsubscribe();
        observer.disconnect();
        control.removeEventListener('click', click);
        inputs.forEach(input => input.removeEventListener('input', inputChanged));
        window.removeEventListener('storage', storageChanged);
        window.removeEventListener('pageshow', pageShown);
    };
}
