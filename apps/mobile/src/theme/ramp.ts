/**
 * The heat ramp: the only colour in Burnfront. Colour = the minute the fire
 * arrived, from yellow (minute 0) to violet (the last minute).
 */
type RGB = [number, number, number];
type Stop = [number, RGB];

const LIGHT: Stop[] = [
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

/** In dark mode the violet tail is lighter so it stays visible on the dark ground. */
const DARK: Stop[] = [...LIGHT.slice(0, 7), [0.92, [122, 62, 172]], [1, [92, 76, 196]]];

function mix(stops: Stop[], t: number): RGB {
  const x = Math.max(0, Math.min(1, t));
  for (let k = 1; k < stops.length; k++) {
    if (x <= stops[k][0]) {
      const [ta, a] = stops[k - 1];
      const [tb, b] = stops[k];
      const u = (x - ta) / (tb - ta);
      return [0, 1, 2].map((j) => Math.round(a[j] + (b[j] - a[j]) * u)) as RGB;
    }
  }
  return stops[stops.length - 1][1];
}

function luminance([r, g, b]: RGB): number {
  const f = (v: number) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

export type Heat = { bg: string; fg: string };

/** Colour for a minute, plus the text colour that reads on it. */
export function heat(minute: number, lastMinute: number, dark = false): Heat {
  const c = mix(dark ? DARK : LIGHT, lastMinute > 0 ? minute / lastMinute : 0);
  return { bg: `rgb(${c[0]},${c[1]},${c[2]})`, fg: luminance(c) >= 0.199 ? '#16181B' : '#FFFFFF' };
}
