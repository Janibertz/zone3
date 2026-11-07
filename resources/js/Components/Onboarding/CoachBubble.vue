<script setup>
/**
 * Der Coach spricht: Avatar mit Initialen und eine Sprechblase.
 *
 * Im Onboarding über jeder Frage (Jan, 07.11.2026) — die Frage bekommt einen
 * Grund, und der gewählte Coach kommt nach seiner Wahl auch vor. {name} und
 * {coach} ersetzt die Komponente; die Texte pflegt der Admin.
 */
import { computed } from 'vue';

const props = defineProps({
    coach: { type: Object, default: null },
    text:  { type: String, default: '' },
    name:  { type: String, default: '' },
});

const AVATAR = { orange: 'bg-warn', blue: 'bg-info', green: 'bg-success', purple: 'bg-accent' };

const body = computed(() => (props.text ?? '')
    .replaceAll('{name}', props.name || '')
    .replaceAll('{coach}', props.coach?.name ?? 'dein Coach')
    // „Hi , ich…“ ohne Namen.
    .replace(/\s+,/g, ','));
</script>

<template>
    <div v-if="body" class="flex items-start gap-3">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[13px] font-bold text-white"
            :class="AVATAR[coach?.avatar_color] ?? 'bg-accent'" aria-hidden="true">{{ coach?.avatar_initials ?? 'Z3' }}</span>
        <p class="min-w-0 flex-1 rounded-[18px] rounded-tl-[6px] bg-surface px-4 py-3 text-[17px] leading-snug text-ink">
            <span class="sr-only">{{ coach?.name ?? 'Coach' }}: </span>{{ body }}
        </p>
    </div>
</template>
