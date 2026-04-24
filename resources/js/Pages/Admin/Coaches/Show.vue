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
    orange: { bg: 'bg-orange-500',  light: 'bg-orange-50 dark:bg-orange-500/10',  border: 'border-orange-200 dark:border-orange-500/30',  badge: 'bg-orange-100 dark:bg-orange-500/20 text-orange-700 dark:text-orange-300' },
    blue:   { bg: 'bg-blue-600',    light: 'bg-blue-50 dark:bg-blue-500/10',      border: 'border-blue-200 dark:border-blue-500/30',      badge: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300'         },
    green:  { bg: 'bg-green-600',   light: 'bg-green-50 dark:bg-green-500/10',    border: 'border-green-200 dark:border-green-500/30',    badge: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300'     },
};

const specialtyLabels = { motivator: 'Motivator', strategist: 'Stratege', companion: 'Begleiter' };

const colors = computed(() => coachColors[props.coach.avatar_color] ?? {
    bg: 'bg-gray-500', light: 'bg-gray-50 dark:bg-slate-800', border: 'border-gray-200 dark:border-slate-700', badge: 'bg-gray-100 text-gray-700',
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
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-slate-400">
                <Link :href="route('admin.coaches.index')" class="hover:text-gray-700 dark:hover:text-slate-200">Coaches</Link>
                <span>/</span>
                <span class="text-gray-900 dark:text-white font-medium">{{ coach.name }}</span>
            </div>
        </template>

        <div class="p-6 space-y-6 max-w-4xl">

            <!-- Flash -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 rounded-xl text-sm text-green-700 dark:text-green-300">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                {{ flash.success }}
            </div>

            <!-- Coach header -->
            <div class="rounded-2xl overflow-hidden border" :class="colors.border">
                <div class="flex items-center gap-5 p-6" :class="colors.light">
                    <div class="h-16 w-16 rounded-2xl flex items-center justify-center text-xl font-bold text-white shadow-sm shrink-0"
                        :class="colors.bg"
                    >
                        {{ coach.avatar_initials }}
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-bold text-gray-900 dark:text-white">{{ coach.name }}</h1>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="colors.badge">
                                {{ specialtyLabels[coach.specialty] ?? coach.specialty }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-slate-400 mt-0.5 italic">{{ coach.tagline }}</p>
                    </div>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-slate-800 border-t" :class="colors.border">
                    <div v-for="stat in [
                        { label: 'Athleten',      value: coach.users_count },
                        { label: 'AI Calls',      value: aiStats.total_calls },
                        { label: 'Kosten gesamt', value: formatCost(aiStats.total_cost) },
                    ]" :key="stat.label" class="p-4 text-center">
                        <p class="text-xs text-gray-400 dark:text-slate-500">{{ stat.label }}</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white mt-0.5">{{ stat.value }}</p>
                    </div>
                </div>
            </div>

            <!-- Edit form -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Coach-Details bearbeiten</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-400"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Tagline</label>
                        <input
                            v-model="form.tagline"
                            type="text"
                            class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-400"
                        />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-500 dark:text-slate-400 mb-1.5">Beschreibung</label>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-400 resize-none"
                    />
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-xs font-medium text-gray-500 dark:text-slate-400">Personality Prompt</label>
                        <span class="text-xs text-gray-400 dark:text-slate-500 tabular-nums">{{ promptCharCount }} Zeichen</span>
                    </div>
                    <textarea
                        v-model="form.personality_prompt"
                        rows="12"
                        class="w-full rounded-xl border border-gray-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-gray-900 dark:text-white px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-400 resize-y font-mono leading-relaxed"
                        placeholder="You are [Name], a running coach..."
                    />
                    <p class="mt-1.5 text-xs text-gray-400 dark:text-slate-500">
                        Dieser Prompt wird als System-Instruction bei jedem AI-Call dieses Coaches verwendet. Änderungen gelten ab dem nächsten API-Call.
                    </p>
                </div>

                <div class="flex justify-end">
                    <button
                        @click="save"
                        :disabled="saving"
                        class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition-colors"
                        :class="saving ? 'bg-gray-400 dark:bg-slate-600 cursor-not-allowed' : 'bg-red-600 hover:bg-red-700'"
                    >
                        {{ saving ? 'Speichert…' : 'Änderungen speichern' }}
                    </button>
                </div>
            </div>

            <!-- Recent athletes -->
            <div v-if="recentUsers?.length" class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl p-5">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300 mb-4">Aktuelle Athleten (letzte 8)</h2>
                <div class="space-y-2">
                    <div v-for="u in recentUsers" :key="u.id" class="flex items-center justify-between py-2 border-b border-gray-50 dark:border-slate-800/60 last:border-0">
                        <div>
                            <Link :href="route('admin.users.show', u.id)" class="text-sm font-medium text-gray-900 dark:text-white hover:text-red-500 dark:hover:text-red-400 transition-colors">
                                {{ u.name }}
                            </Link>
                            <p class="text-xs text-gray-400 dark:text-slate-500">{{ u.email }}</p>
                        </div>
                        <span class="text-xs text-gray-400 dark:text-slate-500">seit {{ formatDate(u.created_at) }}</span>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
