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

    <main class="mx-auto flex max-w-[560px] flex-col gap-6 px-4 pt-3 pb-16 sm:pt-5">
        <SiteBar :back="{ href: '/account', text: 'Account' }" />

        <header class="flex flex-col gap-2">
            <p class="bf-eyebrow">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">
                ACCOUNT SETTINGS
            </h1>
        </header>

        <form class="bf-settings-panel" @submit.prevent="submitProfile">
            <h2 class="bf-settings-title">Profile</h2>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Name</span>
                <input
                    v-model="profileForm.name"
                    type="text"
                    autocomplete="name"
                    required
                    class="bf-input"
                />
                <span v-if="profileForm.errors.name" class="text-xs text-void">{{ profileForm.errors.name }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Email</span>
                <input
                    v-model="profileForm.email"
                    type="email"
                    autocomplete="email"
                    required
                    class="bf-input"
                />
                <span v-if="profileForm.errors.email" class="text-xs text-void">{{ profileForm.errors.email }}</span>
            </label>

            <p v-if="profileForm.recentlySuccessful" class="text-xs font-semibold text-verify" role="status">
                <span aria-hidden="true">&#10003;</span> Saved.
            </p>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 self-start" :disabled="profileForm.processing">Save profile</button>
        </form>

        <form class="bf-settings-panel" @submit.prevent="submitPassword">
            <h2 class="bf-settings-title">Change Password</h2>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Current password</span>
                <input
                    v-model="passwordForm.current_password"
                    type="password"
                    autocomplete="current-password"
                    required
                    class="bf-input"
                />
                <span v-if="passwordForm.errors.current_password" class="text-xs text-void">{{ passwordForm.errors.current_password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">New password</span>
                <input
                    v-model="passwordForm.password"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
                <span v-if="passwordForm.errors.password" class="text-xs text-void">{{ passwordForm.errors.password }}</span>
            </label>

            <label class="flex flex-col gap-1.5">
                <span class="bf-form-label">Confirm new password</span>
                <input
                    v-model="passwordForm.password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    required
                    class="bf-input"
                />
            </label>

            <p v-if="passwordForm.recentlySuccessful" class="text-xs font-semibold text-verify" role="status">
                <span aria-hidden="true">&#10003;</span> Password updated.
            </p>

            <button type="submit" class="bf-btn bf-btn-primary mt-2 self-start" :disabled="passwordForm.processing">Update password</button>
        </form>

        <p class="text-sm text-ash">
            <Link
                href="/logout"
                method="post"
                as="button"
                class="cursor-pointer text-ember hover:text-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame"
            >
                Log out
            </Link>
        </p>
    </main>
</template>
