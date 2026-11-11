<script setup>
/* Top bar on every page: the wordmark (home), an optional back link or page
   name, then the streak, the light/dark switch and the account link. */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import BrandMark from '@/Components/BrandMark.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import { t } from '@/lib/i18n';

defineProps({
    back: { type: Object, default: null }, // { href, text }
    label: { type: String, default: '' },
    crumb: { type: String, default: '' },
    streak: { type: Number, default: 0 },
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <header class="bf-sitebar">
        <div class="bf-sitebar-start">
            <Link v-if="back" :href="back.href" class="bf-icon-btn -ml-2.5" :aria-label="back.text || t('common.back')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 5l-7 7 7 7" />
                </svg>
            </Link>
            <Link href="/" class="bf-wordmark" :aria-label="t('common.home')">
                <BrandMark />
                <span>burnfront</span>
            </Link>
            <span v-if="crumb || label" class="bf-sitebar-crumb ml-2 hidden sm:inline">{{ crumb || label }}</span>
        </div>

        <div class="bf-sitebar-end">
            <span v-if="streak > 0" class="bf-streak"><i aria-hidden="true"></i>{{ t('common.streak', { count: streak }) }}</span>
            <ThemeToggle />
            <Link
                v-if="currentUser"
                href="/account"
                class="bf-account-pill"
                :aria-label="t('common.accountOf', { name: currentUser.name })"
            >
                <span class="hidden sm:inline">{{ currentUser.name }}</span>
                <Avatar :name="currentUser.name" />
            </Link>
            <Link v-else href="/login" class="bf-account-pill is-guest">{{ t('common.signIn') }}</Link>
        </div>
    </header>
</template>
