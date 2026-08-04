<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    log: Object,
});

const showFullPrompt   = ref(false);
const showFullResponse = ref(false);

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleString('de-DE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
    });
}

function formatCost(eur) {
    const val = parseFloat(eur);
    if (!val) return '0,00 ct';
    if (val < 0.001) return (val * 100).toFixed(4) + ' ct';
    return val.toFixed(4) + ' €';
}

const callTypeLabel = (t) => ({
    recommendation:        'Tagesempfehlung',
    adjust_recommendation: 'Empfehlung anpassen',
    plan:                  'Trainingsplan',
    event_plan:            'Event-Trainingsplan',
    weekly_review:         'Wochenrückblick',
    pace_zones:            'Pace-Zonen',
    threshold_pace:        'Schwellenpace',
    nutrition:             'Ernährungstipps',
    adjust_session:        'Session anpassen',
    goal_analysis:         'Ziel-Analyse',
    profile_estimation:    'Profil-Schätzung',
    suggestions:           'Vorschläge',
}[t] ?? t);

// Try to pretty-print the response if it's JSON
const prettyResponse = computed(() => {
    const raw = props.log?.full_response;
    if (!raw) return '';
    try {
        const clean = raw.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '');
        const parsed = JSON.parse(clean);
        return JSON.stringify(parsed, null, 2);
    } catch {
        return raw;
    }
});
</script>

<template>
    <Head :title="`AI Log #${log.id}`" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('admin.ai-logs.index')" class="text-ink-3 hover:text-ink-2 transition-colors">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                </Link>
                <h1 class="text-xl font-bold text-ink">AI Log #{{ log.id }}</h1>
            </div>
        </template>

        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-6">

            <!-- ── Meta Card ── -->
            <div class="bg-surface rounded-card p-6">
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-5">
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Zeitpunkt</p>
                        <p class="text-sm font-semibold text-ink">{{ formatDate(log.created_at) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Nutzer</p>
                        <Link v-if="log.user" :href="route('admin.users.show', log.user.id)" class="text-sm font-semibold text-ink hover:text-danger">
                            {{ log.user.name }}
                        </Link>
                        <p v-else class="text-sm font-semibold text-ink-3">System / anonym</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Typ</p>
                        <p class="text-sm font-semibold text-ink">{{ callTypeLabel(log.call_type) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Modell</p>
                        <p class="text-sm font-semibold text-ink">{{ log.model }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Status</p>
                        <span
                            class="text-xs px-2.5 py-1 rounded-full font-semibold"
                            :class="log.status === 'success'
                                ? 'bg-success-soft text-success'
                                : 'bg-danger-soft text-danger'"
                        >
                            {{ log.status === 'success' ? 'Erfolgreich' : 'Fehler' }}
                        </span>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Prompt Tokens</p>
                        <p class="text-sm font-semibold text-ink">{{ log.prompt_tokens?.toLocaleString('de-DE') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Response Tokens</p>
                        <p class="text-sm font-semibold text-ink">{{ log.completion_tokens?.toLocaleString('de-DE') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Gesamt Tokens</p>
                        <p class="text-sm font-semibold text-ink">{{ log.total_tokens?.toLocaleString('de-DE') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Kosten</p>
                        <p class="text-sm font-semibold text-ink">{{ formatCost(log.cost_eur) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-ink-3 mb-1">Antwortzeit</p>
                        <p class="text-sm font-semibold text-ink">{{ log.duration_ms }} ms</p>
                    </div>
                </div>

                <!-- Error message -->
                <div v-if="log.error_message" class="mt-5 p-4 bg-danger-soft rounded-field border border-danger/25">
                    <p class="text-xs font-semibold text-danger-ink mb-1">Fehlermeldung</p>
                    <p class="text-sm text-danger-ink font-mono">{{ log.error_message }}</p>
                </div>
            </div>

            <!-- ── Prompt ── -->
            <div class="bg-surface rounded-card overflow-hidden">
                <div class="flex items-center justify-between px-5 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink">Prompt (User-Nachricht)</h2>
                    <button
                        @click="showFullPrompt = !showFullPrompt"
                        class="text-xs text-danger hover:underline font-medium"
                    >
                        {{ showFullPrompt ? 'Kürzen' : 'Vollständig anzeigen' }}
                    </button>
                </div>
                <div class="p-5">
                    <pre class="text-xs text-ink-2 whitespace-pre-wrap font-mono leading-relaxed">{{ showFullPrompt ? log.full_prompt : (log.prompt_preview || log.full_prompt) }}</pre>
                    <p v-if="!log.full_prompt && !log.prompt_preview" class="text-sm text-ink-3">Kein Prompt gespeichert.</p>
                </div>
            </div>

            <!-- ── Response ── -->
            <div class="bg-surface rounded-card overflow-hidden">
                <div class="flex items-center justify-between px-5 py-4 border-b border-line">
                    <h2 class="text-sm font-semibold text-ink">Response (Modell-Antwort)</h2>
                    <button
                        @click="showFullResponse = !showFullResponse"
                        class="text-xs text-danger hover:underline font-medium"
                    >
                        {{ showFullResponse ? 'Kürzen' : 'Vollständig anzeigen' }}
                    </button>
                </div>
                <div class="p-5">
                    <pre class="text-xs text-ink-2 whitespace-pre-wrap font-mono leading-relaxed">{{ showFullResponse ? prettyResponse : (log.response_preview || prettyResponse) }}</pre>
                    <p v-if="!log.full_response && !log.response_preview" class="text-sm text-ink-3">Keine Antwort gespeichert.</p>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
