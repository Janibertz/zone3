<script setup>
import { computed, useSlots } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * The default container of the redesign. Everything that used to be a
 * `rounded-2xl border border-gray-100 bg-white dark:bg-slate-900 …` blob.
 */
const props = defineProps({
    title:    { type: String,  default: null },
    subtitle: { type: String,  default: null },
    /** Removes the inner padding — for edge-to-edge content like maps or lists. */
    flush:    { type: Boolean, default: false },
    /** Makes the whole card a link/button with press feedback. */
    href:     { type: String,  default: null },
    tappable: { type: Boolean, default: false },
});

const interactive = computed(() => !!props.href || props.tappable);

const slots = useSlots();
const hasHeader = computed(() => !!props.title || !!props.subtitle || !!slots.header);

const tag = computed(() => (props.href ? Link : props.tappable ? 'button' : 'div'));
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        class="block w-full rounded-card border border-line bg-surface text-left shadow-card"
        :class="interactive ? 'transition-all duration-150 hover:border-line-strong active:scale-[0.99]' : ''"
    >
        <div v-if="hasHeader" class="flex items-center gap-3 px-4 pt-4" :class="flush ? 'pb-4' : ''">
            <div class="min-w-0 flex-1">
                <slot name="header">
                    <h3 v-if="title" class="text-sm font-semibold text-ink">{{ title }}</h3>
                    <p v-if="subtitle" class="text-xs text-ink-3" :class="title ? 'mt-0.5' : ''">{{ subtitle }}</p>
                </slot>
            </div>
            <slot name="action" />
        </div>

        <div :class="flush ? '' : hasHeader ? 'px-4 pb-4 pt-3' : 'p-4'">
            <slot />
        </div>
    </component>
</template>
