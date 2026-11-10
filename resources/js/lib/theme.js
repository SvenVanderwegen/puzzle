/* Light / dark mode. By default the site follows the device setting; the
   switch in the top bar can pin it to light or dark instead. The choice is
   stored per browser under THEME_KEY. resources/views/app.blade.php applies
   the same rule in a tiny inline script before first paint, so the page
   never flashes the wrong theme; this module keeps it in sync afterwards.
   The `dark` class on <html> is the single source of truth for CSS
   (see the .dark block in resources/css/app.css). */
import { ref } from 'vue';

export const THEME_KEY = 'burnfront:theme';
export const THEME_CHOICES = ['system', 'light', 'dark'];

const media = typeof window !== 'undefined' && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

function readChoice() {
    try {
        const v = localStorage.getItem(THEME_KEY);
        return THEME_CHOICES.includes(v) ? v : 'system';
    } catch (e) {
        return 'system';
    }
}

/** The stored choice: 'system', 'light' or 'dark'. */
export const themeChoice = ref(readChoice());
/** Whether dark mode is showing right now. */
export const isDark = ref(false);

function apply() {
    const dark = themeChoice.value === 'dark' || (themeChoice.value === 'system' && !!media?.matches);
    isDark.value = dark;
    if (typeof document === 'undefined') return;
    const root = document.documentElement;
    root.classList.toggle('dark', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => meta.setAttribute('content', dark ? '#121416' : '#F1F1EE'));
}

export function setTheme(choice) {
    themeChoice.value = THEME_CHOICES.includes(choice) ? choice : 'system';
    try {
        if (themeChoice.value === 'system') localStorage.removeItem(THEME_KEY);
        else localStorage.setItem(THEME_KEY, themeChoice.value);
    } catch (e) {
        /* storage blocked: the choice still holds for this page view */
    }
    apply();
}

/** Device setting, then light, then dark, then back to the device setting. */
export function cycleTheme() {
    const i = THEME_CHOICES.indexOf(themeChoice.value);
    setTheme(THEME_CHOICES[(i + 1) % THEME_CHOICES.length]);
}

media?.addEventListener?.('change', apply);
apply();
