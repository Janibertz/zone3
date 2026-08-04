<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    coach:       Object,
    aiStats:     Object,
    recentUsers: Array,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = ref({
    name:               props.coach.name,
    tagline:            props.coach.tagline,
    description:        props.coach.description,
    personality_prompt: props.coach.personality_prompt,
});

const saving = ref(false);

function save() {
    saving.value = true;
    router.put(route('admin.coaches.update', props.coach.id), form.value, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
}

const coachColors = {
    orange: { bg: 'bg-warn',  light: 'bg-warn-soft',  border: 'border-warn/25',  badge: 'bg-warn-soft text-warn-ink' },
    blue:   { bg: 'bg-info',    light: 'bg-info-soft',      border: 'border-info/25',      badge: 'bg-info-soft text-info-ink'         },
    green:  { bg: 'bg-success',   light: 'bg-success-soft',    border: 'border-success/25',    badge: 'bg-success-soft text-success-ink'     },
    purple: { bg: 'bg-accent',  light: 'bg-accent-soft',  border: 'border-accent/25',  badge: 'bg-accent-soft text-accent-ink'  },
};

const specialtyLabels = { motivator: 'Motivator', strategist: 'Stratege', companion: 'Begleiter' };

const colors = computed(() => coachColors[props.coach.avatar_color] ?? {
    bg: 'bg-ink-3', light: 'bg-surface-2', border: 'border-line', badge: 'bg-surface-2 text-ink-2',
});

function formatCost(eur) {
    const val = parseFloat(eur);
    if (!val) return '0,00 ct';
    if (val < 0.001) return (val * 100).toFixed(4) + ' ct';
    return val.toFixed(4) + ' €';
}

function formatTokens(n) {
    if (!n) return '0';
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
    return String(n);
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const promptCharCount = computed(() => form.value.personality_prompt?.length ?? 0);
</script>

<template>
    <Head :title="`Admin – Coach ${coach.name}`" />

    <AdminLayout>
        <template #header>
            <div class="flex items-center gap-2 text-sm text-ink-3">
                <Link :href="route('admin.coaches.index')" class="hover:text-ink">Coaches</Link>
                <span>/</span>
                <span class="text-ink font-medium">{{ coach.name }}</span>
            </div>
        </template>

        <div class="px-4 py-4 lg:px-6 lg:py-6 space-y-6">

            <!-- Flash -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                {{ flash.success }}
            </div>

            <!-- Coach header -->
            <div class="rounded-card overflow-hidden border" :class="colors.border">
                <div class="flex items-center gap-5 p-6" :class="colors.light">
                    <div class="h-16 w-16 rounded-card flex items-center justify-center text-xl font-bold text-white shadow-card shrink-0"
                        :class="colors.bg"
                    >
                        {{ coach.avatar_initials }}
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-bold text-ink">{{ coach.name }}</h1>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="colors.badge">
                                {{ specialtyLabels[coach.specialty] ?? coach.specialty }}
                            </span>
                        </div>
                        <p class="text-sm text-ink-3 mt-0.5 italic">{{ coach.tagline }}</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-3 divide-x divide-line border-t" :class="colors.border">
                    <div v-for="stat in [
                        { label: 'Athleten',      value: coach.users_count },
                        { label: 'AI Calls',      value: aiStats.total_calls },
                        { label: 'Kosten gesamt', value: formatCost(aiStats.total_cost) },
                    ]" :key="stat.label" class="p-4 text-center">
                        <p class="text-xs text-ink-3">{{ stat.label }}</p>
                        <p class="text-xl font-bold text-ink mt-0.5">{{ stat.value }}</p>
                    </div>
                </div>
            </div>

            <!-- Edit form -->
            <div class="bg-surface rounded-card p-6 space-y-5">
                <h2 class="text-sm font-semibold text-ink-2">Coach-Details bearbeiten</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-ink-3 mb-1.5">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-field bg-surface text-sm text-ink px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-danger/40"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-ink-3 mb-1.5">Tagline</label>
                        <input
                            v-model="form.tagline"
                            type="text"
                            class="w-full rounded-field bg-surface text-sm text-ink px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-danger/40"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-ink-3 mb-1.5">Beschreibung</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded-field bg-surface text-sm text-ink px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-danger/40 resize-none"
                    />
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-xs font-medium text-ink-3">Personality Prompt</label>
                        <span class="text-xs text-ink-3 tabular-nums">{{ promptCharCount }} Zeichen</span>
                    </div>
                    <textarea
                        v-model="form.personality_prompt"
                        rows="12"
                        class="w-full rounded-field bg-surface text-sm text-ink px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-danger/40 resize-y font-mono leading-relaxed"
                        placeholder="You are [Name], a running coach..."
                    />
                    <p class="mt-1.5 text-xs text-ink-3">
                        Dieser Prompt wird als System-Instruction bei jedem AI-Call dieses Coaches verwendet. Änderungen gelten ab dem nächsten API-Call.
                    </p>
                </div>

                <div class="flex justify-end">
                    <button
                        @click="save"
                        :disabled="saving"
                        class="px-5 py-2.5 rounded-field text-sm font-semibold text-white transition-colors"
                        :class="saving ? 'bg-ink-3 cursor-not-allowed' : 'bg-danger hover:opacity-90'"
                    >
                        {{ saving ? 'Speichert…' : 'Änderungen speichern' }}
                    </button>
                </div>
            </div>

            <!-- Recent athletes -->
            <div v-if="recentUsers?.length" class="bg-surface rounded-card p-5">
                <h2 class="text-sm font-semibold text-ink-2 mb-4">Aktuelle Athleten (letzte 8)</h2>
                <div class="space-y-2">
                    <div v-for="u in recentUsers" :key="u.id" class="flex items-center justify-between py-2 border-b border-line last:border-0">
                        <div>
                            <Link :href="route('admin.users.show', u.id)" class="text-sm font-medium text-ink hover:text-danger transition-colors">
                                {{ u.name }}
                            </Link>
                            <p class="text-xs text-ink-3">{{ u.email }}</p>
                        </div>
                        <span class="text-xs text-ink-3">seit {{ formatDate(u.created_at) }}</span>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
