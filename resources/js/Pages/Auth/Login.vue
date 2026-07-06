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
        <header class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <span class="h-1.5 w-1.5 bg-ember" aria-hidden="true"></span>
                <span class="font-mono text-[11px] tracking-[.22em] text-ash-dim uppercase">Restricted · Line Verification Unit</span>
                <span class="h-px flex-1 bg-rule" aria-hidden="true"></span>
            </div>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">
                SIGN IN
            </h1>
        </header>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Email</span>
                <input
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
                <span v-if="form.errors.email" class="text-xs text-void">{{ form.errors.email }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Password</span>
                <input
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
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

        <p class="text-sm text-ash">
            No account yet?
            <Link href="/register" class="text-ember hover:text-flame">Register</Link>
        </p>
    </main>
</template>
