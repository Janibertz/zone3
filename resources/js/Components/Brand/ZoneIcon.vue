<script setup>
/**
 * Das Zeichen „3." auf seiner Kachel — dasselbe Motiv wie das App-Icon.
 *
 * Die 3 ist eine Linie aus zwei Kurven, der Punkt ist Akzentblau wie in der
 * Wortmarke. Die Kachel ist schwarz; im dunklen Thema `surface`, sonst
 * verschwaende sie im schwarzen Grund. `tone="admin"` faerbt sie rot, damit
 * man im Admin-Bereich nicht vergisst, wo man ist.
 *
 * Groesse ueber die Klasse setzen (`class="h-8 w-8"`).
 */
defineProps({
    tone: { type: String, default: 'default' }, // default | admin
});
</script>

<template>
    <svg viewBox="0 0 100 100" aria-hidden="true" class="shrink-0">
        <rect width="100" height="100" rx="22.5" :class="tone === 'admin' ? 'fill-danger' : 'fill-black dark:fill-surface'" />
        <g transform="translate(50 50) scale(0.88) translate(-54.5 -50.5)">
            <path
                d="M33 22 H48 A13.5 13.5 0 0 1 48 49 A15 15 0 0 1 48 79 H34"
                fill="none"
                stroke="#ffffff"
                stroke-width="11"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
            <circle cx="75" cy="78" r="6.5" :class="tone === 'admin' ? 'fill-white' : 'fill-accent'" />
        </g>
    </svg>
</template>
