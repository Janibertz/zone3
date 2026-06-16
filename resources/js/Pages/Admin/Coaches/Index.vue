<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    coaches: Array,
});

const coachColors = {
    orange: { bg: 'bg-orange-500',  light: 'bg-orange-50 dark:bg-orange-500/10',  border: 'border-orange-200 dark:border-orange-500/30',  badge: 'bg-orange-100 dark:bg-orange-500/20 text-orange-700 dark:text-orange-300' },
    blue:   { bg: 'bg-blue-600',    light: 'bg-blue-50 dark:bg-blue-500/10',      border: 'border-blue-200 dark:border-blue-500/30',      badge: 'bg-blue-100 dark:bg-blue-500/20 text-blue-700 dark:text-blue-300'         },
    green:  { bg: 'bg-green-600',   light: 'bg-green-50 dark:bg-green-500/10',    border: 'border-green-200 dark:border-green-500/30',    badge: 'bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300'     },
    purple: { bg: 'bg-purple-600',  light: 'bg-purple-50 dark:bg-purple-500/10',  border: 'border-purple-200 dark:border-purple-500/30',  badge: 'bg-purple-100 dark:bg-purple-500/20 text-purple-700 dark:text-purple-300'  },
};

const specialtyLabels = { motivator: 'Motivator', strategist: 'Stratege', companion: 'Begleiter' };

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
</script>

<template>
    <Head title="Admin – Coaches" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Coaches</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-6">

            <p class="text-sm text-gray-500 dark:text-slate-400">
                Hier kannst du die Persönlichkeits-Prompts der AI-Coaches bearbeiten und ihre Nutzungsstatistiken einsehen.
            </p>

            <!-- Coach cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div
                    v-for="coach in coaches" :key="coach.id"
                    class="bg-white dark:bg-slate-900 border rounded-2xl overflow-hidden transition-shadow hover:shadow-md"
                    :class="coachColors[coach.avatar_color]?.border ?? 'border-gray-100 dark:border-slate-800'"
                >
                    <!-- Header -->
                    <div class="p-5 flex items-center gap-4" :class="coachColors[coach.avatar_color]?.light">
                        <div class="h-14 w-14 rounded-2xl flex items-center justify-center text-lg font-bold text-white shadow-sm shrink-0"
                            :class="coachColors[coach.avatar_color]?.bg ?? 'bg-gray-500'"
                        >
                            {{ coach.avatar_initials }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-bold text-gray-900 dark:text-white">{{ coach.name }}</h2>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="coachColors[coach.avatar_color]?.badge">
                                    {{ specialtyLabels[coach.specialty] ?? coach.specialty }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 dark:text-slate-400 mt-0.5 truncate italic">{{ coach.tagline }}</p>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="grid grid-cols-3 divide-x divide-gray-100 dark:divide-slate-800 border-t border-gray-100 dark:border-slate-800">
                        <div class="p-3 text-center">
                            <p class="text-xs text-gray-400 dark:text-slate-500">Athleten</p>
                            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ coach.users_count }}</p>
                        </div>
                        <div class="p-3 text-center">
                            <p class="text-xs text-gray-400 dark:text-slate-500">AI Calls</p>
                            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ coach.ai_calls }}</p>
                        </div>
                        <div class="p-3 text-center">
                            <p class="text-xs text-gray-400 dark:text-slate-500">Kosten</p>
                            <p class="text-lg font-bold text-gray-900 dark:text-white">{{ formatCost(coach.ai_cost) }}</p>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="px-5 py-4">
                        <p class="text-xs text-gray-500 dark:text-slate-400 line-clamp-2">{{ coach.description }}</p>
                    </div>

                    <!-- Footer -->
                    <div class="px-5 pb-5">
                        <Link
                            :href="route('admin.coaches.show', coach.id)"
                            class="block w-full text-center px-4 py-2 rounded-xl text-sm font-medium border transition-colors"
                            :class="(coachColors[coach.avatar_color]?.border ?? 'border-gray-200 dark:border-slate-700') + ' text-gray-700 dark:text-slate-300 hover:bg-gray-50 dark:hover:bg-slate-800'"
                        >
                            Bearbeiten
                        </Link>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
