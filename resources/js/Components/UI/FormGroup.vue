<script setup>
/**
 * Gruppierte Liste nach iOS: Überschrift klein und grau über der Karte,
 * Erklärung klein und grau darunter, dazwischen Zeilen mit eingerückter
 * Trennlinie (`.z-group` in app.css). Ersetzt die Karten mit eigener
 * Kopfzeile und grauen Kästen darin, die Profil, Onboarding und Live noch
 * aus der Zeit vor dem Redesign trugen.
 */
defineProps({
    title:  { type: String, default: null },
    footer: { type: String, default: null },
    /** Ohne Zeilen-Trennlinien — für Inhalt mit eigenem Innenabstand. */
    plain:  { type: Boolean, default: false },
});
</script>

<template>
    <section>
        <h2 v-if="title || $slots.title" class="mx-4 mb-1.5 text-[13px] text-ink-3">
            <slot name="title">{{ title }}</slot>
        </h2>
        <div class="overflow-hidden rounded-card bg-surface" :class="plain ? 'p-4' : 'z-group'">
            <slot />
        </div>
        <div v-if="footer || $slots.footer" class="mx-4 mt-1.5 text-[13px] leading-snug text-ink-3">
            <slot name="footer">{{ footer }}</slot>
        </div>
    </section>
</template>
