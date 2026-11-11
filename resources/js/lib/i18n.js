/* Tiny translation helper. Every user-facing string lives in a locale file
   under resources/js/locales/<locale>/<namespace>.json; t('lesson.title')
   reads key "title" from locales/en/lesson.json. {name} placeholders are
   filled from params. A missing key returns the key itself, so a gap shows
   up on screen instead of failing silently. Only English exists for now. */

const files = import.meta.glob('../locales/*/*.json', { eager: true, import: 'default' });

const messages = {};
for (const [path, data] of Object.entries(files)) {
    const match = path.match(/locales\/([^/]+)\/([^/]+)\.json$/);
    if (!match) continue;
    const [, locale, namespace] = match;
    messages[locale] ??= {};
    messages[locale][namespace] = data;
}

let currentLocale = 'en';

export function setLocale(locale) {
    if (messages[locale]) currentLocale = locale;
}

function lookup(locale, key) {
    const [namespace, ...rest] = key.split('.');
    let node = messages[locale]?.[namespace];
    for (const part of rest) {
        if (node == null) return undefined;
        node = node[part];
    }
    return typeof node === 'string' ? node : undefined;
}

export function t(key, params = {}) {
    const template = lookup(currentLocale, key) ?? lookup('en', key) ?? key;
    return template.replace(/\{(\w+)\}/g, (whole, name) => (name in params ? String(params[name]) : whole));
}
