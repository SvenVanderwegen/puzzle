<script setup>
/* A campaign incident marker: a shield-shaped node on the containment
   trail. Replaces the old circular bf-node. */
defineProps({
    state: { type: String, required: true }, // 'cleared' | 'current' | 'sealed'
    label: { type: String, required: true }, // e.g. "07"
});
</script>

<template>
    <div class="bf-shield-wrap" :class="state === 'current' ? 'size-[66px]' : 'size-[58px] aspect-[58/64]'">
        <div v-if="state === 'cleared'" class="bf-shield is-cleared size-full">
            <span class="bf-shield-num">{{ label }}</span>
        </div>

        <div v-else-if="state === 'sealed'" class="bf-shield is-sealed size-full opacity-50">
            <span class="bf-shield-num">{{ label }}</span>
        </div>

        <div v-else class="bf-shield-current-outer bf-shield relative size-full p-[2px]">
            <div class="bf-shield-current-inner bf-shield size-full">
                <span class="bf-shield-num">{{ label }}</span>
            </div>
        </div>

        <span v-if="state === 'cleared'" class="bf-shield-badge" aria-hidden="true">&#10003;</span>
        <span v-else-if="state === 'sealed'" class="bf-shield-sealed-tag">Sealed</span>
    </div>
</template>
