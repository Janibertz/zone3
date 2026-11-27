<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import { useDarkMode } from '@/Composables/useDarkMode';

/** „Mehr“ im Admin — gruppiert wie Mehr in der App (Mockup 18.11.2026). */
defineProps({ values: { type: Object, required: true } });

const { isDark, toggle } = useDarkMode();

const groups = [
    { title: 'Wachstum', rows: [
        { label: 'Zugang und Warteliste', route: 'admin.invitations.index', value: 'access' },
        { label: 'Einladungslinks', route: 'admin.invitations.index', hash: '#links', value: 'invitations' },
        { label: 'Woher Anmeldungen kommen', route: 'admin.invitations.index', hash: '#quellen', value: 'sources' },
    ] },
    { title: 'Inhalte', rows: [
        { label: 'Coaches', route: 'admin.coaches.index', value: 'coaches' },
        { label: 'Kraftübungen', route: 'admin.strength-exercises.index', value: 'exercises' },
        { label: 'Geteilte Workouts', route: 'admin.workouts.index', value: 'workouts' },
        { label: 'Onboarding-Texte', route: 'admin.onboarding-texts.index', value: 'onboarding' },
    ] },
    { title: 'Nachrichten', rows: [
        { label: 'Push senden', route: 'admin.push.index', value: 'push' },
        { label: 'Newsletter', route: 'admin.newsletter.index', value: 'newsletter' },
        { label: 'Instagram', route: 'admin.instagram.index', value: 'instagram' },
    ] },
    { title: 'Daten und Technik', rows: [
        { label: 'KI-Kosten', route: 'admin.ai-costs', value: 'aiCosts' },
        { label: 'Aktivitäten aller Athleten', route: 'admin.activities.index', value: 'activities' },
        { label: 'Wartung und Einstellungen', route: 'admin.settings.index', value: 'settings' },
        { label: 'COROS-Rohdaten', route: 'admin.coros-probe.index', value: 'probe' },
    ] },
];
</script>

<template>
    <Head title="Admin – Mehr" />

    <AdminLayout>
        <AdminPage title="Mehr">
            <div v-for="g in groups" :key="g.title" class="flex flex-col gap-1.5">
                <div class="px-1 text-[13px] font-semibold uppercase text-ink-3">{{ g.title }}</div>
                <div class="overflow-hidden rounded-[14px] bg-surface">
                    <Link v-for="(r, i) in g.rows" :key="r.label" :href="route(r.route) + (r.hash ?? '')"
                        class="flex items-center justify-between gap-3 px-3.5 py-3 active:bg-surface-2"
                        :class="i < g.rows.length - 1 ? 'border-b-[0.5px] border-line' : ''">
                        <span class="text-[16px]">{{ r.label }}</span>
                        <span class="truncate text-[15px] tabular-nums text-ink-3">{{ values[r.value] }}</span>
                    </Link>
                </div>
            </div>

            <div class="overflow-hidden rounded-[14px] bg-surface">
                <Link :href="route('dashboard')" class="block border-b-[0.5px] border-line px-3.5 py-3 text-[16px] text-accent active:bg-surface-2">Zurück zur App</Link>
                <button type="button" class="flex w-full justify-between border-b-[0.5px] border-line px-3.5 py-3 text-left active:bg-surface-2" @click="toggle">
                    <span class="text-[16px]">Darstellung</span>
                    <span class="text-[15px] text-ink-3">{{ isDark ? 'dunkel' : 'hell' }}</span>
                </button>
                <Link :href="route('logout')" method="post" as="button" class="block w-full px-3.5 py-3 text-left text-[16px] text-danger active:bg-surface-2">Abmelden</Link>
            </div>
        </AdminPage>
    </AdminLayout>
</template>
