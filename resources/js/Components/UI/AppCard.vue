<script setup>
import { computed, useSlots } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Die Standard-Karte: reinweiß, kein Rahmen, große Ecken.
 * Sie hebt sich allein durch den grauen Canvas ab — deshalb hier
 * bewusst keine Border und nur ein Hauch Schatten.
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
        class="block w-full min-w-0 rounded-card bg-surface text-left shadow-card"
        :class="interactive ? 'transition-transform duration-150 active:scale-[0.99]' : ''"
    >
        <div v-if="hasHeader" class="flex items-center gap-3 px-5 pt-5" :class="flush ? 'pb-5' : ''">
            <div class="min-w-0 flex-1">
                <slot name="header">
                    <h3 v-if="title" class="text-[15px] font-semibold text-ink">{{ title }}</h3>
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
