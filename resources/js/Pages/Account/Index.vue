<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import RowArrow from '@/Components/RowArrow.vue';
import SiteBar from '@/Components/SiteBar.vue';
import { t } from '@/lib/i18n';

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);

const links = [
    { href: '/account/settings', key: 'settings' },
    { href: '/game/history', key: 'games' },
    { href: '/daily/history', key: 'daily' },
    { href: '/game/replays', key: 'replays' },
];
</script>

<template>
    <Head :title="t('account.pageTitle')" />

    <div class="bf-page is-narrow">
        <SiteBar :back="{ href: '/', text: t('common.home') }" />

        <main>
            <header class="bf-page-head">
                <p class="bf-eyebrow">{{ t('account.eyebrow') }}</p>
                <h1 class="bf-page-title">
                    {{ currentUser?.name ? t('account.title', { name: currentUser.name }) : t('account.titleNoName') }}
                </h1>
                <p v-if="currentUser?.email" class="bf-lede">{{ t('account.signedInAs', { email: currentUser.email }) }}</p>
            </header>

            <nav class="bf-list" :aria-label="t('account.eyebrow')">
                <Link v-for="l in links" :key="l.key" :href="l.href" class="bf-row">
                    <span class="bf-row-text">
                        <span class="bf-row-title">{{ t(`account.${l.key}`) }}</span>
                        <span class="bf-row-sub">{{ t(`account.${l.key}Help`) }}</span>
                    </span>
                    <RowArrow />
                </Link>

                <Link href="/logout" method="post" as="button" class="bf-row is-dashed mt-2 w-full cursor-pointer">
                    <span class="bf-row-text">
                        <span class="bf-row-title">{{ t('account.signOut') }}</span>
                        <span class="bf-row-sub">{{ t('account.signOutHelp') }}</span>
                    </span>
                </Link>
            </nav>
        </main>
    </div>
</template>
