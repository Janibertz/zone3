<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    queues:       Array,
    failedJobs:   Object,
    planHealth:   Object,
    integrations: Object,
    environment:  Object,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const busy = ref(null);

function act(method, url, key, options = {}) {
    busy.value = key;
    router[method](url, {}, {
        preserveScroll: true,
        ...options,
        onFinish: () => { busy.value = null; },
    });
}

// ── Ableitungen ──────────────────────────────────────────────────────────

const queueTrouble = computed(() => props.queues.filter((q) => q.stale).length);

const headline = computed(() => {
    if (queueTrouble.value > 0)              return { tone: 'danger',  text: 'Eine Queue steht — der Worker arbeitet sie nicht ab.' };
    if (props.failedJobs.total > 0)          return { tone: 'warn',    text: `${props.failedJobs.total} fehlgeschlagene Aufgabe(n).` };
    if (props.planHealth.gaps.length > 0)    return { tone: 'warn',    text: `${props.planHealth.gaps.length} Plan/Pläne mit Lücken.` };
    if (props.planHealth.stuck.length > 0)   return { tone: 'warn',    text: 'Eine Plangenerierung hängt.' };
    return { tone: 'success', text: 'Keine Auffälligkeiten.' };
});

const toneClass = {
    success: 'bg-success-soft border-success/25 text-success-ink',
    warn:    'bg-warn-soft border-warn/25 text-warn-ink',
    danger:  'bg-danger-soft border-danger/25 text-danger-ink',
};

function when(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function day(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: '2-digit' });
}

/** Wie lange ist das her, in Tagen — für „kommt hier noch etwas rein?". */
function daysAgo(value) {
    if (!value) return null;
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return null;
    return Math.floor((Date.now() - d.getTime()) / 86400000);
}
</script>

<template>
    <Head title="System" />

    <AdminLayout>
        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-6">

            <!-- Flash -->
            <div v-if="flash.success" class="px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="px-4 py-3 bg-danger-soft border border-danger/25 rounded-field text-sm text-danger-ink">
                {{ flash.error }}
            </div>

            <!-- ── Ein Satz zum Zustand ──────────────────────────────── -->
            <div class="px-4 py-3 border rounded-field text-sm font-medium" :class="toneClass[headline.tone]">
                {{ headline.text }}
            </div>

            <!-- ── Queues ────────────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Warteschlangen</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Nicht die Menge zählt, sondern das Alter: wartet die älteste Aufgabe seit Minuten,
                        arbeitet der Worker dieser Queue nicht.
                    </p>
                </div>

                <div class="grid gap-px bg-line sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="q in queues" :key="q.queue" class="bg-surface px-6 py-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-ink">{{ q.queue }}</p>
                            <span v-if="q.stale" class="px-2 py-0.5 rounded-full text-xs font-medium bg-danger-soft text-danger-ink">steht</span>
                            <span v-else-if="q.pending > 0" class="px-2 py-0.5 rounded-full text-xs font-medium bg-info-soft text-info-ink">läuft</span>
                            <span v-else class="px-2 py-0.5 rounded-full text-xs font-medium bg-success-soft text-success-ink">leer</span>
                        </div>
                        <p class="mt-2 text-2xl font-bold text-ink tabular-nums">{{ q.pending }}</p>
                        <p class="text-xs text-ink-3">
                            wartend<span v-if="q.reserved > 0">, davon {{ q.reserved }} in Arbeit</span>
                        </p>
                        <p v-if="q.pending > 0" class="mt-1 text-xs" :class="q.stale ? 'text-danger-ink font-medium' : 'text-ink-3'">
                            älteste seit {{ q.waiting_min }} min
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Fehlgeschlagene Aufgaben ──────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-ink-2">Fehlgeschlagene Aufgaben ({{ failedJobs.total }})</h2>
                        <p class="text-xs text-ink-3 mt-0.5">Eine Klasse, die mehrfach auftaucht, ist ein Fehler — kein Pech.</p>
                    </div>
                    <div v-if="failedJobs.total > 0" class="flex gap-2">
                        <button
                            class="px-3 py-1.5 rounded-field text-xs font-medium bg-accent text-white disabled:opacity-50"
                            :disabled="busy === 'retry-all'"
                            @click="act('post', route('admin.system.failed.retry-all'), 'retry-all')">
                            Alle erneut versuchen
                        </button>
                        <button
                            class="px-3 py-1.5 rounded-field text-xs font-medium bg-danger-soft text-danger-ink disabled:opacity-50"
                            :disabled="busy === 'flush'"
                            @click="act('delete', route('admin.system.failed.flush'), 'flush')">
                            Liste leeren
                        </button>
                    </div>
                </div>

                <div v-if="failedJobs.byClass.length" class="px-6 py-3 border-b border-line flex flex-wrap gap-2">
                    <span v-for="c in failedJobs.byClass" :key="c.job"
                        class="px-2 py-1 rounded-full text-xs bg-warn-soft text-warn-ink">
                        {{ c.job.split('\\').pop() }} · {{ c.count }}
                    </span>
                </div>

                <p v-if="!failedJobs.recent.length" class="px-6 py-6 text-sm text-ink-3">
                    Nichts fehlgeschlagen.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Aufgabe</th>
                                <th class="px-3 py-2 text-left font-medium">Queue</th>
                                <th class="px-3 py-2 text-left font-medium">Wann</th>
                                <th class="px-3 py-2 text-left font-medium">Grund</th>
                                <th class="px-6 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="j in failedJobs.recent" :key="j.uuid">
                                <td class="px-6 py-3 font-medium text-ink whitespace-nowrap">{{ j.job.split('\\').pop() }}</td>
                                <td class="px-3 py-3 text-ink-3 whitespace-nowrap">{{ j.queue }}</td>
                                <td class="px-3 py-3 text-ink-3 whitespace-nowrap">{{ when(j.failed_at) }}</td>
                                <td class="px-3 py-3 text-ink-3 max-w-md truncate" :title="j.reason">{{ j.reason }}</td>
                                <td class="px-6 py-3 text-right whitespace-nowrap">
                                    <button
                                        class="px-2 py-1 rounded-field text-xs bg-accent-soft text-accent-ink disabled:opacity-50"
                                        :disabled="busy === j.uuid"
                                        @click="act('post', route('admin.system.failed.retry', j.uuid), j.uuid)">
                                        Erneut
                                    </button>
                                    <button
                                        class="ml-1 px-2 py-1 rounded-field text-xs text-ink-3 hover:text-danger-ink disabled:opacity-50"
                                        :disabled="busy === j.uuid"
                                        @click="act('delete', route('admin.system.failed.forget', j.uuid), j.uuid)">
                                        Entfernen
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Plan-Gesundheit ───────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Trainingspläne</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Eine Lücke ist ein Datum innerhalb der eigenen Spanne eines Plans, an dem keine
                        einzige Einheit steht. Gelesen wird die Datenbank, nicht der Verlauf — der zeigt,
                        was das Modell vorhatte.
                    </p>
                </div>

                <div class="grid gap-px bg-line sm:grid-cols-3">
                    <div class="bg-surface px-6 py-4">
                        <p class="text-2xl font-bold tabular-nums" :class="planHealth.gaps.length ? 'text-warn-ink' : 'text-ink'">
                            {{ planHealth.gaps.length }}
                        </p>
                        <p class="text-xs text-ink-3">Pläne mit Lücken</p>
                    </div>
                    <div class="bg-surface px-6 py-4">
                        <p class="text-2xl font-bold tabular-nums" :class="planHealth.orphans_planned ? 'text-warn-ink' : 'text-ink'">
                            {{ planHealth.orphans_planned }}
                        </p>
                        <p class="text-xs text-ink-3">
                            geplante Einheiten ohne Plan — unsichtbar für den Athleten
                            <span class="block">({{ planHealth.orphans_total }} insgesamt ohne Plan)</span>
                        </p>
                    </div>
                    <div class="bg-surface px-6 py-4">
                        <p class="text-2xl font-bold tabular-nums" :class="planHealth.stuck.length ? 'text-danger-ink' : 'text-ink'">
                            {{ planHealth.stuck.length }}
                        </p>
                        <p class="text-xs text-ink-3">hängende Generierungen</p>
                    </div>
                </div>

                <div v-if="planHealth.gaps.length" class="divide-y divide-line border-t border-line">
                    <div v-for="g in planHealth.gaps" :key="g.user_id" class="px-6 py-4 flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <Link :href="route('admin.users.show', g.user_id)" class="text-sm font-medium text-ink hover:text-accent-ink">
                                {{ g.name }}
                            </Link>
                            <p class="text-xs text-ink-3 mt-0.5">
                                <span v-for="d in g.dates.slice(0, 6)" :key="d" class="mr-2">{{ day(d) }}</span>
                                <span v-if="g.dates.length > 6">+{{ g.dates.length - 6 }} weitere</span>
                            </p>
                        </div>
                        <button
                            class="px-3 py-1.5 rounded-field text-xs font-medium bg-warn-soft text-warn-ink disabled:opacity-50"
                            :disabled="busy === `gap-${g.user_id}`"
                            @click="act('post', route('admin.system.plan-gaps.fill', g.user_id), `gap-${g.user_id}`)">
                            Mit Ruhetagen schliessen
                        </button>
                    </div>
                </div>

                <div v-if="planHealth.stuck.length" class="border-t border-line divide-y divide-line">
                    <div v-for="s in planHealth.stuck" :key="s.event_id" class="px-6 py-3 text-sm">
                        <span class="text-ink font-medium">{{ s.name }}</span>
                        <span class="text-ink-3"> — generiert seit {{ when(s.since) }}</span>
                    </div>
                </div>
            </div>

            <!-- ── Anbindungen ───────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Strava</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Die Frage ist nicht, ob verbunden — die bleibt grün, auch wenn seit Tagen nichts
                        mehr ankommt. Die Frage ist, wann zuletzt etwas importiert wurde.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Athlet</th>
                                <th class="px-3 py-2 text-left font-medium">Token</th>
                                <th class="px-3 py-2 text-left font-medium">Letzte Aktivität</th>
                                <th class="px-3 py-2 text-left font-medium">Zuletzt importiert</th>
                                <th class="px-6 py-2 text-right font-medium">Gesamt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="s in integrations.strava" :key="s.user_id">
                                <td class="px-6 py-3">
                                    <Link :href="route('admin.users.show', s.user_id)" class="font-medium text-ink hover:text-accent-ink">
                                        {{ s.name }}
                                    </Link>
                                </td>
                                <td class="px-3 py-3">
                                    <span v-if="s.token_expired" class="px-2 py-0.5 rounded-full text-xs bg-warn-soft text-warn-ink">abgelaufen</span>
                                    <span v-else class="px-2 py-0.5 rounded-full text-xs bg-success-soft text-success-ink">gültig</span>
                                </td>
                                <td class="px-3 py-3 whitespace-nowrap"
                                    :class="daysAgo(s.last_activity_at) > 10 ? 'text-warn-ink font-medium' : 'text-ink-3'">
                                    {{ day(s.last_activity_at) }}
                                    <span v-if="daysAgo(s.last_activity_at) !== null" class="text-xs">
                                        ({{ daysAgo(s.last_activity_at) }} T)
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-ink-3 whitespace-nowrap">{{ when(s.last_import_at) }}</td>
                                <td class="px-6 py-3 text-right text-ink-3 tabular-nums">{{ s.activities }}</td>
                            </tr>
                            <tr v-if="!integrations.strava.length">
                                <td colspan="5" class="px-6 py-6 text-sm text-ink-3">Kein Konto verbunden.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="integrations.garmin.length" class="border-t border-line">
                    <div class="px-6 py-3 border-b border-line">
                        <h3 class="text-sm font-semibold text-ink-2">Garmin</h3>
                    </div>
                    <div class="divide-y divide-line">
                        <div v-for="g in integrations.garmin" :key="g.user_id" class="px-6 py-3 flex items-center justify-between text-sm">
                            <span class="text-ink">{{ g.name }}</span>
                            <span class="text-ink-3">
                                zuletzt {{ day(g.last_date) }} · {{ g.days }} Tage
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Umgebung ──────────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Umgebung</h2>
                </div>
                <dl class="grid gap-px bg-line sm:grid-cols-2 lg:grid-cols-4">
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">PHP</dt>
                        <dd class="text-sm text-ink font-medium">{{ environment.php }}</dd>
                    </div>
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">Laravel</dt>
                        <dd class="text-sm text-ink font-medium">{{ environment.laravel }}</dd>
                    </div>
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">Umgebung</dt>
                        <dd class="text-sm font-medium" :class="environment.debug ? 'text-danger-ink' : 'text-ink'">
                            {{ environment.env }}<span v-if="environment.debug"> · Debug an</span>
                        </dd>
                    </div>
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">Datenbank</dt>
                        <dd class="text-sm text-ink font-medium">
                            {{ environment.db_driver }}<span v-if="environment.db_size_mb"> · {{ environment.db_size_mb }} MB</span>
                        </dd>
                    </div>
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">Queue</dt>
                        <dd class="text-sm text-ink font-medium">{{ environment.queue_driver }}</dd>
                    </div>
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">Cache</dt>
                        <dd class="text-sm text-ink font-medium">{{ environment.cache_driver }}</dd>
                    </div>
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">Hauptmodell</dt>
                        <dd class="text-sm text-ink font-medium">{{ environment.model }}</dd>
                    </div>
                    <div class="bg-surface px-6 py-3">
                        <dt class="text-xs text-ink-3">Mini-Modell</dt>
                        <dd class="text-sm text-ink font-medium">{{ environment.model_mini }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </AdminLayout>
</template>
