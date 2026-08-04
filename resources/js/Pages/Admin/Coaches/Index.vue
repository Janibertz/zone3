<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    coaches: Array,
});

const coachColors = {
    orange: { bg: 'bg-warn',  light: 'bg-warn-soft',  border: 'border-warn/25',  badge: 'bg-warn-soft text-warn-ink' },
    blue:   { bg: 'bg-info',    light: 'bg-info-soft',      border: 'border-info/25',      badge: 'bg-info-soft text-info-ink'         },
    green:  { bg: 'bg-success',   light: 'bg-success-soft',    border: 'border-success/25',    badge: 'bg-success-soft text-success-ink'     },
    purple: { bg: 'bg-accent',  light: 'bg-accent-soft',  border: 'border-accent/25',  badge: 'bg-accent-soft text-accent-ink'  },
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
            <h1 class="text-xl font-bold text-ink">Coaches</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-6">

            <p class="text-sm text-ink-3">
                Hier kannst du die Persönlichkeits-Prompts der AI-Coaches bearbeiten und ihre Nutzungsstatistiken einsehen.
            </p>

            <!-- Coach cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div
                    v-for="coach in coaches" :key="coach.id"
                    class="bg-surface border rounded-card overflow-hidden transition-shadow hover:shadow-md"
                    :class="coachColors[coach.avatar_color]?.border ?? 'border-line'"
                >
                    <!-- Header -->
                    <div class="p-5 flex items-center gap-4" :class="coachColors[coach.avatar_color]?.light">
                        <div class="h-14 w-14 rounded-card flex items-center justify-center text-lg font-bold text-white shadow-card shrink-0"
                            :class="coachColors[coach.avatar_color]?.bg ?? 'bg-ink-3'"
                        >
                            {{ coach.avatar_initials }}
                        </div>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <h2 class="text-lg font-bold text-ink">{{ coach.name }}</h2>
                                <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="coachColors[coach.avatar_color]?.badge">
                                    {{ specialtyLabels[coach.specialty] ?? coach.specialty }}
                                </span>
                            </div>
                            <p class="text-xs text-ink-3 mt-0.5 truncate italic">{{ coach.tagline }}</p>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="grid grid-cols-3 divide-x divide-line border-t border-line">
                        <div class="p-3 text-center">
                            <p class="text-xs text-ink-3">Athleten</p>
                            <p class="text-lg font-bold text-ink">{{ coach.users_count }}</p>
                        </div>
                        <div class="p-3 text-center">
                            <p class="text-xs text-ink-3">AI Calls</p>
                            <p class="text-lg font-bold text-ink">{{ coach.ai_calls }}</p>
                        </div>
                        <div class="p-3 text-center">
                            <p class="text-xs text-ink-3">Kosten</p>
                            <p class="text-lg font-bold text-ink">{{ formatCost(coach.ai_cost) }}</p>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="px-5 py-4">
                        <p class="text-xs text-ink-3 line-clamp-2">{{ coach.description }}</p>
                    </div>

                    <!-- Footer -->
                    <div class="px-5 pb-5">
                        <Link
                            :href="route('admin.coaches.show', coach.id)"
                            class="block w-full text-center px-4 py-2 rounded-field text-sm font-medium border transition-colors"
                            :class="(coachColors[coach.avatar_color]?.border ?? 'border-line') + ' text-ink-2 hover:bg-surface-2'"
                        >
                            Bearbeiten
                        </Link>
                    </div>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
