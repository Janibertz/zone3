<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';

const props = defineProps({
    show: Boolean,
});

const emit = defineEmits(['close', 'saved']);

const today = new Date();

const blank = () => ({
    energy_level:    5,
    mood:            5,
    sleep_quality:   5,
    muscle_soreness: 5,
    stress_level:    5,
    notes:           '',
    is_sick:         false,
    is_injured:      false,
});

const form       = ref(blank());
const submitting = ref(false);
const loadError  = ref('');
const saveError  = ref('');

/**
 * The five sliders, so the markup stays a single loop. `low`/`high` name the
 * ends in words: a bare 1–10 says nothing about which end is good, and for
 * soreness and stress the good end is the low one.
 */
const scales = [
    { key: 'energy_level',    label: 'Energie',     low: 'leer',      high: 'voll' },
    { key: 'mood',            label: 'Stimmung',    low: 'mies',      high: 'super' },
    { key: 'sleep_quality',   label: 'Schlaf',      low: 'schlecht',  high: 'super' },
    { key: 'muscle_soreness', label: 'Muskelkater', low: 'keiner',    high: 'stark' },
    { key: 'stress_level',    label: 'Stress',      low: 'entspannt', high: 'hoch' },
];

const flags = [
    { key: 'is_sick',    label: 'Ich bin krank' },
    { key: 'is_injured', label: 'Ich bin verletzt' },
];

// Soreness and stress are inverted — high values are bad.
const score = computed(() => {
    const f = form.value;
    const total = f.energy_level + f.mood + f.sleep_quality
        + (10 - f.muscle_soreness) + (10 - f.stress_level);
    return total / 5;
});

const verdict = computed(() => {
    if (form.value.is_sick || form.value.is_injured) {
        return { text: 'Heute nicht trainieren', tone: 'text-danger' };
    }
    if (score.value >= 8) return { text: 'Bereit für hartes Training', tone: 'text-success' };
    if (score.value >= 6) return { text: 'Moderates Training möglich', tone: 'text-accent' };
    if (score.value >= 4) return { text: 'Nur leichtes Training',      tone: 'text-warn' };
    return { text: 'Ruhetag empfohlen', tone: 'text-danger' };
});

const scoreLabel = computed(() => score.value.toLocaleString('de-DE', {
    minimumFractionDigits: 1,
    maximumFractionDigits: 1,
}));

const formatDate = (date) => date.toLocaleDateString('de-DE', {
    weekday: 'long',
    day:     'numeric',
    month:   'long',
});

async function submitWellbeing() {
    submitting.value = true;
    saveError.value  = '';
    try {
        const res = await axios.post('/api/wellbeing', form.value);
        emit('saved', res.data);
        emit('close');
    } catch (error) {
        saveError.value = error.response?.data?.message
            ?? 'Speichern fehlgeschlagen. Bitte noch einmal versuchen.';
    } finally {
        submitting.value = false;
    }
}

// Pull today's entry when the sheet opens so an edit continues where it left off.
watch(() => props.show, async (open) => {
    if (!open) return;

    loadError.value = '';
    saveError.value = '';
    try {
        const { data } = await axios.get('/api/wellbeing/today');
        if (data) {
            form.value = {
                energy_level:    data.energy_level    || 5,
                mood:            data.mood            || 5,
                sleep_quality:   data.sleep_quality   || 5,
                muscle_soreness: data.muscle_soreness || 5,
                stress_level:    data.stress_level    || 5,
                notes:           data.notes           || '',
                is_sick:         data.is_sick         || false,
                is_injured:      data.is_injured      || false,
            };
        }
    } catch {
        loadError.value = 'Der heutige Eintrag konnte nicht geladen werden — du startest bei den Standardwerten.';
        form.value = blank();
    }
});
</script>

<template>
    <AppSheet
        :show="show"
        title="Wie geht's dir heute?"
        :subtitle="`Tagesform · ${formatDate(today)}`"
        max-width="lg"
        @close="$emit('close')"
    >
        <p v-if="loadError" class="mb-4 rounded-card bg-warn-soft p-3 text-[13px] text-warn-ink">{{ loadError }}</p>

        <div class="space-y-6 pt-1">
            <!-- Ergebnis zuerst: es antwortet live auf jede Skala -->
            <div class="flex items-baseline justify-between rounded-card bg-surface-2 px-4 py-3" aria-live="polite">
                <p class="text-[15px] font-semibold" :class="verdict.tone">{{ verdict.text }}</p>
                <p class="font-rounded text-[28px] font-bold leading-none tabular-nums text-ink">
                    {{ scoreLabel }}<span class="ml-1 text-[15px] font-semibold text-ink-3">/ 10</span>
                </p>
            </div>

            <!--
                Skalen als Segmente statt Schieberegler (Redesign Paket 3,
                28.10.2026): ein Tipp statt Ziehen, und der Wert steht da,
                wo der Finger war. Die Skala bleibt 1 bis 10 — sie ist so
                gespeichert und so in den Prompts beschrieben.
            -->
            <div v-for="s in scales" :key="s.key" role="radiogroup" :aria-label="s.label">
                <div class="mb-2 flex items-baseline justify-between px-1">
                    <span class="text-[17px] font-semibold text-ink">{{ s.label }}</span>
                    <span class="text-[13px] text-ink-3">1 {{ s.low }} · 10 {{ s.high }}</span>
                </div>
                <div class="grid grid-cols-10 gap-[3px] rounded-[10px] bg-surface-2 p-[3px]">
                    <button v-for="n in 10" :key="n" type="button" role="radio" :aria-checked="form[s.key] === n"
                        class="h-9 rounded-[7px] text-[15px] font-semibold tabular-nums transition-colors duration-150"
                        :class="form[s.key] === n ? 'bg-accent text-white' : 'text-ink-2 active:bg-surface-3'"
                        @click="form[s.key] = n">{{ n }}</button>
                </div>
            </div>

            <!-- Krank / verletzt als Schalter -->
            <div class="divide-y divide-line rounded-card bg-surface-2">
                <div v-for="f in flags" :key="f.key" class="flex min-h-[52px] items-center justify-between gap-4 px-4">
                    <span class="text-[17px] text-ink">{{ f.label }}</span>
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="form[f.key]"
                        :aria-label="f.label"
                        class="relative inline-flex h-[31px] w-[51px] shrink-0 rounded-full p-0.5 transition-colors duration-200 ease-out-strong"
                        :class="form[f.key] ? 'bg-danger' : 'bg-surface-3'"
                        @click="form[f.key] = !form[f.key]"
                    >
                        <span
                            class="h-[27px] w-[27px] rounded-full bg-white shadow-[0_2px_4px_rgb(0_0_0/0.2)] transition-transform duration-200 ease-out-strong"
                            :class="form[f.key] ? 'translate-x-5' : 'translate-x-0'"
                        />
                    </button>
                </div>
            </div>

            <!-- Notiz -->
            <div>
                <label for="wellbeing-notes" class="mb-2 block px-1 text-[13px] text-ink-3">Notiz für den Coach (optional)</label>
                <textarea
                    id="wellbeing-notes"
                    v-model="form.notes"
                    rows="3"
                    class="z-input resize-none"
                    placeholder="z. B. Knie zwickt, wenig geschlafen, Zahn-OP …"
                />
                <p class="mt-1.5 px-1 text-[13px] text-ink-3">Der Coach liest sie mit und richtet die Empfehlung danach.</p>
            </div>
        </div>

        <template #footer>
            <p v-if="saveError" class="mb-3 text-[13px] text-danger">{{ saveError }}</p>
            <AppButton block :loading="submitting" @click="submitWellbeing">
                {{ submitting ? 'Speichert…' : 'Speichern' }}
            </AppButton>
        </template>
    </AppSheet>
</template>
