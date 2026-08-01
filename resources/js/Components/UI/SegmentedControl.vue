<script setup>
/**
 * iOS-style segmented control — replaces the tab-button rows that are
 * hand-rolled on the Profile, Plan and Statistics pages.
 *
 * options: [{ value, label }]
 */
defineProps({
    modelValue: { type: [String, Number], default: null },
    options:    { type: Array, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex gap-1 rounded-full bg-surface-2 p-1" role="tablist">
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            role="tab"
            :aria-selected="modelValue === opt.value"
            class="flex-1 rounded-full px-3 py-2 text-sm font-medium transition-all duration-150 active:scale-[0.98]"
            :class="modelValue === opt.value
                ? 'bg-surface text-ink shadow-card'
                : 'text-ink-3 hover:text-ink-2'"
            @click="$emit('update:modelValue', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
