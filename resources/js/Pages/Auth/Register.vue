<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';

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
    <Head title="Register — Burnfront" />

    <main class="bf-auth-shell">
        <Link href="/" class="bf-auth-brand" aria-label="Back to Burnfront menu">
            <span class="bf-auth-brand-mark"><BrandMark knockout /></span>
            <span><strong>Burnfront</strong><small>Incident desk</small></span>
        </Link>

        <section class="bf-auth-card" aria-labelledby="register-title">
            <header class="bf-auth-header">
                <p class="bf-eyebrow">Personnel file · New record</p>
                <h1 id="register-title">Join the unit</h1>
                <p>Keep your daily reports, campaign rank, streaks, and completed case files together.</p>
            </header>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Name</span>
                <input
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.name" class="text-xs text-void">{{ form.errors.name }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Email</span>
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.email" class="text-xs text-void">{{ form.errors.email }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Password</span>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.password" class="text-xs text-void">{{ form.errors.password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Confirm password</span>
                <input
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
            </label>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 w-full" :disabled="form.processing">Register</button>
            </form>

            <footer class="bf-auth-footer">
                <span>Already have a personnel file?</span>
                <Link href="/login">Sign in →</Link>
            </footer>
        </section>

        <p class="bf-auth-note"><span aria-hidden="true"></span> Incident record ready</p>
    </main>
</template>
