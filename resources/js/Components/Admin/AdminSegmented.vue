<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Segmente wie in den Mockups (14 px). Ein Eintrag mit `href` ist ein
 * Link (System: Status · Log · KI-Aufrufe sind drei Seiten).
 */
defineProps({
    modelValue: { type: [String, Number], default: null },
    options:    { type: Array, required: true },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex rounded-[9px] bg-surface-2 p-0.5" role="tablist">
        <template v-for="o in options" :key="o.value">
            <component
                :is="o.href ? Link : 'button'"
                :href="o.href"
                :type="o.href ? undefined : 'button'"
                role="tab"
                :aria-selected="modelValue === o.value"
                class="flex h-[30px] flex-1 items-center justify-center whitespace-nowrap rounded-[7px] px-2 text-[14px] transition duration-200 ease-out-strong"
                :class="modelValue === o.value
                    ? 'bg-surface font-semibold text-ink shadow-[0_1px_3px_rgb(0_0_0/0.12)] dark:bg-surface-3'
                    : 'font-medium text-ink'"
                @click="o.href ? null : $emit('update:modelValue', o.value)"
            >{{ o.label }}</component>
        </template>
    </div>
</template>
