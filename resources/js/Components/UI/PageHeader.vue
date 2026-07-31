<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Large-title page header, the app-like counterpart to the old
 * `<template #header>` bar. `back` turns the title into a detail-view
 * header with a back affordance.
 */
defineProps({
    title:    { type: String, required: true },
    subtitle: { type: String, default: null },
    /** Route URL for the back chevron. Omit for top-level pages. */
    back:     { type: String, default: null },
});
</script>

<template>
    <header class="px-4 pt-4 pb-2 lg:px-8 lg:pt-8">
        <Link
            v-if="back"
            :href="back"
            class="-ml-2 mb-1 inline-flex items-center gap-1 rounded-field px-2 py-1 text-sm font-medium text-ink-3 transition-colors hover:text-ink"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
            Zurück
        </Link>

        <div class="flex items-end gap-3">
            <div class="min-w-0 flex-1">
                <h1 class="truncate text-2xl font-bold tracking-tight text-ink lg:text-3xl">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-sm text-ink-3">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.action" class="shrink-0 pb-1">
                <slot name="action" />
            </div>
        </div>
    </header>
</template>
