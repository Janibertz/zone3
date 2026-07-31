<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * One row of a settings/navigation list — the iOS-style pattern that replaces
 * most of the ad-hoc flex rows across the app.
 */
const props = defineProps({
    title:    { type: String,  default: null },
    subtitle: { type: String,  default: null },
    value:    { type: String,  default: null },
    href:     { type: String,  default: null },
    /** Shows the chevron. Implied by `href`. */
    chevron:  { type: Boolean, default: false },
    danger:   { type: Boolean, default: false },
});

const tag = computed(() => (props.href ? Link : 'button'));
const showChevron = computed(() => props.chevron || !!props.href);
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        :type="href ? undefined : 'button'"
        class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors hover:bg-surface-2 active:bg-surface-3"
    >
        <span v-if="$slots.icon" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-field bg-surface-2 text-ink-2">
            <slot name="icon" />
        </span>

        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium" :class="danger ? 'text-danger' : 'text-ink'">{{ title }}</span>
            <span v-if="subtitle" class="mt-0.5 block truncate text-xs text-ink-3">{{ subtitle }}</span>
        </span>

        <span v-if="value" class="shrink-0 text-sm text-ink-3">{{ value }}</span>
        <slot name="trailing" />

        <svg v-if="showChevron" class="h-4 w-4 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
        </svg>
    </component>
</template>
