<script setup>
import { Link } from '@inertiajs/vue3';
import AdminFlash from '@/Components/Admin/AdminFlash.vue';

/**
 * Rahmen einer Admin-Seite (18.11.2026): großer Titel wie in den Mockups,
 * darüber eine graue Zeile, auf Unterseiten „‹ Zurück“ oben links.
 */
defineProps({
    title:     { type: String, required: true },
    eyebrow:   { type: String, default: null },
    /** Rote „Admin“-Marke neben dem Titel (nur Heute). */
    badge:     { type: Boolean, default: false },
    back:      { type: String, default: null },
    backLabel: { type: String, default: 'Zurück' },
    /** narrow = Liste wie am iPhone, wide = Desktop mit Spalten. */
    width:     { type: String, default: 'narrow' },
    /** Titel 34 (Tab-Seite) oder 28 (Unterseite). */
    detail:    { type: Boolean, default: false },
});
</script>

<template>
    <div class="mx-auto flex w-full flex-col gap-[18px] px-4 lg:px-10 lg:pt-8" :class="width === 'wide' ? 'max-w-[1180px]' : 'max-w-[680px]'">
        <div v-if="back || $slots.nav" class="-mx-2 -mb-2 flex h-11 items-center justify-between">
            <Link v-if="back" :href="back" class="flex h-11 items-center gap-1 px-2 text-[17px] text-accent active:opacity-60">
                <svg width="12" height="20" viewBox="0 0 12 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M10 2 2 10l8 8" /></svg>
                {{ backLabel }}
            </Link>
            <span v-else />
            <slot name="nav" />
        </div>

        <div class="flex items-end justify-between gap-3">
            <div class="min-w-0">
                <div v-if="eyebrow" class="text-[13px] font-semibold text-ink-3">{{ eyebrow }}</div>
                <div class="flex items-center gap-2">
                    <h1 class="m-0 break-words font-extrabold text-ink" :class="detail ? 'text-[28px] leading-[34px] tracking-[-0.03em]' : 'text-[34px] leading-[41px] tracking-[-0.035em]'">{{ title }}</h1>
                    <span v-if="badge" class="rounded-md bg-danger px-1.5 py-0.5 text-[12px] font-bold text-white">Admin</span>
                </div>
            </div>
            <div v-if="$slots.action" class="shrink-0">
                <slot name="action" />
            </div>
        </div>

        <AdminFlash />

        <slot />
    </div>
</template>
