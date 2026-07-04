<script setup>
/* Shared utility bar for every Burnfront page: a back-link/breadcrumb on the
   left, sign-in status on the right. Stacks on narrow screens so neither side
   has to squeeze or wrap mid-word; sits on one row once there's room. */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    back: { type: Object, default: null }, // { href, text }
    label: { type: String, default: '' }, // plain text used when there's no back link (e.g. the start page)
    crumb: { type: String, default: '' }, // extra " · " suffix, e.g. mode/difficulty
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <div class="flex flex-col gap-1.5 text-[11px] sm:flex-row sm:items-center sm:justify-between">
        <p class="tracking-[.18em] text-ash-dim uppercase">
            <template v-if="back">
                <Link :href="back.href" class="text-ash-dim hover:text-ember">&larr; {{ back.text }}</Link>
            </template>
            <template v-else-if="label">{{ label }}</template>
            <template v-if="crumb"> &middot; {{ crumb }}</template>
        </p>
        <p class="whitespace-nowrap text-ash-dim">
            <template v-if="currentUser">
                Signed in as {{ currentUser.name }} &middot;
                <Link href="/logout" method="post" as="button" class="cursor-pointer text-ember hover:text-flame">Log out</Link>
            </template>
            <template v-else>
                <Link href="/login" class="text-ember hover:text-flame">Sign in</Link>
                &middot;
                <Link href="/register" class="text-ember hover:text-flame">Register</Link>
            </template>
        </p>
    </div>
</template>
