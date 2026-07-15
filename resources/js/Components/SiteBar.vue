<script setup>
/* Shared field-office utility bar. It keeps the case breadcrumb, Burnfront
   mark and account entry point in one stable row on every screen. */
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import BrandMark from '@/Components/BrandMark.vue';

defineProps({
    back: { type: Object, default: null },
    label: { type: String, default: '' },
    crumb: { type: String, default: '' },
});

const page = usePage();
const currentUser = computed(() => page.props.auth?.user ?? null);
</script>

<template>
    <div class="bf-sitebar">
        <div class="flex min-w-0 items-center gap-2.5">
            <Link href="/" class="bf-sitebar-mark" aria-label="Burnfront case index">
                <BrandMark knockout class="size-6" />
            </Link>

            <p class="flex min-w-0 items-center truncate font-mono text-[12px] font-semibold tracking-[.12em] text-ash-dim uppercase">
                <template v-if="back">
                    <Link
                        :href="back.href"
                        class="rounded-[5px] py-2.5 text-ash-dim hover:text-ember focus-visible:text-flame focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-flame sm:py-1"
                    >
                        &larr; {{ back.text }}
                    </Link>
                </template>
                <template v-else-if="label">{{ label }}</template>
                <template v-if="crumb"> &middot; {{ crumb }}</template>
            </p>
        </div>

        <Link
            :href="currentUser ? '/account' : '/login'"
            class="bf-account-pill group"
            :aria-label="currentUser ? `Account, signed in as ${currentUser.name}` : 'Sign in'"
        >
            <span class="hidden font-mono text-[12px] font-semibold tracking-[.09em] text-ash-dim uppercase group-hover:text-ember group-focus-visible:text-ember sm:inline">
                {{ currentUser ? currentUser.name : 'Sign in' }}
            </span>
            <Avatar :name="currentUser?.name" />
        </Link>
    </div>
</template>
