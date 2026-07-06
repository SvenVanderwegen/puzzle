<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import SiteBar from '@/Components/SiteBar.vue';

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <Head title="Account — Burnfront" />

    <main class="mx-auto flex max-w-[420px] flex-col gap-7 px-4 pt-6 pb-16">
        <SiteBar :back="{ href: '/', text: 'Menu' }" />

        <header class="flex flex-col gap-2">
            <p class="bf-eyebrow">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-stock">
                ACCOUNT
            </h1>
            <p v-if="currentUser" class="text-sm text-ash">
                Signed in as <span class="text-stock">{{ currentUser.name }}</span>
            </p>
        </header>

        <nav class="flex flex-col gap-3" aria-label="Account menu">
            <Link href="/account/settings" class="bf-row">
                <span class="flex flex-1 flex-col gap-0.5">
                    <span class="font-staatliches text-[21px] leading-none text-stock">Account Settings</span>
                    <span class="text-[13px] text-ash">Update your name, email, or password.</span>
                </span>
                <span class="shrink-0 font-staatliches text-ash-dim" aria-hidden="true">&#9656;</span>
            </Link>

            <Link href="/game/history" class="bf-row">
                <span class="flex flex-1 flex-col gap-0.5">
                    <span class="font-staatliches text-[21px] leading-none text-stock">Game History</span>
                    <span class="text-[13px] text-ash">A record of every fire you've solved.</span>
                </span>
                <span class="shrink-0 font-staatliches text-ash-dim" aria-hidden="true">&#9656;</span>
            </Link>

            <Link href="/daily/history" class="bf-row">
                <span class="flex flex-1 flex-col gap-0.5">
                    <span class="font-staatliches text-[21px] leading-none text-stock">Daily History</span>
                    <span class="text-[13px] text-ash">Your streak and past daily results.</span>
                </span>
                <span class="shrink-0 font-staatliches text-ash-dim" aria-hidden="true">&#9656;</span>
            </Link>

            <Link
                href="/logout"
                method="post"
                as="button"
                class="bf-row cursor-pointer border-ember-deep/40 bg-ember/[.05] text-left"
            >
                <span class="flex flex-1 flex-col gap-0.5">
                    <span class="font-staatliches text-[21px] leading-none text-ember">Log Out</span>
                    <span class="text-[13px] text-ash">Sign out of this device.</span>
                </span>
                <span class="shrink-0 font-staatliches text-ember" aria-hidden="true">&#9211;</span>
            </Link>
        </nav>
    </main>
</template>
