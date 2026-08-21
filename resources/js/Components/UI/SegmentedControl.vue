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
    <!--
        whitespace-nowrap plus overflow-x-auto: bei vier Reitern („Alle,
        Laufen, Rad, Schwimmen") wird es auf einem 375-Pixel-Telefon knapp.
        Passen sie, verteilt flex-1 sie gleichmaessig; passen sie nicht,
        schiebt sich die Leiste seitlich, statt die Beschriftung umbrechen zu
        lassen.
    -->
    <div class="no-scrollbar flex gap-1 overflow-x-auto rounded-full bg-surface-2 p-1" role="tablist">
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            role="tab"
            :aria-selected="modelValue === opt.value"
            class="flex-1 whitespace-nowrap rounded-full px-2.5 py-3 text-[13px] font-medium transition-all duration-150 active:scale-[0.98] sm:px-3 sm:py-2 sm:text-sm"
            :class="modelValue === opt.value
                ? 'bg-surface text-ink shadow-card'
                : 'text-ink-3 hover:text-ink-2'"
            @click="$emit('update:modelValue', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
