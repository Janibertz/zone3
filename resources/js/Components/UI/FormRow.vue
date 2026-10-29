<script setup>
/**
 * Eine Zeile in einer {@see FormGroup}: Beschriftung links, Bedienelement
 * rechts. `stacked` stellt das Element unter die Beschriftung (Textfelder,
 * Auswahlreihen).
 */
defineProps({
    label:   { type: String,  default: null },
    hint:    { type: String,  default: null },
    stacked: { type: Boolean, default: false },
    /** Rendert ein <label>, damit ein Tipp auf die Beschriftung das Feld fokussiert. */
    as:      { type: String,  default: 'div' },
});
</script>

<template>
    <component :is="as" class="block px-4" :class="stacked ? 'py-3' : 'flex min-h-[48px] items-center gap-3 py-2'">
        <span v-if="label || $slots.label" class="min-w-0" :class="stacked ? 'mb-2 block' : 'flex-1'">
            <span class="block text-[17px] text-ink"><slot name="label">{{ label }}</slot></span>
            <span v-if="hint" class="mt-0.5 block text-[13px] leading-snug text-ink-3">{{ hint }}</span>
        </span>
        <slot />
    </component>
</template>
