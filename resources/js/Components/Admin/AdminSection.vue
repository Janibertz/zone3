<script setup>
defineProps({
    title: { type: String, required: true },
    /** Graue Zahl rechts neben der Überschrift. */
    aside: { type: [String, Number], default: null },
});
</script>

<template>
    <section class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between gap-3">
            <h2 class="m-0 text-[22px] font-bold tracking-[-0.02em] text-ink">{{ title }}</h2>
            <slot name="aside">
                <span v-if="aside !== null" class="text-[15px] tabular-nums text-ink-3">{{ aside }}</span>
            </slot>
        </div>
        <slot />
    </section>
</template>
