<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { t } from '@/lib/i18n';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="t('auth.login.pageTitle')" />

    <main class="bf-auth-shell">
        <div class="bf-auth-top">
            <Link href="/" class="bf-auth-brand" :aria-label="t('auth.backHome')">
                <span class="bf-auth-brand-mark"><BrandMark /></span>
                <span><strong>burnfront</strong><small>{{ t('auth.brandSub') }}</small></span>
            </Link>
            <ThemeToggle />
        </div>

        <section class="bf-auth-card" aria-labelledby="sign-in-title">
            <header class="bf-auth-header">
                <p class="bf-eyebrow">{{ t('auth.login.eyebrow') }}</p>
                <h1 id="sign-in-title">{{ t('auth.login.title') }}</h1>
                <p>{{ t('auth.login.lead') }}</p>
            </header>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('auth.email') }}</span>
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.email" class="bf-field-error">{{ form.errors.email }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('auth.password') }}</span>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.password" class="bf-field-error">{{ form.errors.password }}</span>
            </label>

            <label class="bf-check">
                <input v-model="form.remember" type="checkbox" />
                <span>{{ t('auth.login.remember') }}</span>
            </label>

            <button type="submit" class="bf-btn bf-btn-primary bf-btn-lg mt-2 w-full" :disabled="form.processing">{{ t('auth.login.submit') }}</button>
            </form>

            <footer class="bf-auth-footer">
                <span>{{ t('auth.login.noAccount') }}</span>
                <Link href="/register">{{ t('auth.login.toRegister') }}</Link>
            </footer>
        </section>

        <p class="bf-auth-note">
            {{ t('auth.login.note') }} <Link href="/">{{ t('auth.playAsGuest') }}</Link>
        </p>
    </main>
</template>
