<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AdminPage from '@/Components/Admin/AdminPage.vue';
import AdminSection from '@/Components/Admin/AdminSection.vue';

/**
 * Eine Story zum Posten von Hand (27.11.2026): Bild oder Video sichern bzw.
 * direkt über das Teilen-Menü an Instagram geben, darunter alles, was in den
 * Sticker gehört, zum Kopieren. Die Nachricht zur Uhrzeit führt hierher.
 */
const props = defineProps({ post: { type: Object, required: true } });

const media   = computed(() => props.post.media[0]);
const isVideo = computed(() => /\.(mp4|mov)(\?.*)?$/i.test(media.value));
const when    = computed(() => new Date(props.post.at).toLocaleString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }));
const done    = computed(() => props.post.status === 'posted');
const isReel  = computed(() => props.post.type !== 'manual_story');
const kind    = computed(() => props.post.type === 'manual_trial_reel' ? 'Trial Reel' : (isReel.value ? 'Reel' : 'Story'));

const copied  = ref(null);
const sharing = ref(false);
const shareError = ref(null);

async function copy(key, text) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = null; }, 1800);
    } catch { /* ohne Zwischenablage bleibt der Text markierbar */ }
}

async function share() {
    shareError.value = null;
    sharing.value = true;
    try {
        const blob = await (await fetch(media.value)).blob();
        const name = media.value.split('/').pop().split('?')[0];
        const file = new File([blob], name, { type: blob.type || (isVideo.value ? 'video/mp4' : 'image/jpeg') });
        if (navigator.canShare?.({ files: [file] })) {
            await navigator.share({ files: [file] });
        } else {
            const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
            a.click();
            URL.revokeObjectURL(a.href);
        }
    } catch (e) {
        if (e?.name !== 'AbortError') shareError.value = 'Das hat nicht geklappt. Halte das Bild gedrückt und tippe auf „Sichern“.';
    } finally {
        sharing.value = false;
    }
}

const posted = () => router.post(route('admin.instagram.posted', props.post.slug));
</script>

<template>
    <Head :title="`Admin – ${kind} posten`" />

    <AdminLayout>
        <AdminPage :title="`${kind} posten`" :eyebrow="when" :back="route('admin.instagram.index')" back-label="Instagram" detail>
            <div class="overflow-hidden rounded-[14px] bg-black">
                <video v-if="isVideo" :src="media" controls playsinline class="mx-auto max-h-[70dvh] w-full object-contain" />
                <img v-else :src="media" alt="Story" class="mx-auto max-h-[70dvh] w-full object-contain">
            </div>

            <div class="flex flex-col gap-2">
                <button type="button" :disabled="sharing"
                    class="h-[50px] rounded-[14px] bg-accent text-[17px] font-semibold text-white active:opacity-80 disabled:opacity-50"
                    @click="share">{{ sharing ? 'Lädt …' : (isVideo ? 'Video teilen oder sichern' : 'Bild teilen oder sichern') }}</button>
                <p v-if="!isReel" class="px-1 text-[13px] text-ink-3">Im Teilen-Menü kannst du direkt Instagram wählen, dann öffnet sich die Story.</p>
                <p v-else class="px-1 text-[13px] text-ink-3">Im Teilen-Menü Instagram → Reels wählen. Dort Musik drunterlegen, unten den Text einfügen<template v-if="post.type === 'manual_trial_reel'"> und vor dem Teilen „Testreel“ einschalten</template>.</p>
                <p v-if="shareError" class="px-1 text-[13px] text-danger">{{ shareError }}</p>
            </div>

            <AdminSection v-if="isReel && post.caption" title="Text">
                <button type="button" class="flex w-full items-start justify-between gap-3 rounded-[14px] bg-surface px-3.5 py-3 text-left active:bg-surface-2" @click="copy('caption', post.caption)">
                    <span class="min-w-0 select-text whitespace-pre-line text-[16px]">{{ post.caption }}</span>
                    <span class="shrink-0 text-[15px] text-accent">{{ copied === 'caption' ? 'Kopiert' : 'Kopieren' }}</span>
                </button>
            </AdminSection>

            <AdminSection v-if="post.sticker" :title="post.sticker.label + '-Sticker'">
                <div class="overflow-hidden rounded-[14px] bg-surface">
                    <button v-if="post.sticker.text" type="button" class="flex w-full items-center justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-3 text-left active:bg-surface-2" @click="copy('text', post.sticker.text)">
                        <span class="min-w-0"><span class="block text-[13px] text-ink-3">{{ post.sticker.type === 'link' ? 'Sticker-Text' : 'Frage' }}</span><span class="block select-text text-[16px]">{{ post.sticker.text }}</span></span>
                        <span class="shrink-0 text-[15px] text-accent">{{ copied === 'text' ? 'Kopiert' : 'Kopieren' }}</span>
                    </button>
                    <button v-if="post.sticker.url" type="button" class="flex w-full items-center justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-3 text-left active:bg-surface-2" @click="copy('url', post.sticker.url)">
                        <span class="min-w-0"><span class="block text-[13px] text-ink-3">Link</span><span class="block select-text break-all text-[16px]">{{ post.sticker.url }}</span></span>
                        <span class="shrink-0 text-[15px] text-accent">{{ copied === 'url' ? 'Kopiert' : 'Kopieren' }}</span>
                    </button>
                    <button v-for="(o, i) in post.sticker.options" :key="i" type="button" class="flex w-full items-center justify-between gap-3 border-b-[0.5px] border-line px-3.5 py-3 text-left last:border-b-0 active:bg-surface-2" @click="copy('o' + i, o)">
                        <span class="min-w-0"><span class="block text-[13px] text-ink-3">Antwort {{ i + 1 }}</span><span class="block select-text text-[16px]">{{ o }}</span></span>
                        <span class="shrink-0 text-[15px] text-accent">{{ copied === 'o' + i ? 'Kopiert' : 'Kopieren' }}</span>
                    </button>
                </div>
                <p class="px-1 text-[13px] text-ink-3">Den Sticker in die gestrichelte Fläche setzen. Auf dem Bild ist sie schon frei gelassen.</p>
            </AdminSection>

            <AdminSection v-if="post.note" title="Hinweis">
                <div class="select-text whitespace-pre-line rounded-[14px] bg-surface px-3.5 py-3 text-[16px]">{{ post.note }}</div>
            </AdminSection>

            <button v-if="!done" type="button" class="h-[50px] rounded-[14px] bg-surface text-[17px] font-semibold text-accent active:bg-surface-2" @click="posted">Gepostet</button>
            <p v-else class="text-center text-[15px] text-ink-3">Schon als gepostet markiert.</p>
        </AdminPage>
    </AdminLayout>
</template>
