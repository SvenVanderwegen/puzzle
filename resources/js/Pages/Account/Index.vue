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
            <p class="text-[11px] tracking-[.22em] text-ash-dim uppercase">Line Verification Unit</p>
            <h1 class="font-staatliches text-[40px] leading-[0.95] font-normal tracking-[.035em] text-paper">
                ACCOUNT
            </h1>
            <p v-if="currentUser" class="text-sm text-ash">Signed in as {{ currentUser.name }}</p>
        </header>

        <nav class="flex flex-col gap-3" aria-label="Account menu">
            <Link href="/account/settings" class="bf-tile">
                <span class="bf-tile-title">Account Settings</span>
                <span class="bf-tile-desc">Update your name, email, or password.</span>
            </Link>

            <div class="bf-tile is-locked cursor-default">
                <span class="bf-tile-title">Game History</span>
                <span class="bf-tile-desc">A record of every fire you've solved.</span>
                <span class="bf-tile-meta">Coming soon</span>
            </div>

            <Link href="/daily/history" class="bf-tile">
                <span class="bf-tile-title">Daily History</span>
                <span class="bf-tile-desc">Your streak and past daily results.</span>
            </Link>

            <Link href="/logout" method="post" as="button" class="bf-tile cursor-pointer text-left">
                <span class="bf-tile-title text-ember">Log Out</span>
                <span class="bf-tile-desc">Sign out of this device.</span>
            </Link>
        </nav>
    </main>
</template>
