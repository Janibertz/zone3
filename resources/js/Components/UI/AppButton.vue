<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    variant: { type: String,  default: 'primary' }, // primary | secondary | ghost | danger
    size:    { type: String,  default: 'md' },      // sm | md | lg
    block:   { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    disabled:{ type: Boolean, default: false },
    /** Renders an Inertia <Link> instead of a <button>. */
    href:    { type: String,  default: null },
    type:    { type: String,  default: 'button' },
});

const variants = {
    primary:   'bg-accent text-white hover:opacity-90 active:opacity-80',
    secondary: 'bg-surface-2 text-ink hover:bg-surface-3 border border-line',
    ghost:     'text-ink-2 hover:bg-surface-2 hover:text-ink',
    danger:    'bg-danger text-white hover:opacity-90 active:opacity-80',
};

const sizes = {
    sm: 'h-9  px-3.5 text-sm  gap-1.5 rounded-field',
    md: 'h-11 px-4   text-sm  gap-2   rounded-field',
    lg: 'h-14 px-5   text-base gap-2  rounded-field',
};

const classes = computed(() => [
    'inline-flex items-center justify-center font-semibold transition-all duration-150',
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-canvas',
    'active:scale-[0.98] disabled:pointer-events-none disabled:opacity-50',
    variants[props.variant] ?? variants.primary,
    sizes[props.size] ?? sizes.md,
    props.block ? 'w-full' : '',
]);

const isDisabled = computed(() => props.disabled || props.loading);
</script>

<template>
    <component
        :is="href ? Link : 'button'"
        :href="href ?? undefined"
        :type="href ? undefined : type"
        :disabled="href ? undefined : isDisabled"
        :class="classes"
    >
        <svg v-if="loading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z" />
        </svg>
        <slot />
    </component>
</template>
