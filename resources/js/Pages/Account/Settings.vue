<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import SiteBar from '@/Components/SiteBar.vue';

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
    <Head title="Account Settings — Burnfront" />

    <main class="mx-auto flex max-w-[420px] flex-col gap-8 px-4 pt-6 pb-16">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="font-mono text-[11px] tracking-[.22em] text-ash-dim uppercase">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">
                ACCOUNT SETTINGS
            </h1>
        </header>

        <form class="flex flex-col gap-4" @submit.prevent="submitProfile">
            <h2 class="font-mono text-[11px] font-semibold tracking-[.18em] text-ash-dim uppercase">Profile</h2>

            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Name</span>
                <input
                    v-model="profileForm.name"
                    type="text"
                    autocomplete="name"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
                <span v-if="profileForm.errors.name" class="text-xs text-void">{{ profileForm.errors.name }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Email</span>
                <input
                    v-model="profileForm.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
                <span v-if="profileForm.errors.email" class="text-xs text-void">{{ profileForm.errors.email }}</span>
            </label>

            <p v-if="profileForm.recentlySuccessful" class="text-xs text-ember">Saved.</p>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 self-start" :disabled="profileForm.processing">Save profile</button>
        </form>

        <form class="flex flex-col gap-4 border-t border-rule pt-7" @submit.prevent="submitPassword">
            <h2 class="font-mono text-[11px] font-semibold tracking-[.18em] text-ash-dim uppercase">Change Password</h2>

            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Current password</span>
                <input
                    v-model="passwordForm.current_password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
                <span v-if="passwordForm.errors.current_password" class="text-xs text-void">{{ passwordForm.errors.current_password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">New password</span>
                <input
                    v-model="passwordForm.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
                <span v-if="passwordForm.errors.password" class="text-xs text-void">{{ passwordForm.errors.password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] tracking-[.09em] text-ash-dim uppercase">Confirm new password</span>
                <input
                    v-model="passwordForm.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="rounded-[7px] border border-rule-2 bg-folder px-3.5 py-3 font-mono text-[13px] text-stock outline-none focus-visible:border-ember"
                />
            </label>

            <p v-if="passwordForm.recentlySuccessful" class="text-xs text-ember">Password updated.</p>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 self-start" :disabled="passwordForm.processing">Update password</button>
        </form>

        <p class="text-sm text-ash">
            <Link href="/logout" method="post" as="button" class="cursor-pointer text-ember hover:text-flame">Log out</Link>
        </p>
    </main>
</template>
