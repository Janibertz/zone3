<script setup>
defineProps({
    title:       { type: String, default: 'Nichts da' },
    description: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-col items-center px-6 py-12 text-center">
        <div v-if="$slots.icon" class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-surface-2 text-ink-3">
            <slot name="icon" />
        </div>
        <h3 class="text-base font-semibold text-ink">{{ title }}</h3>
        <p v-if="description" class="mt-1.5 max-w-xs text-sm text-ink-3">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5">
            <slot />
        </div>
    </div>
</template>
