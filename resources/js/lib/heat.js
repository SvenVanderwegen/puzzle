/* The heat ramp: the only colour in Burnfront. Colour means time, so a cell's
   colour is the minute the fire reached it, scaled to the last minute of
   that fire (t = minute / lastMinute, 0..1). Linear interpolation between
   fixed stops, yellow at the spark to violet at the end. Dark mode keeps the
   same ramp with a lighter violet tail so late minutes still read on black.
   Pure functions, no DOM, so the web app, the lesson and the Expo app can
   share it byte for byte. */

export const HEAT_STOPS = [
    [0, [255, 226, 74]],
    [0.14, [255, 193, 46]],
    [0.28, [255, 154, 31]],
    [0.42, [255, 111, 39]],
    [0.56, [238, 63, 56]],
    [0.7, [204, 33, 98]],
    [0.82, [151, 33, 136]],
    [0.92, [99, 39, 143]],
    [1, [58, 43, 124]],
];

export const HEAT_STOPS_DARK = [
    ...HEAT_STOPS.slice(0, 7),
    [0.92, [122, 62, 172]],
    [1, [92, 76, 196]],
];

export const INK = '#16181B';
export const WHITE = '#FFFFFF';

/** RGB triple for t in 0..1 (clamped). */
export function heatRgb(t, dark = false) {
    const stops = dark ? HEAT_STOPS_DARK : HEAT_STOPS;
    const x = Math.max(0, Math.min(1, Number.isFinite(t) ? t : 0));
    for (let k = 1; k < stops.length; k++) {
        if (x <= stops[k][0]) {
            const [t0, a] = stops[k - 1];
            const [t1, b] = stops[k];
            const u = t1 === t0 ? 0 : (x - t0) / (t1 - t0);
            return [0, 1, 2].map((j) => Math.round(a[j] + (b[j] - a[j]) * u));
        }
    }
    return stops[stops.length - 1][1].slice();
}

export function rgbCss(rgb) {
    return `rgb(${rgb[0]},${rgb[1]},${rgb[2]})`;
}

/** CSS colour for minute / lastMinute. */
export function heatColor(t, dark = false) {
    return rgbCss(heatRgb(t, dark));
}

/** Text colour that stays readable on a ramp colour: ink on light steps, white on dark. */
export function inkOn(rgb) {
    const f = (v) => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    };
    const L = 0.2126 * f(rgb[0]) + 0.7152 * f(rgb[1]) + 0.0722 * f(rgb[2]);
    return L >= 0.199 ? INK : WHITE;
}

/** Background and text colour for one cell burning at `minute` of a fire lasting `lastMinute`. */
export function heatCell(minute, lastMinute, dark = false) {
    const rgb = heatRgb(lastMinute > 0 ? minute / lastMinute : 0, dark);
    return { bg: rgbCss(rgb), fg: inkOn(rgb) };
}

/**
 * The burn curve: how many cells caught fire in each minute, from a distance
 * array (-1 = firebreak / never burned). Spoiler-free: it says how the fire
 * grew, not where. Returns counts indexed by minute, 0..lastMinute.
 */
export function burnCurve(times) {
    let last = 0;
    for (const t of times) if (t > last) last = t;
    const counts = new Array(last + 1).fill(0);
    for (const t of times) if (t >= 0) counts[t]++;
    return counts;
}

/** The burn curve as block characters, for a share text. */
export function curveBlocks(counts) {
    const blocks = '▁▂▃▄▅▆▇█';
    const max = Math.max(1, ...counts);
    return counts.map((c) => blocks[Math.min(blocks.length - 1, Math.round((c / max) * (blocks.length - 1)))]).join('');
}
