<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { t } from '@/lib/i18n';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head :title="t('auth.register.pageTitle')" />

    <main class="bf-auth-shell">
        <div class="bf-auth-top">
            <Link href="/" class="bf-auth-brand" :aria-label="t('auth.backHome')">
                <span class="bf-auth-brand-mark"><BrandMark /></span>
                <span><strong>burnfront</strong><small>{{ t('auth.brandSub') }}</small></span>
            </Link>
            <ThemeToggle />
        </div>

        <section class="bf-auth-card" aria-labelledby="register-title">
            <header class="bf-auth-header">
                <p class="bf-eyebrow">{{ t('auth.register.eyebrow') }}</p>
                <h1 id="register-title">{{ t('auth.register.title') }}</h1>
                <p>{{ t('auth.register.lead') }}</p>
            </header>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('auth.name') }}</span>
                <input
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.name" class="bf-field-error">{{ form.errors.name }}</span>
            </label>

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
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.password" class="bf-field-error">{{ form.errors.password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">{{ t('auth.confirmPassword') }}</span>
                <input
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
            </label>

            <button type="submit" class="bf-btn bf-btn-primary bf-btn-lg mt-2 w-full" :disabled="form.processing">{{ t('auth.register.submit') }}</button>
            </form>

            <footer class="bf-auth-footer">
                <span>{{ t('auth.register.haveAccount') }}</span>
                <Link href="/login">{{ t('auth.register.toLogin') }}</Link>
            </footer>
        </section>

        <p class="bf-auth-note">
            {{ t('auth.register.note') }} <Link href="/">{{ t('auth.playAsGuest') }}</Link>
        </p>
    </main>
</template>
