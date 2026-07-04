<script setup>
/* Shared utility bar for every Burnfront page: a back-link/breadcrumb on the
   left, the account entry point on the right. Stacks on narrow screens so
   neither side has to squeeze or wrap mid-word; sits on one row once there's
   room. The avatar is a large (44px) tap target on mobile since it's often
   the only interactive thing in reach up here. */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Avatar from '@/Components/Avatar.vue';

const props = defineProps({
    back: { type: Object, default: null }, // { href, text }
    label: { type: String, default: '' }, // plain text used when there's no back link (e.g. the start page)
    crumb: { type: String, default: '' }, // extra " · " suffix, e.g. mode/difficulty
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <div class="flex flex-col gap-1 text-[11px] sm:flex-row sm:items-center sm:justify-between">
        <p class="flex min-h-11 items-center tracking-[.18em] text-ash-dim uppercase sm:min-h-0">
            <template v-if="back">
                <Link
                    :href="back.href"
                    class="-mx-1 rounded-[5px] px-1 py-2.5 text-ash-dim hover:text-ember focus-visible:text-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame sm:py-1"
                >
                    &larr; {{ back.text }}
                </Link>
            </template>
            <template v-else-if="label">{{ label }}</template>
            <template v-if="crumb"> &middot; {{ crumb }}</template>
        </p>

        <Link
            :href="currentUser ? '/account' : '/login'"
            class="group -mr-1 inline-flex items-center gap-2.5 self-end rounded-full py-1 pr-1 pl-3 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame sm:self-auto"
            :aria-label="currentUser ? `Account, signed in as ${currentUser.name}` : 'Sign in'"
        >
            <span class="hidden text-[11px] tracking-[.09em] text-ash-dim uppercase group-hover:text-ember group-focus-visible:text-ember sm:inline">
                {{ currentUser ? currentUser.name : 'Sign in' }}
            </span>
            <Avatar :name="currentUser?.name" />
        </Link>
    </div>
</template>
