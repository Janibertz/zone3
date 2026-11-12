<script setup>
/**
 * Erklärung und Haken zur Übermittlung an OpenAI — im Onboarding (Coach)
 * und im Sheet für ältere Konten derselbe Wortlaut.
 */
const checked = defineModel({ type: Boolean, default: false });
</script>

<template>
    <div class="space-y-3">
        <p class="text-[15px] leading-snug text-ink-2">
            Plan, Auswertungen und die Antworten deines Coachs schreibt ein KI-Modell von <strong class="text-ink">OpenAI</strong>.
            Dafür schickt Zone3 bei jeder Anfrage die Daten, die die Aufgabe braucht: Trainings, Ziele, Tagesform,
            deine Nachrichten an den Coach und, wenn du sie verbunden hast, Erholungswerte deiner Uhr.
            OpenAI trainiert damit keine Modelle.
        </p>
        <label class="flex cursor-pointer items-start gap-3 rounded-field bg-surface-2 px-4 py-3">
            <input v-model="checked" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-line-strong text-accent focus:ring-accent/40">
            <span class="text-[15px] leading-snug text-ink">
                Ich willige ein, dass Zone3 diese Daten an OpenAI übermittelt.
                <a href="/privacy#ki" target="_blank" class="text-accent">Mehr in der Datenschutzerklärung</a>
            </span>
        </label>
    </div>
</template>
