<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

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

    <main class="mx-auto flex max-w-[420px] flex-col gap-7 px-4 pt-16 pb-16">
        <header class="flex flex-col gap-2">
            <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-paper">
                SIGN IN
            </h1>
        </header>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1.5">
                <span class="text-[11px] tracking-[.09em] text-ash-dim uppercase">Email</span>
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="rounded-[5px] border border-line bg-transparent px-3 py-2 text-sm text-paper outline-none focus-visible:border-ember"
                />
                <span v-if="form.errors.email" class="text-xs text-danger">{{ form.errors.email }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="text-[11px] tracking-[.09em] text-ash-dim uppercase">Password</span>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="rounded-[5px] border border-line bg-transparent px-3 py-2 text-sm text-paper outline-none focus-visible:border-ember"
                />
                <span v-if="form.errors.password" class="text-xs text-danger">{{ form.errors.password }}</span>
            </label>

            <label class="flex items-center gap-2 text-sm text-ash">
                <input v-model="form.remember" type="checkbox" class="accent-ember" />
                Remember me
            </label>

            <button type="submit" class="bf-btn bf-btn-primary mt-2" :disabled="form.processing">Sign in</button>
        </form>

        <p class="text-sm text-ash">
            No account yet?
            <Link href="/register" class="text-ember hover:text-flame">Register</Link>
        </p>
    </main>
</template>
