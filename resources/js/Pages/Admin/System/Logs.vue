<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    log:     Object,
    filters: Object,
});

const q     = ref(props.filters?.q ?? '');
const level = ref(props.filters?.level ?? '');

let timer = null;
watch([q, level], () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 250);
});

function apply() {
    router.get(route('admin.system.logs'), {
        q:     q.value     || undefined,
        level: level.value || undefined,
    }, { preserveState: true, replace: true });
}

/**
 * Die Fragen, die hier tatsächlich gestellt werden.
 *
 * „Ruft Strava überhaupt an?" hat zwei Sitzungen gekostet und ist eine
 * Suche nach einem Wort. Deshalb steht sie als Knopf da und nicht als
 * Übung im Tippen.
 */
const shortcuts = [
    { label: 'Strava-Webhook',  q: 'Strava-Webhook' },
    { label: 'Strava-Import',   q: 'Strava-Aktivitaet' },
    { label: 'Plan',            q: 'Plan' },
    { label: 'Push',            q: 'push' },
];

function useShortcut(s) {
    level.value = '';
    q.value = s.q;
}

const levelTone = {
    ERROR:     'bg-danger-soft text-danger-ink',
    CRITICAL:  'bg-danger-soft text-danger-ink',
    ALERT:     'bg-danger-soft text-danger-ink',
    EMERGENCY: 'bg-danger-soft text-danger-ink',
    WARNING:   'bg-warn-soft text-warn-ink',
    NOTICE:    'bg-info-soft text-info-ink',
    INFO:      'bg-info-soft text-info-ink',
    DEBUG:     'bg-surface-3 text-ink-3',
};

const sizeMb = computed(() => (props.log.size / 1024 / 1024).toFixed(1));

function when(value) {
    if (!value) return '—';
    const d = new Date(value.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
</script>

<template>
    <Head title="Log" />

    <AdminLayout>
        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-6">

            <div class="bg-surface rounded-card shadow-card">
                <div class="px-6 py-4 border-b border-line flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-ink-2">Anwendungslog</h2>
                        <p class="text-xs text-ink-3 mt-0.5">
                            <span v-if="log.file">{{ log.file }} · {{ sizeMb }} MB</span>
                            <span v-else>Keine Logdatei gefunden.</span>
                            <span v-if="log.truncated"> · gelesen wird das letzte halbe Megabyte</span>
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <Link :href="route('admin.system.index')"
                            class="px-3 py-1.5 rounded-field text-xs font-medium border border-line text-ink-2 hover:bg-surface-2">
                            Systemstatus
                        </Link>
                        <button
                            class="px-3 py-1.5 rounded-field text-xs font-medium bg-accent text-white"
                            @click="router.reload()">
                            Neu laden
                        </button>
                    </div>
                </div>

                <!-- Filter -->
                <div class="px-6 py-3 border-b border-line space-y-2">
                    <div class="grid gap-2 sm:grid-cols-[1fr_auto]">
                        <input
                            v-model="q"
                            type="search"
                            placeholder="Volltext — z. B. Strava, user_id, Ausnahme"
                            class="rounded-field border-line bg-canvas text-sm text-ink placeholder:text-ink-3" />

                        <select v-model="level" class="rounded-field border-line bg-canvas text-sm text-ink">
                            <option value="">Alle Stufen</option>
                            <option value="error">Nur Fehler</option>
                            <option value="warning">Nur Warnungen</option>
                            <option value="info">Nur Info</option>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="s in shortcuts" :key="s.label"
                            class="px-2 py-1 rounded-full text-xs border border-line text-ink-2 hover:bg-surface-2"
                            :class="q === s.q ? 'bg-accent-soft text-accent-ink border-accent/25' : ''"
                            @click="useShortcut(s)">
                            {{ s.label }}
                        </button>
                        <button
                            v-if="q || level"
                            class="px-2 py-1 rounded-full text-xs text-ink-3 hover:text-ink"
                            @click="q = ''; level = ''">
                            zurücksetzen
                        </button>
                    </div>
                </div>

                <p v-if="!log.entries.length" class="px-6 py-8 text-sm text-ink-3">
                    Kein Eintrag passt.
                    <span v-if="q || level">Andere Suche versuchen — gelesen wird nur das Ende der Datei.</span>
                </p>

                <div v-else class="divide-y divide-line">
                    <div v-for="(e, i) in log.entries" :key="i" class="px-6 py-3">
                        <div class="flex flex-wrap items-baseline gap-2">
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-medium shrink-0"
                                :class="levelTone[e.level] ?? 'bg-surface-3 text-ink-3'">
                                {{ e.level }}
                            </span>
                            <span class="text-xs text-ink-3 tabular-nums shrink-0">{{ when(e.time) }}</span>
                            <span class="text-sm text-ink break-all">{{ e.message }}</span>
                        </div>

                        <p v-if="e.context" class="mt-1 text-xs text-ink-3 font-mono break-all">
                            {{ e.context }}
                        </p>

                        <p v-if="e.trace" class="mt-1 text-[11px] text-ink-3">
                            + {{ e.trace }} Zeile(n) Stacktrace
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
