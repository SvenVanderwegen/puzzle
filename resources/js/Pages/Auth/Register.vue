<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

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

    <main class="mx-auto flex max-w-[420px] flex-col gap-7 px-4 pt-16 pb-16">
        <header class="flex flex-col gap-2">
            <p class="font-mono text-[11px] tracking-[.22em] text-ash-dim uppercase">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">
                REGISTER
            </h1>
        </header>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Name</span>
                <input
                    v-model="form.name"
                    type="text"
                    autocomplete="name"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
                <span v-if="form.errors.name" class="text-xs text-void">{{ form.errors.name }}</span>
            </label>

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
                    autocomplete="new-password"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
                <span v-if="form.errors.password" class="text-xs text-void">{{ form.errors.password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Confirm password</span>
                <input
                    v-model="form.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
            </label>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 w-full" :disabled="form.processing">Register</button>
        </form>

        <p class="text-sm text-ash">
            Already have an account?
            <Link href="/login" class="text-ember hover:text-flame">Sign in</Link>
        </p>
    </main>
</template>
