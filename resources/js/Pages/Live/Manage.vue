<script setup>
import { ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/UI/AppCard.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import SectionHeader from '@/Components/UI/SectionHeader.vue';

const props = defineProps({
    track: { type: Object, default: null },
});

const page  = usePage();
const flash = () => page.props.flash ?? {};

const form = useForm({
    title:         props.track?.title ?? 'Backyard Ultra',
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

function save() {
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
            <div class="space-y-5 px-4 py-5 lg:px-6">

                <header class="px-1">
                    <h1 class="text-2xl font-bold tracking-tight text-ink">Live-Verfolgung</h1>
                    <p class="mt-1 text-[15px] text-ink-3">
                        Eine öffentliche Seite, auf der Freunde deinen Lauf mitverfolgen können.
                    </p>
                </header>

                <div v-if="flash().success" class="rounded-card bg-success-soft px-5 py-3.5">
                    <p class="text-[15px] font-semibold text-success-ink">{{ flash().success }}</p>
                </div>

                <!-- Der Link -->
                <section v-if="track">
                    <SectionHeader title="Dein Link" />
                    <AppCard>
                        <p class="break-all rounded-field bg-surface-2 px-4 py-3 font-mono text-[13px] text-ink">
                            {{ track.publicUrl }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <AppButton size="sm" @click="copyLink('public')">{{ copied === 'public' ? 'Kopiert' : 'Link kopieren' }}</AppButton>
                            <AppButton size="sm" variant="secondary" :href="track.publicUrl">Seite ansehen</AppButton>
                        </div>
                        <p class="mt-3 text-[13px] leading-relaxed text-ink-3">
                            Wer den Link hat, sieht die Seite — ohne Login. Sie ist nirgends verlinkt
                            und der Schlüssel lässt sich nicht erraten. Name und Profilbild werden
                            <strong class="text-ink-2">nicht</strong> angezeigt.
                        </p>

                        <!-- Crew -->
                        <div class="mt-5 border-t border-line pt-5">
                            <p class="text-[15px] font-semibold text-ink">Link für deine Crew</p>
                            <p class="mt-1 text-[13px] leading-relaxed text-ink-3">
                                Zeigt zusätzlich eine Steuerleiste: Lagemeldung schreiben und das
                                Rennende festhalten. <strong class="text-ink-2">Nur an die Crew geben</strong> —
                                wer ihn hat, kann die Seite verändern.
                            </p>
                            <p class="mt-3 break-all rounded-field bg-surface-2 px-4 py-3 font-mono text-[13px] text-ink">
                                {{ track.crewUrl }}
                            </p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <AppButton size="sm" @click="copyLink('crew')">{{ copied === 'crew' ? 'Kopiert' : 'Crew-Link kopieren' }}</AppButton>
                                <AppButton size="sm" variant="secondary" :href="track.crewUrl">Öffnen</AppButton>
                            </div>
                        </div>
                    </AppCard>
                </section>

                <!-- Einstellungen -->
                <section>
                    <SectionHeader title="Rennen" />
                    <AppCard>
                        <div class="space-y-4">
                            <div>
                                <label class="z-label">Titel</label>
                                <input v-model="form.title" type="text" class="z-input" />
                                <p v-if="form.errors.title" class="z-error">{{ form.errors.title }}</p>
                            </div>

                            <div>
                                <label class="z-label">Start</label>
                                <input v-model="form.starts_at" type="datetime-local" class="z-input" />
                                <p class="z-hint">Die Yard-Uhr rechnet allein hieraus — sie läuft auch weiter, wenn Garmin ausfällt.</p>
                                <p v-if="form.errors.starts_at" class="z-error">{{ form.errors.starts_at }}</p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label class="z-label">Rundenlänge (km)</label>
                                    <input v-model="form.yard_km" type="number" step="0.001" class="z-input" />
                                </div>
                                <div>
                                    <label class="z-label">Angenommene Pace (Sek./km)</label>
                                    <input v-model.number="form.assumed_pace_sec" type="number" min="120" max="1800" class="z-input" />
                                    <p class="z-hint">
                                        420 = 7:00/km. Damit zählt die Distanz innerhalb der laufenden Runde
                                        weich hoch, statt einmal pro Stunde zu springen. Auf der Seite steht
                                        dabei ausdrücklich „geschätzt".
                                    </p>
                                </div>
                                <div>
                                    <label class="z-label">Zielrunden <span class="font-normal text-ink-3">(optional)</span></label>
                                    <input v-model="form.target_yards" type="number" min="1" class="z-input" />
                                </div>
                            </div>

                            <label class="flex cursor-pointer items-center gap-3">
                                <input v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-line-strong text-accent focus:ring-accent" />
                                <span class="text-[15px] text-ink">Seite ist aktiv</span>
                            </label>

                            <AppButton block :loading="form.processing" @click="save">Speichern</AppButton>
                        </div>
                    </AppCard>
                </section>

                <!-- Garmin -->
                <section>
                    <SectionHeader title="Garmin LiveTrack" />
                    <AppCard>
                        <div>
                            <label class="z-label">LiveTrack-Link</label>
                            <input
                                v-model="form.livetrack_url"
                                type="text"
                                class="z-input"
                                placeholder="https://livetrack.garmin.com/session/…/token/…"
                            />
                            <p class="z-hint">
                                Den bekommst du, wenn du LiveTrack in der Garmin-Connect-App startest.
                                <strong class="text-ink-2">Erst am Renntag verfügbar</strong> — die Sitzung entsteht mit der Aktivität.
                            </p>
                            <p v-if="form.errors.livetrack_url" class="z-error">{{ form.errors.livetrack_url }}</p>
                        </div>

                        <div v-if="track" class="mt-4 rounded-field bg-surface-2 px-4 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-[13px] text-ink-3">Verbindung</span>
                                <span class="text-[13px] font-semibold" :class="track.hasLiveTrack ? 'text-success' : 'text-ink-3'">
                                    {{ track.hasLiveTrack ? 'hinterlegt' : 'noch nicht hinterlegt' }}
                                </span>
                            </div>
                            <div v-if="track.lastPolledAt" class="mt-2 flex items-center justify-between gap-3">
                                <span class="text-[13px] text-ink-3">Zuletzt abgefragt</span>
                                <span class="text-[13px] text-ink-2">{{ track.lastPolledAt }}</span>
                            </div>
                            <div v-if="track.distanceKm != null" class="mt-2 flex items-center justify-between gap-3">
                                <span class="text-[13px] text-ink-3">Distanz</span>
                                <span class="text-[13px] font-semibold tabular-nums text-ink">{{ track.distanceKm }} km</span>
                            </div>
                            <p v-if="track.lastError" class="mt-3 text-[13px] text-danger">{{ track.lastError }}</p>
                        </div>

                        <label class="mt-4 flex cursor-pointer items-start gap-3">
                            <input v-model="form.embed_map" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-line-strong text-accent focus:ring-accent" />
                            <span>
                                <span class="block text-[15px] text-ink">Garmins Karte auf der Seite einbetten</span>
                                <span class="mt-0.5 block text-[13px] text-ink-3">
                                    Dann steht die LiveTrack-Adresse im Quelltext der öffentlichen Seite —
                                    also genau der Link, den du deinen Freunden ohnehin schickst.
                                    Ohne Haken zeigt die Seite keine Karte.
                                </span>
                            </span>
                        </label>

                        <AppButton block class="mt-4" :loading="form.processing" @click="save">Speichern</AppButton>

                        <AppButton v-if="track?.hasLiveTrack" variant="secondary" block class="mt-2"
                            :loading="testing" @click="testConnection">
                            Verbindung jetzt testen
                        </AppButton>
                    </AppCard>
                </section>

                <!-- Rennsteuerung -->
                <section v-if="track">
                    <SectionHeader title="Am Renntag" />
                    <AppCard>
                        <p class="text-[15px] leading-relaxed text-ink-3">
                            Die Uhr zählt automatisch jede Stunde eine Runde weiter. Wenn du aufhörst,
                            trag hier die endgültige Rundenzahl ein — die öffentliche Seite friert dann
                            dort ein. Deine Crew kann das auch über ihren Link erledigen.
                        </p>

                        <div v-if="track.stopped_at_yard !== null" class="mt-4 rounded-field bg-surface-2 px-4 py-3">
                            <p class="text-[15px] font-semibold text-ink">
                                Endstand: {{ track.stopped_at_yard }} {{ track.stopped_at_yard === 1 ? 'Runde' : 'Runden' }}
                            </p>
                            <AppButton size="sm" variant="secondary" class="mt-3" @click="resumeRace">
                                Doch noch unterwegs
                            </AppButton>
                        </div>

                        <div v-else class="mt-4 space-y-3">
                            <div>
                                <label class="z-label">Endgültige Rundenzahl</label>
                                <input v-model.number="raceForm.stopped_at_yard" type="number" min="0" class="z-input" placeholder="z.B. 17" />
                                <p class="z-hint">Solange das leer bleibt, zählt die Uhr automatisch weiter.</p>
                            </div>
                            <AppButton block :loading="raceForm.processing" @click="finishRace">
                                Rennen beenden
                            </AppButton>
                        </div>
                    </AppCard>
                </section>

                <!-- Ehrliche Einordnung -->
                <AppCard>
                    <p class="text-[15px] font-semibold text-ink">Was du wissen solltest</p>
                    <ul class="mt-2 space-y-2 text-[15px] leading-relaxed text-ink-3">
                        <li>Garmin bietet für LiveTrack keine offizielle Schnittstelle. Zone3 nutzt dieselben Adressen wie Garmins eigene Weboberfläche — das kann jederzeit aufhören zu funktionieren.</li>
                        <li>Lauf das Rennen als <strong class="text-ink-2">eine</strong> Aktivität und nutz nur die Runden-Taste. Startest du eine neue Aktivität, ändert sich der LiveTrack-Link und die Seite verliert die Verbindung.</li>
                        <li>Dein Handy braucht durchgehend Empfang und Strom, sonst kommen keine Werte an.</li>
                        <li>Body Battery, HRV und Readiness sind nicht live verfügbar — die kommen erst beim Sync danach.</li>
                    </ul>
                </AppCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
