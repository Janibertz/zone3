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
    <div class="no-scrollbar flex overflow-x-auto rounded-[9px] bg-surface-2 p-0.5" role="tablist">
        <button
            v-for="opt in options"
            :key="opt.value"
            type="button"
            role="tab"
            :aria-selected="modelValue === opt.value"
            class="min-h-[32px] flex-1 whitespace-nowrap rounded-[7px] px-3 py-1.5 text-[13px] font-semibold transition duration-200 ease-out-strong"
            :class="modelValue === opt.value
                ? 'bg-surface text-ink shadow-[0_1px_3px_rgb(0_0_0/0.12),0_1px_1px_rgb(0_0_0/0.04)] dark:bg-surface-3'
                : 'text-ink-2 hover:text-ink'"
            @click="$emit('update:modelValue', opt.value)"
        >
            {{ opt.label }}
        </button>
    </div>
</template>
