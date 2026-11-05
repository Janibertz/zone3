<script setup>
import PageHeader from '@/Components/UI/PageHeader.vue';
import Toggle from '@/Components/UI/Toggle.vue';
import { ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FormGroup from '@/Components/UI/FormGroup.vue';
import FormRow from '@/Components/UI/FormRow.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import { clockInput } from '@/Composables/useFormat';

const props = defineProps({
    track: { type: Object, default: null },
});

const page  = usePage();
const flash = () => page.props.flash ?? {};

const form = useForm({
    // Keine Vorgabe mehr (Befund N7): „Backyard Ultra" stand als Wert im Feld.
    title:         props.track?.title ?? '',
    starts_at:     props.track?.starts_at ?? '',
    yard_km:       props.track?.yard_km ?? 6.706,
    assumed_pace_sec: props.track?.assumed_pace_sec ?? 420,
    target_yards:  props.track?.target_yards ?? null,
    livetrack_url: '',
    is_active:     props.track?.is_active ?? true,
    embed_map:     props.track?.embed_map ?? false,
});

// Endstand von Hand — die Uhr weiss nicht, wenn jemand aufhoert.
const raceForm = useForm({
    stopped_at_yard: props.track?.stopped_at_yard ?? null,
});

function finishRace() {
    raceForm.post(route('live.finish'), { preserveScroll: true });
}

function resumeRace() {
    raceForm.stopped_at_yard = null;
    raceForm.post(route('live.finish'), { preserveScroll: true });
}

// Die Pace wird wie überall als M:SS eingegeben; gespeichert bleibt sie in
// Sekunden pro km (vorher stand „420 Sek./km“ im Formular).
const paceText = ref(form.assumed_pace_sec
    ? `${Math.floor(form.assumed_pace_sec / 60)}:${String(form.assumed_pace_sec % 60).padStart(2, '0')}` : '');
const paceError = ref('');

function save() {
    paceError.value = '';
    const m = /^(\d{1,2}):([0-5]\d)$/.exec(paceText.value.trim());
    if (paceText.value.trim() && !m) {
        paceError.value = 'Pace bitte als M:SS, z. B. 7:00.';
        return;
    }
    form.assumed_pace_sec = m ? Number(m[1]) * 60 + Number(m[2]) : 420;
    form.post(route('live.store'), { preserveScroll: true });
}

const testing = ref(false);
function testConnection() {
    testing.value = true;
    router.post(route('live.test'), {}, {
        preserveScroll: true,
        onFinish: () => { testing.value = false; },
    });
}

const copied = ref(null);
async function copyLink(which) {
    await navigator.clipboard.writeText(which === 'crew' ? props.track.crewUrl : props.track.publicUrl);
    copied.value = which;
    setTimeout(() => { copied.value = null; }, 2000);
}
</script>

<template>
    <Head title="Live-Verfolgung" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-3xl" :back="route('more')" title="Live-Verfolgung"
                subtitle="Eine öffentliche Seite, auf der Freunde dein Backyard-Rennen mitverfolgen." />
            <div class="mx-auto max-w-3xl space-y-7 px-4 pb-10 pt-3 lg:px-8">

                <div v-if="flash().success" class="rounded-card bg-success-soft px-5 py-3.5">
                    <p class="text-[15px] font-semibold text-success-ink">{{ flash().success }}</p>
                </div>

                <!-- Die Links -->
                <template v-if="track">
                    <FormGroup title="Dein Link" footer="Wer den Link hat, sieht die Seite, ohne Login. Sie ist nirgends verlinkt, der Schlüssel lässt sich nicht erraten, Name und Profilbild stehen nicht darauf.">
                        <p class="break-all px-4 py-3 font-mono text-[13px] text-ink-2">{{ track.publicUrl }}</p>
                        <button type="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2" @click="copyLink('public')">
                            {{ copied === 'public' ? 'Kopiert' : 'Link kopieren' }}
                        </button>
                        <a :href="track.publicUrl" class="flex min-h-[48px] items-center px-4 text-[17px] text-accent active:bg-surface-2">Seite ansehen</a>
                    </FormGroup>

                    <FormGroup title="Link für deine Crew" footer="Zeigt zusätzlich eine Steuerleiste: Lagemeldung schreiben und das Rennende festhalten. Nur an die Crew geben, wer ihn hat, kann die Seite verändern.">
                        <p class="break-all px-4 py-3 font-mono text-[13px] text-ink-2">{{ track.crewUrl }}</p>
                        <button type="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2" @click="copyLink('crew')">
                            {{ copied === 'crew' ? 'Kopiert' : 'Crew-Link kopieren' }}
                        </button>
                        <a :href="track.crewUrl" class="flex min-h-[48px] items-center px-4 text-[17px] text-accent active:bg-surface-2">Öffnen</a>
                    </FormGroup>
                </template>

                <!-- Rennen -->
                <FormGroup title="Rennen" footer="Die Runden-Uhr rechnet allein aus dem Start und läuft weiter, auch wenn Garmin ausfällt. Mit der Pace zählt die Distanz in der laufenden Runde weich hoch; die Seite schreibt dann „geschätzt“ dazu.">
                    <FormRow as="label" label="Titel">
                        <input v-model="form.title" type="text" placeholder="z. B. Backyard Hannover" class="z-inline w-48" />
                    </FormRow>
                    <FormRow as="label" label="Start">
                        <input v-model="form.starts_at" type="datetime-local" class="z-inline" />
                    </FormRow>
                    <FormRow as="label" label="Rundenlänge">
                        <input v-model="form.yard_km" type="number" step="0.001" inputmode="decimal" class="z-inline w-20" />
                        <span class="text-[17px] text-ink-3">km</span>
                    </FormRow>
                    <FormRow as="label" label="Angenommene Pace">
                        <input :value="paceText" @input="paceText = clockInput($event)" type="text" inputmode="numeric" autocomplete="off" placeholder="7:00" class="z-inline w-16" />
                        <span class="text-[17px] text-ink-3">/km</span>
                    </FormRow>
                    <FormRow as="label" label="Zielrunden">
                        <input v-model="form.target_yards" type="number" min="1" inputmode="numeric" placeholder="optional" class="z-inline w-24" />
                    </FormRow>
                    <FormRow label="Seite ist aktiv">
                        <Toggle v-model="form.is_active" label="Seite ist aktiv" />
                    </FormRow>
                </FormGroup>
                <p v-for="e in [form.errors.title, form.errors.starts_at, form.errors.yard_km, form.errors.assumed_pace_sec, paceError].filter(Boolean)" :key="e" class="z-error mx-4 -mt-5">{{ e }}</p>

                <!-- Garmin -->
                <FormGroup title="Garmin LiveTrack">
                    <FormRow label="LiveTrack-Link" hint="Bekommst du, wenn du LiveTrack in Garmin Connect startest, also erst am Renntag mit der Aktivität." stacked>
                        <input v-model="form.livetrack_url" type="text" class="z-input" placeholder="https://livetrack.garmin.com/session/…" />
                    </FormRow>
                    <template v-if="track">
                        <FormRow label="Verbindung">
                            <span class="text-[17px]" :class="track.hasLiveTrack ? 'text-success-ink' : 'text-ink-3'">{{ track.hasLiveTrack ? 'hinterlegt' : 'noch keine' }}</span>
                        </FormRow>
                        <FormRow v-if="track.lastPolledAt" label="Zuletzt abgefragt">
                            <span class="text-[17px] text-ink-3">{{ track.lastPolledAt }}</span>
                        </FormRow>
                        <FormRow v-if="track.distanceKm != null" label="Distanz">
                            <span class="text-[17px] tabular-nums text-ink-3">{{ Number(track.distanceKm).toLocaleString('de-DE') }} km</span>
                        </FormRow>
                    </template>
                    <FormRow label="Karte von Garmin einbetten" hint="Dann steht die LiveTrack-Adresse im Quelltext der öffentlichen Seite, also der Link, den du deinen Freunden ohnehin schickst.">
                        <Toggle v-model="form.embed_map" label="Karte von Garmin einbetten" />
                    </FormRow>
                    <button v-if="track?.hasLiveTrack" type="button" :disabled="testing"
                        class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2 disabled:opacity-50" @click="testConnection">
                        {{ testing ? 'Teste …' : 'Verbindung jetzt testen' }}
                    </button>
                </FormGroup>
                <p v-if="form.errors.livetrack_url" class="z-error mx-4 -mt-5">{{ form.errors.livetrack_url }}</p>
                <p v-if="track?.lastError" class="z-error mx-4 -mt-5">{{ track.lastError }}</p>

                <AppButton block :loading="form.processing" @click="save">Sichern</AppButton>

                <!-- Rennsteuerung -->
                <FormGroup v-if="track" title="Am Renntag"
                    footer="Die Uhr zählt jede Stunde eine Runde weiter. Wenn du aufhörst, trag die endgültige Rundenzahl ein, dann friert die Seite dort ein. Deine Crew kann das auch über ihren Link.">
                    <template v-if="track.stopped_at_yard !== null">
                        <FormRow label="Endstand">
                            <span class="text-[17px] tabular-nums text-ink-3">{{ track.stopped_at_yard }} {{ track.stopped_at_yard === 1 ? 'Runde' : 'Runden' }}</span>
                        </FormRow>
                        <button type="button" class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-accent active:bg-surface-2" @click="resumeRace">Doch noch unterwegs</button>
                    </template>
                    <template v-else>
                        <FormRow as="label" label="Endgültige Runden">
                            <input v-model.number="raceForm.stopped_at_yard" type="number" min="0" inputmode="numeric" placeholder="z. B. 17" class="z-inline w-24" />
                        </FormRow>
                        <button type="button" :disabled="raceForm.processing || raceForm.stopped_at_yard === null || raceForm.stopped_at_yard === ''"
                            class="flex min-h-[48px] w-full items-center px-4 text-[17px] text-danger active:bg-surface-2 disabled:opacity-40" @click="finishRace">Rennen beenden</button>
                    </template>
                </FormGroup>

                <!-- Ehrliche Einordnung -->
                <FormGroup title="Was du wissen solltest" plain>
                    <ul class="space-y-2 text-[15px] leading-relaxed text-ink-2">
                        <li>Garmin bietet für LiveTrack keine offizielle Schnittstelle. Zone3 nutzt dieselben Adressen wie Garmins Weboberfläche, das kann jederzeit aufhören zu funktionieren.</li>
                        <li>Lauf das Rennen als <strong class="text-ink">eine</strong> Aktivität und nutz nur die Runden-Taste. Mit einer neuen Aktivität ändert sich der LiveTrack-Link.</li>
                        <li>Dein Handy braucht durchgehend Empfang und Strom.</li>
                        <li>Body Battery und HRV kommen nicht live, erst mit dem Sync danach.</li>
                    </ul>
                </FormGroup>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
