/* Tiny helper for the "one / many" keys in the locale files (the i18n helper
   has no plural rules of its own). pick('history.hints', n) reads
   history.hintsNone (n = 0, when present), history.hintsOne or history.hintsMany. */
import { t } from '@/lib/i18n';

export function plural(base, count, params = {}) {
    const p = { count, ...params };
    if (count === 0) {
        const none = t(`${base}None`, p);
        if (none !== `${base}None`) return none;
    }
    return t(count === 1 ? `${base}One` : `${base}Many`, p);
}

/** t(key), or the fallback when the key is missing. */
export function tOr(key, fallback, params) {
    const v = t(key, params);
    return v === key ? fallback : v;
}
