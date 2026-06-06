<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    monthlyStats: Array,
    weeklyStats:  Array,
    paceTrend:    Array,
    totals:       Object,
});

function maxVal(data, key = 'km') {
    return Math.max(...data.map(d => d[key]), 1);
}

function barHeight(val, max, totalHeight = 100) {
    return Math.max((val / max) * totalHeight, val > 0 ? 3 : 0);
}

const paceTrendMin = computed(() => Math.min(...props.paceTrend.map(p => p.pace_sec)));
const paceTrendMax = computed(() => Math.max(...props.paceTrend.map(p => p.pace_sec)));

function paceBarHeight(paceSec, totalHeight = 90) {
    const range = paceTrendMax.value - paceTrendMin.value || 1;
    return Math.max((1 - (paceSec - paceTrendMin.value) / range) * totalHeight, 4);
}

function formatTime(minutes) {
    if (minutes < 60) return `${minutes}m`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function fmtPace(sec) {
    return `${Math.floor(sec / 60)}:${String(Math.floor(sec % 60)).padStart(2,'0')}`;
}
</script>

<template>
    <AuthenticatedLayout>
        <div class="max-w-3xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6">

            <!-- Header -->
            <div class="mb-4 sm:mb-6 flex items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white">Statistiken</h1>
                    <p class="mt-0.5 text-xs sm:text-sm text-gray-500 dark:text-slate-400">Deine Laufanalyse auf einen Blick</p>
                </div>
                <Link :href="route('wrapped.index')"
                    class="shrink-0 inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-br from-indigo-600 to-purple-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 transition-opacity">
                    🎁 Rückblick
                </Link>
            </div>

            <!-- KPI Grid — 2x2 on mobile, 4-col on sm+ -->
            <div class="grid grid-cols-2 gap-2.5 sm:gap-3 mb-4 sm:mb-5">
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 font-medium">Läufe gesamt</p>
                    <p class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums">{{ totals.runs }}</p>
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 font-medium">Kilometer</p>
                    <p class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums">{{ totals.km.toLocaleString('de-DE') }}</p>
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 font-medium">Laufzeit</p>
                    <p class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ formatTime(totals.time_min) }}</p>
                </div>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4">
                    <p class="text-xs text-gray-500 dark:text-slate-400 font-medium">Ø Pace</p>
                    <p class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white mt-1 tabular-nums">{{ totals.avg_pace ?? '–' }}</p>
                    <p v-if="totals.avg_pace" class="text-xs text-gray-400 dark:text-slate-500">min/km</p>
                </div>
            </div>

            <!-- Monthly volume — horizontal scroll on mobile so all 12 bars always visible -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 sm:p-5 mb-3">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Monatliches Volumen</h2>
                <div v-if="monthlyStats.every(m => m.km === 0)" class="text-center py-8 text-sm text-gray-400 dark:text-slate-500">
                    Noch keine Daten
                </div>
                <div v-else class="overflow-x-auto -mx-1 px-1" style="scrollbar-width:none;">
                    <div class="flex items-end gap-1.5 h-28" :style="{ minWidth: monthlyStats.length * 32 + 'px' }">
                        <div
                            v-for="month in monthlyStats"
                            :key="month.label"
                            class="flex-1 flex flex-col items-center gap-1 group min-w-[28px]"
                            :title="`${month.label}: ${month.km} km`"
                        >
                            <span class="text-[10px] text-indigo-600 dark:text-indigo-400 font-semibold opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap leading-none">
                                {{ month.km }}
                            </span>
                            <div
                                class="w-full rounded-t transition-all duration-500"
                                :class="month.km > 0 ? 'bg-indigo-500' : 'bg-gray-100 dark:bg-slate-800'"
                                :style="{ height: barHeight(month.km, maxVal(monthlyStats)) + 'px' }"
                            />
                            <span class="text-[9px] text-gray-400 dark:text-slate-500 leading-none whitespace-nowrap">{{ month.label }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Weekly volume -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 sm:p-5 mb-3">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Wöchentliches Volumen <span class="font-normal text-gray-400 dark:text-slate-500">(8 Wochen)</span></h2>
                <div v-if="weeklyStats.every(w => w.km === 0)" class="text-center py-8 text-sm text-gray-400 dark:text-slate-500">
                    Noch keine Daten
                </div>
                <div v-else class="flex items-end gap-2 h-28">
                    <div
                        v-for="week in weeklyStats"
                        :key="week.label"
                        class="flex-1 flex flex-col items-center gap-1 group"
                        :title="`${week.label}: ${week.km} km`"
                    >
                        <span class="text-[10px] text-violet-600 dark:text-violet-400 font-semibold opacity-0 group-hover:opacity-100 transition-opacity">
                            {{ week.km }}
                        </span>
                        <div
                            class="w-full rounded-t transition-all duration-500"
                            :class="week.km > 0 ? 'bg-violet-500' : 'bg-gray-100 dark:bg-slate-800'"
                            :style="{ height: barHeight(week.km, maxVal(weeklyStats)) + 'px' }"
                        />
                        <span class="text-[10px] text-gray-400 dark:text-slate-500 leading-none">{{ week.label }}</span>
                    </div>
                </div>
            </div>

            <!-- Pace trend — horizontal scroll on mobile -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-100 dark:border-slate-800 p-4 sm:p-5">
                <div class="flex items-start justify-between mb-1">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Pace-Trend</h2>
                    <span class="text-xs text-gray-400 dark:text-slate-500">letzte 20 Läufe</span>
                </div>
                <p class="text-xs text-gray-400 dark:text-slate-500 mb-4">Höhere Balken = schnellere Pace</p>

                <div v-if="paceTrend.length === 0" class="text-center py-8 text-sm text-gray-400 dark:text-slate-500">
                    Noch keine Läufe vorhanden
                </div>
                <div v-else class="overflow-x-auto -mx-1 px-1" style="scrollbar-width:none;">
                    <div class="flex items-end gap-1.5 h-24" :style="{ minWidth: paceTrend.length * 28 + 'px' }">
                        <div
                            v-for="run in paceTrend"
                            :key="run.date + run.name"
                            class="flex-1 flex flex-col items-center gap-1 group min-w-[22px]"
                            :title="`${run.date}\n${run.name}\n${run.pace_label} /km`"
                        >
                            <span class="text-[9px] text-emerald-600 dark:text-emerald-400 font-medium opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap leading-none">
                                {{ run.pace_label }}
                            </span>
                            <div
                                class="w-full rounded-t bg-emerald-500 transition-all duration-500"
                                :style="{ height: paceBarHeight(run.pace_sec) + 'px' }"
                            />
                            <span class="text-[8px] text-gray-400 dark:text-slate-500 leading-none">{{ run.date }}</span>
                        </div>
                    </div>
                </div>

                <!-- Legend -->
                <div v-if="paceTrend.length > 0" class="mt-3 flex items-center justify-between text-xs text-gray-400 dark:text-slate-500 border-t border-gray-100 dark:border-slate-800 pt-3">
                    <span>Schnellste <strong class="text-gray-800 dark:text-slate-200">{{ fmtPace(paceTrendMin) }} /km</strong></span>
                    <span>Langsamste <strong class="text-gray-800 dark:text-slate-200">{{ fmtPace(paceTrendMax) }} /km</strong></span>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
