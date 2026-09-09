<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmSheet from '@/Components/UI/ConfirmSheet.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    window:      Number,
    windows:     Object,
    summary:     Object,
    routes:      Array,
    slowQueries: Array,
    jobs:        Array,
    outgoing:    Array,
    commands:    Array,
    slowest:     Array,
    findings:    Array,
    openAlerts:  Array,
    thresholds:  Object,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const busy       = ref(false);
const confirming = ref(false);

function switchWindow(hours) {
    router.get(route('admin.performance.index'), { hours }, {
        preserveScroll: true,
        preserveState:  true,
        only: ['window', 'summary', 'routes', 'slowQueries', 'jobs', 'outgoing', 'slowest'],
    });
}

function runCheck() {
    busy.value = true;
    router.post(route('admin.performance.check'), {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; },
    });
}

function flush() {
    busy.value = true;
    router.delete(route('admin.performance.flush'), {
        preserveScroll: true,
        onFinish: () => { busy.value = false; confirming.value = false; },
    });
}

/** „340 ms" bis 999, danach „1,2 s" — ab da liest sich die Sekunde besser. */
function ms(value) {
    if (value === null || value === undefined) return '–';
    if (value < 1000) return `${value} ms`;
    return `${(value / 1000).toFixed(1).replace('.', ',')} s`;
}

function ago(minutes) {
    if (minutes === null || minutes === undefined) return 'nie';
    if (minutes < 1)    return 'gerade eben';
    if (minutes < 60)   return `vor ${minutes} Min`;
    if (minutes < 1440) return `vor ${Math.floor(minutes / 60)} Std`;
    return `vor ${Math.floor(minutes / 1440)} Tg`;
}

function clock(iso) {
    if (!iso) return '–';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '–' : d.toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <Head title="Performance" />

    <AdminLayout>
        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-6">

            <div v-if="flash.success" class="px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                {{ flash.success }}
            </div>

            <!-- ── Kopf: Zeitraum und Eingriffe ─────────────────────────── -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex gap-1.5">
                    <button
                        v-for="(hours, label) in windows" :key="label"
                        class="rounded-full px-3 py-1 text-xs font-medium transition-colors"
                        :class="window === hours ? 'bg-accent text-white' : 'bg-surface-2 text-ink-2 hover:text-ink'"
                        @click="switchWindow(hours)">
                        {{ label }}
                    </button>
                </div>

                <div class="flex gap-2">
                    <button
                        class="rounded-field bg-surface-2 px-3 py-1.5 text-xs font-medium text-ink-2 hover:text-ink disabled:opacity-50"
                        :disabled="busy" @click="runCheck">
                        Jetzt prüfen
                    </button>
                    <button
                        class="rounded-field px-3 py-1.5 text-xs font-medium text-ink-3 hover:text-danger-ink disabled:opacity-50"
                        :disabled="busy" @click="confirming = true">
                        Messwerte verwerfen
                    </button>
                </div>
            </div>

            <!-- ── Offene Befunde ───────────────────────────────────────── -->
            <div v-if="findings.length" class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Auffälligkeiten ({{ findings.length }})</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Dieselben Befunde, die der Wächter alle 15 Minuten prüft und per Push meldet —
                        hier ohne Nebenwirkung, ein Seitenaufruf löst also keine Nachricht aus.
                    </p>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="f in findings" :key="f.key" class="px-6 py-3 flex items-start gap-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-warn" />
                        <div class="min-w-0">
                            <p class="text-sm text-ink">{{ f.message }}</p>
                            <p class="text-xs text-ink-3 font-mono">{{ f.key }}</p>
                        </div>
                    </li>
                </ul>
            </div>

            <div v-else class="bg-surface rounded-card shadow-card px-6 py-4 flex items-center gap-2">
                <span class="h-2 w-2 rounded-full bg-success" />
                <span class="text-sm text-ink-2">Keine Auffälligkeiten.</span>
            </div>

            <!-- ── Zusammenfassung ──────────────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <div class="bg-surface rounded-card shadow-card px-4 py-3">
                    <p class="text-xs text-ink-3">Anfragen</p>
                    <p class="text-xl font-bold tabular-nums text-ink">{{ summary.hits }}</p>
                </div>
                <div class="bg-surface rounded-card shadow-card px-4 py-3">
                    <p class="text-xs text-ink-3">Ø Dauer</p>
                    <p class="text-xl font-bold tabular-nums text-ink">{{ ms(summary.avg_ms) }}</p>
                </div>
                <div class="bg-surface rounded-card shadow-card px-4 py-3">
                    <p class="text-xs text-ink-3">Langsamste</p>
                    <p class="text-xl font-bold tabular-nums text-ink">{{ ms(summary.max_ms) }}</p>
                </div>
                <div class="bg-surface rounded-card shadow-card px-4 py-3">
                    <p class="text-xs text-ink-3">Ø Queries</p>
                    <p class="text-xl font-bold tabular-nums text-ink">{{ summary.avg_queries }}</p>
                </div>
                <div class="bg-surface rounded-card shadow-card px-4 py-3">
                    <p class="text-xs text-ink-3">Serverfehler</p>
                    <p class="text-xl font-bold tabular-nums"
                       :class="summary.errors > 0 ? 'text-danger-ink' : 'text-ink'">{{ summary.errors }}</p>
                </div>
            </div>

            <!-- ── Anfragen nach Route ──────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Anfragen nach Route</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Gruppiert über das Route-Muster, nicht die URL. Die interessanteste Spalte ist
                        <span class="font-medium">max. Queries</span>: eine Route mit im Schnitt 40 und
                        maximal 200 hat kein Performance-, sondern ein N+1-Problem.
                    </p>
                </div>

                <p v-if="!routes.length" class="px-6 py-8 text-sm text-ink-3">
                    Für diesen Zeitraum wurde nichts aufgezeichnet.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Route</th>
                                <th class="px-3 py-2 text-right font-medium">Aufrufe</th>
                                <th class="px-3 py-2 text-right font-medium">Ø</th>
                                <th class="px-3 py-2 text-right font-medium">max.</th>
                                <th class="px-3 py-2 text-right font-medium">Ø Qry</th>
                                <th class="px-3 py-2 text-right font-medium">max. Qry</th>
                                <th class="px-6 py-2 text-right font-medium">5xx</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="r in routes" :key="r.name">
                                <td class="px-6 py-2.5 font-mono text-xs text-ink">{{ r.name }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ r.hits }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-2">{{ ms(r.avg_ms) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums"
                                    :class="r.max_ms >= thresholds.slow_request_ms ? 'text-warn-ink font-semibold' : 'text-ink-3'">
                                    {{ ms(r.max_ms) }}
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ r.avg_queries }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums"
                                    :class="r.suspect_n1 ? 'text-danger-ink font-semibold' : 'text-ink-3'">
                                    {{ r.max_queries }}<span v-if="r.suspect_n1" title="N+1-Verdacht"> ⚠</span>
                                </td>
                                <td class="px-6 py-2.5 text-right tabular-nums"
                                    :class="r.errors > 0 ? 'text-danger-ink font-semibold' : 'text-ink-3'">{{ r.errors }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Langsame Queries ─────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Langsame Queries</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Alles über {{ thresholds.slow_query_ms }} ms. Die Werte stehen bewusst als
                        <span class="font-mono">?</span> darin — Bindings enthalten Gesundheitsdaten und
                        werden nicht gespeichert.
                    </p>
                </div>

                <p v-if="!slowQueries.length" class="px-6 py-8 text-sm text-ink-3">
                    Keine Query über {{ thresholds.slow_query_ms }} ms.
                </p>

                <ul v-else class="divide-y divide-line">
                    <li v-for="q in slowQueries" :key="q.sql" class="px-6 py-3">
                        <div class="flex items-baseline justify-between gap-4">
                            <code class="min-w-0 flex-1 break-all text-xs text-ink-2">{{ q.sql }}</code>
                            <span class="shrink-0 text-sm font-semibold tabular-nums text-warn-ink">{{ ms(q.max_ms) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-ink-3">
                            {{ q.hits }}× · Ø {{ ms(q.avg_ms) }}
                            <span v-if="q.origin"> · aus <span class="font-mono">{{ q.origin }}</span></span>
                        </p>
                    </li>
                </ul>
            </div>

            <!-- ── Hintergrund-Kommandos ────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Hintergrundarbeit</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Fortgeschrieben statt aufgezeichnet — eine Zeile pro Kommando. Die Frage lautet
                        nicht „welche Läufe gab es", sondern „lief es zuletzt rechtzeitig".
                    </p>
                </div>

                <p v-if="!commands.length" class="px-6 py-8 text-sm text-ink-3">
                    Noch kein Kommando beobachtet.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Kommando</th>
                                <th class="px-3 py-2 text-left font-medium">Zuletzt</th>
                                <th class="px-3 py-2 text-right font-medium">Dauer</th>
                                <th class="px-3 py-2 text-right font-medium">Queries</th>
                                <th class="px-3 py-2 text-right font-medium">Läufe</th>
                                <th class="px-6 py-2 text-right font-medium">Fehler</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="c in commands" :key="c.command">
                                <td class="px-6 py-2.5">
                                    <span class="font-mono text-xs text-ink">{{ c.command }}</span>
                                    <span v-if="c.overdue"
                                        class="ml-2 rounded-full bg-warn-soft px-2 py-0.5 text-[11px] font-medium text-warn-ink">
                                        überfällig
                                    </span>
                                </td>
                                <td class="px-3 py-2.5 whitespace-nowrap"
                                    :class="c.overdue ? 'text-warn-ink' : 'text-ink-3'">
                                    {{ ago(c.ago_minutes) }}
                                    <span class="text-ink-3">· {{ clock(c.last_run_at) }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-2">{{ ms(c.duration_ms) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ c.query_count ?? '–' }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ c.runs_total }}</td>
                                <td class="px-6 py-2.5 text-right tabular-nums"
                                    :class="c.failures > 0 ? 'text-danger-ink font-semibold' : 'text-ink-3'">{{ c.failures }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Queue-Jobs ───────────────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Queue-Jobs</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Ein Job, der 30 Sekunden braucht, tut das nicht immer wegen OpenAI — deshalb
                        stehen die Queries daneben.
                    </p>
                </div>

                <p v-if="!jobs.length" class="px-6 py-8 text-sm text-ink-3">
                    In diesem Zeitraum lief kein Job.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Job</th>
                                <th class="px-3 py-2 text-right font-medium">Läufe</th>
                                <th class="px-3 py-2 text-right font-medium">Ø</th>
                                <th class="px-3 py-2 text-right font-medium">max.</th>
                                <th class="px-3 py-2 text-right font-medium">Ø Qry</th>
                                <th class="px-6 py-2 text-right font-medium">Fehler</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="j in jobs" :key="j.full_name">
                                <td class="px-6 py-2.5 text-ink">{{ j.name }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ j.hits }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-2">{{ ms(j.avg_ms) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ ms(j.max_ms) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ j.avg_queries }}</td>
                                <td class="px-6 py-2.5 text-right tabular-nums"
                                    :class="j.failures > 0 ? 'text-danger-ink font-semibold' : 'text-ink-3'">{{ j.failures }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Ausgehende Aufrufe ───────────────────────────────────── -->
            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Ausgehende Aufrufe</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Strava, OpenAI, fit-service, Wetter. Beantwortet die Frage, die beim
                        Webhook-Ausfall offenblieb: liegt es an uns oder am fremden Dienst. Query-Strings
                        werden nicht gespeichert — dort stehen Zugangsdaten.
                    </p>
                </div>

                <p v-if="!outgoing.length" class="px-6 py-8 text-sm text-ink-3">
                    In diesem Zeitraum ging kein Aufruf raus.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-ink-3 border-b border-line">
                            <tr>
                                <th class="px-6 py-2 text-left font-medium">Ziel</th>
                                <th class="px-3 py-2 text-right font-medium">Aufrufe</th>
                                <th class="px-3 py-2 text-right font-medium">Ø</th>
                                <th class="px-3 py-2 text-right font-medium">max.</th>
                                <th class="px-6 py-2 text-right font-medium">Fehler</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="o in outgoing" :key="o.name">
                                <td class="px-6 py-2.5 font-mono text-xs text-ink">{{ o.name }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ o.hits }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-2">{{ ms(o.avg_ms) }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums text-ink-3">{{ ms(o.max_ms) }}</td>
                                <td class="px-6 py-2.5 text-right tabular-nums"
                                    :class="o.errors > 0 ? 'text-danger-ink font-semibold' : 'text-ink-3'">{{ o.errors }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ── Die langsamsten Einzelfälle ──────────────────────────── -->
            <div v-if="slowest.length" class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink-2">Langsamste Einzelanfragen</h2>
                    <p class="text-xs text-ink-3 mt-0.5">
                        Der Durchschnitt sagt, wie es läuft; diese Liste sagt, wo man hinsieht.
                    </p>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="(s, i) in slowest" :key="i" class="px-6 py-3 flex flex-wrap items-baseline justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono text-xs text-ink">{{ s.path || s.name }}</p>
                            <p class="text-xs text-ink-3">
                                {{ clock(s.at) }} · Status {{ s.status }} ·
                                {{ s.query_count }} Queries ({{ ms(s.query_ms) }}) ·
                                {{ Math.round((s.memory_kb || 0) / 1024) }} MB
                            </p>
                        </div>
                        <span class="shrink-0 text-sm font-semibold tabular-nums text-warn-ink">{{ ms(s.duration_ms) }}</span>
                    </li>
                </ul>
            </div>

            <p class="text-xs text-ink-3">
                Aufgezeichnet wird nach dem Prinzip Zusammenfassung + Ausreißer: eine Zeile pro Anfrage,
                einzelne Queries erst ab {{ thresholds.slow_query_ms }} ms. Geschrieben wird nach der
                Antwort, nicht währenddessen. Aufbewahrung {{ summary.retention }} Tage,
                <span class="font-mono">perf:prune</span> räumt nachts auf.
            </p>
        </div>

        <ConfirmSheet
            :show="confirming"
            title="Messwerte verwerfen"
            message="Alle aufgezeichneten Anfragen, Queries, Jobs und Aufrufe werden gelöscht. Der Zustand der Hintergrund-Kommandos bleibt bestehen."
            confirm-label="Verwerfen"
            :loading="busy"
            @confirm="flush"
            @close="confirming = false" />
    </AdminLayout>
</template>
