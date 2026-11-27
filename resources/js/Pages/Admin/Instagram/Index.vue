<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSection from '@/Components/Admin/AdminSection.vue';

/** Instagram im Admin (27.11.2026): eingeplante Beiträge und ihr Stand. */
const props = defineProps({
    posts:      { type: Array, required: true },
    configured: { type: Boolean, required: true },
});

const TYPE = { image: 'Bild', carousel: 'Karussell', reel: 'Reel', trial_reel: 'Trial Reel', story: 'Story', manual_story: 'Story von Hand', manual_reel: 'Reel von Hand', manual_trial_reel: 'Trial Reel von Hand' };
const STATUS = {
    planned:   ['geplant', 'text-ink-3'],
    due:       ['fällig', 'text-warn-ink'],
    waiting:   ['Instagram verarbeitet', 'text-warn-ink'],
    published: ['veröffentlicht', 'text-success-ink'],
    notified:  ['wartet auf dich', 'text-warn-ink'],
    posted:    ['gepostet', 'text-success-ink'],
    failed:    ['Fehler', 'text-danger-ink'],
    missed:    ['verpasst', 'text-danger-ink'],
};

const fmt = (iso) => new Date(iso).toLocaleString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
const upcoming = computed(() => props.posts.filter((p) => ['planned', 'due', 'waiting', 'notified'].includes(p.status)));
const past     = computed(() => props.posts.filter((p) => !upcoming.value.includes(p)).reverse());
const thumb    = (p) => p.cover ?? (/\.(mp4|mov)/i.test(p.media[0]) ? null : p.media[0]);
const title    = (p) => (p.caption || p.sticker?.text || p.note || p.slug).split('\n')[0];
</script>

<template>
    <Head title="Admin – Instagram" />

    <AdminLayout>
        <AdminPage title="Instagram" :back="route('admin.more')" back-label="Mehr" detail>
            <p v-if="!configured" class="rounded-[14px] bg-surface px-3.5 py-3 text-[15px] text-warn-ink">
                Kein Instagram-Zugang eingetragen (INSTAGRAM_ACCOUNT_ID, INSTAGRAM_ACCESS_TOKEN). Stories von Hand kommen trotzdem als Nachricht.
            </p>

            <AdminSection v-for="group in [{ t: 'Als Nächstes', list: upcoming }, { t: 'Letzte 14 Tage', list: past }]" :key="group.t" :title="group.t" :aside="group.list.length">
                <div v-if="group.list.length" class="overflow-hidden rounded-[14px] bg-surface">
                    <component :is="p.manual ? Link : 'div'" v-for="(p, i) in group.list" :key="p.slug"
                        :href="p.manual ? route('admin.instagram.show', p.slug) : undefined"
                        class="flex items-center gap-3 px-3.5 py-2.5" :class="[i < group.list.length - 1 ? 'border-b-[0.5px] border-line' : '', p.manual ? 'active:bg-surface-2' : '']">
                        <img v-if="thumb(p)" :src="thumb(p)" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover">
                        <div v-else class="h-12 w-12 shrink-0 rounded-lg bg-surface-2" />
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[16px]">{{ title(p) }}</div>
                            <div class="text-[13px] text-ink-3">{{ fmt(p.at) }} · {{ TYPE[p.type] }}</div>
                            <div v-if="p.error" class="truncate text-[13px] text-danger">{{ p.error }}</div>
                        </div>
                        <button v-if="!p.manual && ['failed', 'missed'].includes(p.status)" type="button" class="shrink-0 text-[15px] text-accent active:opacity-60" @click.prevent="router.post(route('admin.instagram.retry', p.slug), {}, { preserveScroll: true })">Erneut</button>
                        <a v-else-if="p.permalink" :href="p.permalink" target="_blank" rel="noopener" class="shrink-0 text-[15px] text-accent">Ansehen</a>
                        <span v-else class="shrink-0 text-[13px]" :class="STATUS[p.status]?.[1]">{{ STATUS[p.status]?.[0] }}</span>
                    </component>
                </div>
                <p v-else class="px-1 text-[15px] text-ink-3">Nichts.</p>
            </AdminSection>
        </AdminPage>
    </AdminLayout>
</template>
