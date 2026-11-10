<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';
import { setTheme, themeChoice } from '@/lib/theme';

const themes = ['system', 'light', 'dark'];
const themeLabel = { system: 'themeSystem', light: 'themeLight', dark: 'themeDark' };

const props = defineProps({
    account: { type: Object, required: true }, // { name, email }
});

const profileForm = useForm({
    name: props.account.name,
    email: props.account.email,
});

function submitProfile() {
    profileForm.patch('/account/settings', {
        preserveScroll: true,
    });
}

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function submitPassword() {
    passwordForm.put('/account/settings/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('account.settingsPage.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/account', text: t('account.settingsPage.back') }" />

        <main class="flex flex-col gap-4">

        <header class="bf-page-head pb-2">
            <p class="bf-eyebrow">{{ t('account.settingsPage.eyebrow') }}</p>
            <h1 class="bf-page-title">{{ t('account.settingsPage.title') }}</h1>
        </header>

        <section class="bf-settings-panel" aria-labelledby="look-title">
            <h2 id="look-title" class="bf-settings-title">{{ t('account.settingsPage.look') }}</h2>
            <p class="bf-row-sub -mt-3">{{ t('account.settingsPage.lookHelp') }}</p>
            <div class="bf-segmented" role="group" :aria-label="t('account.settingsPage.look')">
                <button
                    v-for="c in themes"
                    :key="c"
                    type="button"
                    :aria-pressed="themeChoice === c ? 'true' : 'false'"
                    @click="setTheme(c)"
                >
                    {{ t('account.settingsPage.' + themeLabel[c]) }}
                </button>
            </div>
        </section>

        <form class="bf-settings-panel" @submit.prevent="submitProfile">
            <h2 class="bf-settings-title">{{ t('account.settingsPage.profile') }}</h2>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('account.settingsPage.name') }}</span>
                <input
                    v-model="profileForm.name"
                    type="text"
                    autocomplete="name"
                    required
                    class="bf-input"
                />
                <span v-if="profileForm.errors.name" class="bf-field-error">{{ profileForm.errors.name }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('account.settingsPage.email') }}</span>
                <input
                    v-model="profileForm.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="bf-input"
                />
                <span v-if="profileForm.errors.email" class="bf-field-error">{{ profileForm.errors.email }}</span>
            </label>

            <p v-if="profileForm.recentlySuccessful" class="text-[16px] font-semibold text-good" role="status">
                <span aria-hidden="true">&#10003;</span> {{ t('account.settingsPage.saved') }}
            </p>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 self-start" :disabled="profileForm.processing">{{ t('account.settingsPage.saveProfile') }}</button>
        </form>

        <form class="bf-settings-panel" @submit.prevent="submitPassword">
            <h2 class="bf-settings-title">{{ t('account.settingsPage.password') }}</h2>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('account.settingsPage.currentPassword') }}</span>
                <input
                    v-model="passwordForm.current_password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="bf-input"
                />
                <span v-if="passwordForm.errors.current_password" class="bf-field-error">{{ passwordForm.errors.current_password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('account.settingsPage.newPassword') }}</span>
                <input
                    v-model="passwordForm.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
                <span v-if="passwordForm.errors.password" class="bf-field-error">{{ passwordForm.errors.password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('account.settingsPage.confirmPassword') }}</span>
                <input
                    v-model="passwordForm.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
            </label>

            <p v-if="passwordForm.recentlySuccessful" class="text-[16px] font-semibold text-good" role="status">
                <span aria-hidden="true">&#10003;</span> {{ t('account.settingsPage.passwordSaved') }}
            </p>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 self-start" :disabled="passwordForm.processing">{{ t('account.settingsPage.savePassword') }}</button>
        </form>

        <Link href="/logout" method="post" as="button" class="bf-row is-dashed w-full cursor-pointer">
            <span class="bf-row-text">
                <span class="bf-row-title">{{ t('account.signOut') }}</span>
                <span class="bf-row-sub">{{ t('account.signOutHelp') }}</span>
            </span>
        </Link>
        </main>
    </div>
</template>
