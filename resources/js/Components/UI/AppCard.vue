<script setup>
import { computed, useSlots } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Die Standard-Karte, wie in Health: weiß (dunkel: #1C1C1E) auf dem
 * gruppierten Grund, 16px Ecken, kein Rahmen, kein Schatten.
 */
const props = defineProps({
    title:    { type: String,  default: null },
    subtitle: { type: String,  default: null },
    /** Entfernt das Innenpolster — für randlose Inhalte wie Karten oder Listen. */
    flush:    { type: Boolean, default: false },
    /** Macht die ganze Karte antippbar. */
    href:     { type: String,  default: null },
    tappable: { type: Boolean, default: false },
});

const interactive = computed(() => !!props.href || props.tappable);
const tag = computed(() => (props.href ? Link : props.tappable ? 'button' : 'div'));

const slots = useSlots();
const hasHeader = computed(() => !!props.title || !!props.subtitle || !!slots.header);
</script>

<template>
    <component
        :is="tag"
        :href="href ?? undefined"
        class="block w-full min-w-0 rounded-card bg-surface text-left"
        :class="interactive ? 'transition-transform duration-150 ease-out-strong active:scale-[0.98]' : ''"
    >
        <div v-if="hasHeader" class="flex items-center gap-3 px-5 pt-5" :class="flush ? 'pb-3' : ''">
            <div class="min-w-0 flex-1">
                <slot name="header">
                    <h3 v-if="title" class="text-[17px] font-semibold text-ink">{{ title }}</h3>
                    <p v-if="subtitle" class="text-[13px] text-ink-3" :class="title ? 'mt-0.5' : ''">{{ subtitle }}</p>
                </slot>
            </div>
            <slot name="action" />
        </div>

        <div :class="flush ? '' : hasHeader ? 'px-5 pb-5 pt-3' : 'p-5'">
            <slot />
        </div>
    </component>
</template>
