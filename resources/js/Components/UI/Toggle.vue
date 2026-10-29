<script setup>
/**
 * iOS-Schalter (51 × 31, grün an). Vorher stand in jedem Formular ein eigener
 * Nachbau in drei verschiedenen Größen, mal blau, mal rot.
 */
defineProps({
    modelValue: { type: Boolean, default: false },
    label:      { type: String,  default: null },
    disabled:   { type: Boolean, default: false },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :aria-label="label ?? undefined"
        :disabled="disabled"
        class="relative inline-flex h-[31px] w-[51px] shrink-0 rounded-full p-0.5 transition-colors duration-200 ease-out-strong disabled:opacity-40"
        :class="modelValue ? 'bg-success' : 'bg-surface-3'"
        @click="$emit('update:modelValue', !modelValue)"
    >
        <span
            class="h-[27px] w-[27px] rounded-full bg-white shadow-[0_2px_4px_rgb(0_0_0/0.2)] transition-transform duration-200 ease-out-strong"
            :class="modelValue ? 'translate-x-5' : 'translate-x-0'"
        />
    </button>
</template>
