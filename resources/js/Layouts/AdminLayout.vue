<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useDarkMode } from '@/Composables/useDarkMode';
import Wordmark from '@/Components/Brand/Wordmark.vue';
import ZoneIcon from '@/Components/Brand/ZoneIcon.vue';

/**
 * Admin-Hülle (Umbau 18.11.2026, Mockups „Admin-Bereich aufräumen“).
 * Am iPhone fünf Tabs: Heute · Athleten · Postfach · System · Mehr. Am
 * Desktop dieselben oben in der Seitenleiste, darunter die Gruppen von
 * „Mehr“. Die Zahl am Postfach und der Punkt an System kommen aus
 * `adminNav` (HandleInertiaRequests → AdminInsights::nav()).
 */

const page = usePage();
const nav = computed(() => page.props.adminNav ?? { inbox: 0, system: false, athletes: null });
const { isDark, toggle } = useDarkMode();

const icons = {
    home: '<path d="M3 11 12 3l9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" />',
    people: '<circle cx="9" cy="8" r="4" /><path d="M2 21c0-4 3-6 7-6s7 2 7 6M16 4a4 4 0 0 1 0 8M22 21c0-3-2-5-4-6" />',
    inbox: '<path d="M3 13h5l2 3h4l2-3h5M5 5h14l2 8v6H3v-6z" />',
    pulse: '<path d="M3 12h4l3-8 4 16 3-8h4" />',
};

/** Die vier Tabs vor „Mehr“ — `match` sind die Routen, auf denen der Tab rot ist. */
const tabs = [
    { label: 'Heute',    route: 'admin.dashboard',   icon: icons.home,   match: ['admin.dashboard'] },
    { label: 'Athleten', route: 'admin.users.index', icon: icons.people, match: ['admin.users.*'] },
    { label: 'Postfach', route: 'admin.inbox.index', icon: icons.inbox,  match: ['admin.inbox.*', 'admin.support.*', 'admin.contact.*'], badge: 'inbox' },
    { label: 'System',   route: 'admin.system.index', icon: icons.pulse, match: ['admin.system.*', 'admin.ai-logs.*'], dot: 'system' },
];

/** Dieselben Gruppen wie auf der Mehr-Seite. */
const groups = [
    { title: 'Wachstum', items: [
        { label: 'Zugang und Warteliste', route: 'admin.invitations.index', match: ['admin.invitations.*'] },
    ] },
    { title: 'Inhalte', items: [
        { label: 'Coaches',          route: 'admin.coaches.index',            match: ['admin.coaches.*'] },
        { label: 'Kraftübungen',     route: 'admin.strength-exercises.index', match: ['admin.strength-exercises.*'] },
        { label: 'Geteilte Workouts', route: 'admin.workouts.index',          match: ['admin.workouts.*'] },
        { label: 'Onboarding-Texte', route: 'admin.onboarding-texts.index',   match: ['admin.onboarding-texts.*'] },
    ] },
    { title: 'Nachrichten', items: [
        { label: 'Push senden', route: 'admin.push.index',       match: ['admin.push.*'] },
        { label: 'Newsletter',  route: 'admin.newsletter.index', match: ['admin.newsletter.*'] },
        { label: 'Instagram',   route: 'admin.instagram.index',  match: ['admin.instagram.*'] },
    ] },
    { title: 'Daten und Technik', items: [
        { label: 'Aktivitäten',   route: 'admin.activities.index',  match: ['admin.activities.*'] },
        { label: 'Einstellungen', route: 'admin.settings.index',    match: ['admin.settings.*'] },
        { label: 'COROS-Rohdaten', route: 'admin.coros-probe.index', match: ['admin.coros-probe.*'] },
    ] },
];

const isOn = (patterns) => patterns.some((p) => route().current(p));

/** Alles, was nicht zu einem der vier Tabs gehört, hält „Mehr“ aktiv. */
const moreActive = computed(() => ! tabs.some((t) => isOn(t.match)));

const kiActive = computed(() => route().current('admin.ai-costs'));
</script>

<template>
    <div class="min-h-screen bg-canvas text-ink">

        <!-- ═══ Desktop-Seitenleiste ═══ -->
        <aside class="hidden flex-col gap-[18px] overflow-y-auto border-r-[0.5px] border-line-strong bg-canvas px-3 py-[22px] lg:fixed lg:inset-y-0 lg:left-0 lg:z-30 lg:flex lg:w-[248px]">
            <Link :href="route('admin.dashboard')" class="flex items-center gap-2.5 px-2">
                <ZoneIcon tone="admin" class="h-8 w-8" />
                <div class="flex flex-col gap-1">
                    <Wordmark class="h-[15px] text-ink" />
                    <span class="text-[12px] font-bold leading-none text-danger">Admin</span>
                </div>
            </Link>

            <nav class="flex flex-col gap-0.5">
                <Link
                    v-for="t in tabs"
                    :key="t.route"
                    :href="route(t.route)"
                    class="flex items-center justify-between rounded-[9px] px-2.5 py-2 text-[15px] transition duration-150 ease-out-strong"
                    :class="isOn(t.match) ? 'bg-danger font-semibold text-white' : 'text-ink hover:bg-surface-2'"
                >
                    {{ t.label }}
                    <span v-if="t.route === 'admin.users.index' && nav.athletes" class="tabular-nums" :class="isOn(t.match) ? 'text-white/80' : 'text-ink-3'">{{ nav.athletes }}</span>
                    <span v-else-if="t.badge && nav[t.badge] > 0" class="rounded-[9px] px-[7px] py-px text-[12px] font-bold tabular-nums" :class="isOn(t.match) ? 'bg-white text-danger' : 'bg-danger text-white'">{{ nav[t.badge] }}</span>
                    <span v-else-if="t.dot && nav[t.dot]" class="h-2 w-2 rounded-full" :class="isOn(t.match) ? 'bg-white' : 'bg-warn'" />
                </Link>
                <Link
                    :href="route('admin.ai-costs')"
                    class="rounded-[9px] px-2.5 py-2 text-[15px] transition duration-150 ease-out-strong"
                    :class="kiActive ? 'bg-danger font-semibold text-white' : 'text-ink hover:bg-surface-2'"
                >KI-Kosten</Link>
            </nav>

            <div v-for="g in groups" :key="g.title" class="flex flex-col gap-0.5">
                <span class="px-2.5 pb-1 text-[12px] font-bold uppercase text-ink-3">{{ g.title }}</span>
                <Link
                    v-for="i in g.items"
                    :key="i.route"
                    :href="route(i.route)"
                    class="rounded-[9px] px-2.5 py-[7px] text-[15px] transition duration-150 ease-out-strong"
                    :class="isOn(i.match) ? 'bg-danger font-semibold text-white' : 'text-ink hover:bg-surface-2'"
                >{{ i.label }}</Link>
            </div>

            <div class="mt-auto flex flex-col gap-0.5">
                <Link :href="route('dashboard')" class="rounded-[9px] px-2.5 py-[7px] text-[15px] text-accent hover:bg-surface-2">Zurück zur App</Link>
                <button type="button" class="rounded-[9px] px-2.5 py-[7px] text-left text-[15px] text-ink-2 hover:bg-surface-2" @click="toggle">
                    {{ isDark ? 'Helles Design' : 'Dunkles Design' }}
                </button>
                <Link :href="route('logout')" method="post" as="button" class="rounded-[9px] px-2.5 py-[7px] text-left text-[15px] text-danger hover:bg-danger-soft">Abmelden</Link>
            </div>
        </aside>

        <!-- ═══ Inhalt ═══ -->
        <div class="flex min-h-screen flex-col pt-[calc(env(safe-area-inset-top)+14px)] lg:pl-[248px] lg:pt-0">
            <slot name="header" />
            <main class="flex-1 pb-[calc(env(safe-area-inset-bottom)+84px)] lg:pb-10">
                <slot />
            </main>
        </div>

        <!-- ═══ Tab-Leiste am Telefon ═══ -->
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t-[0.5px] border-line-strong bg-surface/85 px-1 pb-safe-tabbar backdrop-blur-xl backdrop-saturate-150 lg:hidden" aria-label="Admin">
            <div class="flex h-[52px]">
                <Link
                    v-for="t in tabs"
                    :key="t.route"
                    :href="route(t.route)"
                    class="relative flex flex-1 flex-col items-center gap-[3px] pt-2 text-[10px] font-semibold"
                    :class="isOn(t.match) ? 'text-danger' : 'text-[#8E8E93]'"
                >
                    <svg class="h-[26px] w-[26px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="t.icon" />
                    {{ t.label }}
                    <span v-if="t.badge && nav[t.badge] > 0" class="absolute left-[52%] top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-[9px] bg-danger px-1 text-[12px] font-bold text-white tabular-nums">{{ nav[t.badge] }}</span>
                    <span v-if="t.dot && nav[t.dot]" class="absolute left-[58%] top-1.5 h-[9px] w-[9px] rounded-full bg-warn" />
                </Link>
                <Link
                    :href="route('admin.more')"
                    class="flex flex-1 flex-col items-center gap-[3px] pt-2 text-[10px] font-semibold"
                    :class="moreActive ? 'text-danger' : 'text-[#8E8E93]'"
                >
                    <svg class="h-[26px] w-[26px]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></svg>
                    Mehr
                </Link>
            </div>
        </nav>
    </div>
</template>
