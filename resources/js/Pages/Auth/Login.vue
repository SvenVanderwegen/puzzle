<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import FlameGlyph from '@/Components/FlameGlyph.vue';

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
    <Head title="Sign in — Burnfront" />

    <main class="bf-auth-shell">
        <Link href="/" class="bf-auth-brand" aria-label="Back to Burnfront menu">
            <span class="bf-auth-brand-mark"><FlameGlyph knockout /></span>
            <span><strong>Burnfront</strong><small>Incident desk</small></span>
        </Link>

        <section class="bf-auth-card" aria-labelledby="sign-in-title">
            <header class="bf-auth-header">
                <p class="bf-eyebrow">Restricted · Line Verification Unit</p>
                <h1 id="sign-in-title">Return to the desk</h1>
                <p>Open today’s incident, continue your campaign, and review filed reconstructions.</p>
            </header>

            <form class="flex flex-col gap-4" @submit.prevent="submit">
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
                    autocomplete="current-password"
                    required
                    class="bf-input"
                />
                <span v-if="form.errors.password" class="text-xs text-void">{{ form.errors.password }}</span>
            </label>

            <label class="flex items-center gap-2 text-sm text-ash">
                <span class="relative flex h-[17px] w-[17px] shrink-0 items-center justify-center rounded-[3px] border border-ember">
                    <input v-model="form.remember" type="checkbox" class="peer absolute inset-0 h-full w-full cursor-pointer appearance-none" />
                    <span class="pointer-events-none text-[11px] leading-none text-ember opacity-0 peer-checked:opacity-100">✓</span>
                </span>
                Remember me
            </label>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 w-full" :disabled="form.processing">Sign in</button>
            </form>

            <footer class="bf-auth-footer">
                <span>No personnel file yet?</span>
                <Link href="/register">Create account →</Link>
            </footer>
        </section>

        <p class="bf-auth-note"><span aria-hidden="true"></span> Secure incident record access</p>
    </main>
</template>
