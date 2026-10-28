<script setup>
/**
 * Sektionsüberschrift wie in Health: fett, 22px, volle Schriftfarbe.
 * Rechts eine Textaktion in der Akzentfarbe („Alle", „Zum Plan") —
 * AppButton variant="plain", kein Knopf mit Füllung.
 */
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <div class="mb-2.5 flex items-center justify-between gap-3">
        <h2 class="text-[22px] font-bold leading-7 tracking-[-0.02em] text-ink">{{ title }}</h2>
        <div v-if="$slots.action" class="flex shrink-0 items-center gap-4">
            <slot name="action" />
        </div>
    </div>
</template>
