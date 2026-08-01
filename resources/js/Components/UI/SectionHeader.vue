<script setup>
/**
 * Sektionsüberschrift: grau und mittelschwer, nicht schwarz und fett —
 * die Karten darunter sollen die Aufmerksamkeit bekommen, nicht die
 * Beschriftung. Rechts optional eine Pill-Aktion.
 */
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <div class="mb-3 flex items-center justify-between gap-3 px-1">
        <h2 class="text-[17px] font-medium text-ink-2">{{ title }}</h2>
        <div v-if="$slots.action" class="shrink-0">
            <slot name="action" />
        </div>
    </div>
</template>
