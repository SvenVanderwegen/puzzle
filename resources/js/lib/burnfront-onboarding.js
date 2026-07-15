export const ONBOARDING_KEY = 'burnfront:onboarding:rules:v1';

export function hasCompletedOnboarding() {
    if (typeof window === 'undefined') return false;

    try {
        return window.localStorage.getItem(ONBOARDING_KEY) === 'complete';
    } catch {
        return false;
    }
}

export function completeOnboarding() {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(ONBOARDING_KEY, 'complete');
    } catch {
        // Storage can be unavailable in private or hardened browser modes.
    }
}
