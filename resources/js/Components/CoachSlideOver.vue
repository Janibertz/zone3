<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { useCoachChat } from '@/Composables/useCoachChat';
import { useFinePointer } from '@/Composables/useFinePointer';
import AppButton from '@/Components/UI/AppButton.vue';
import axios from 'axios';

/**
 * Coach-Chat im Stil von Nachrichten (iMessage), Mockup „Zone3 Coach-Chat
 * wie iMessage“, von Jan am 11.11.2026 abgenommen.
 *
 * Am iPhone Vollbild; der Kopf bleibt stehen, wenn die Tastatur aufgeht
 * (siehe syncViewport), am Desktop ein Panel rechts. Blasen mit Spitze und
 * Gruppen, die Uhrzeit als Zeile über einem neuen Abschnitt, je Nachricht
 * per Wischen nach links (Telefon) bzw. beim Darüberfahren (Desktop).
 */

const { isOpen, close, takePending } = useCoachChat();
const page  = usePage();
const coach = computed(() => page.props.coach);
const coachName = computed(() => coach.value?.name ?? 'Dein Coach');
const finePointer = useFinePointer();

const messages   = ref([]);
const inputText  = ref('');
const isLoading  = ref(false);
const hasLoaded  = ref(false);
const messagesEl = ref(null);
const inputEl    = ref(null);
const panelEl    = ref(null);
const pendingReload = ref(false);

/* Die Coach-Farbe steht als Name in der Datenbank. Avatar wie bei Kontakten
 * in Nachrichten: senkrechter Verlauf in der Systemfarbe. */
const avatarGradients = {
    orange: 'linear-gradient(180deg,#FFAE45,#F28C0F)',
    blue:   'linear-gradient(180deg,#5AA9FF,#0071E3)',
    green:  'linear-gradient(180deg,#5BD27A,#248A3D)',
    purple: 'linear-gradient(180deg,#B98AF0,#8A3FD1)',
};
const avatarStyle = computed(() => ({
    background: avatarGradients[coach.value?.avatar_color] ?? avatarGradients.purple,
}));
const initials = computed(() => coach.value?.avatar_initials ?? 'CO');

/* ── Schnell fragen (Plus-Knopf) ──────────────────────────────────────── */
const quickPrompts = [
    { text: 'Was soll ich heute trainieren?',        color: '#0071E3', icon: 'clock' },
    { text: 'Bin ich auf Kurs für mein Rennen?',      color: '#248A3D', icon: 'flag' },
    { text: 'Analysiere mein letztes Training',       color: '#5856D6', icon: 'chart' },
    { text: 'Mach das heutige Training schwerer',     color: '#C45C00', icon: 'up' },
    { text: 'Ich bin krank und falle aus',            color: '#D70015', icon: 'plus' },
    { text: 'Ich möchte meine Zielzeit fürs Rennen anpassen', label: 'Zielzeit fürs Rennen anpassen', color: '#8A3FD1', icon: 'target' },
];
const promptIcons = {
    clock:  'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2Zm0 2.5V8l2.5 1.5',
    flag:   'M3 14V2l9 3.5L3 9',
    chart:  'M2 13h12M4 10l3-3 2 2 4-5',
    up:     'M8 13V3M3.5 7.5 8 3l4.5 4.5',
    plus:   'M8 3v10M3 8h10',
    target: 'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2Zm0 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z',
};
const emptyPrompts = quickPrompts.slice(0, 3).concat({ text: 'Ich bin krank und falle eine Woche aus' });

const sheet = ref(null);   // 'prompts' | 'coach' | null

function pickPrompt(p) {
    sheet.value = null;
    sendMessage(p.text);
}

/* ── Verlauf in Zeilen ────────────────────────────────────────────────────
 * Aus den Nachrichten wird eine flache Liste: Zeitzeilen, Blasen, Karten,
 * Systemzeilen. So weiss jede Blase, ob sie die letzte ihrer Gruppe ist
 * (nur die trägt die Spitze) — wie in Nachrichten. */
const TIME_GAP_MS = 30 * 60 * 1000;

function timeLabel(dateStr) {
    const d = new Date(dateStr);
    const time = d.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    const startOf = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const days = Math.round((startOf(new Date()) - startOf(d)) / 86400000);

    let day;
    if (days === 0)      day = 'Heute';
    else if (days === 1) day = 'Gestern';
    else if (days < 7)   day = d.toLocaleDateString('de-DE', { weekday: 'long' });
    else                 day = d.toLocaleDateString('de-DE', { day: 'numeric', month: 'short', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });

    return { day, time };
}

function formatTime(dateStr) {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
}

const changesOf   = (msg) => (msg.actions ?? []).filter(a => a.type !== 'proposal');
const proposalsOf = (msg) => (msg.actions ?? []).filter(a => a.type === 'proposal');

const rows = computed(() => {
    const out = [];
    let prev = null;

    messages.value.forEach((msg, i) => {
        const t = msg.created_at ? new Date(msg.created_at).getTime() : null;
        const pt = prev?.created_at ? new Date(prev.created_at).getTime() : null;
        const newDay = t && pt && new Date(t).toDateString() !== new Date(pt).toDateString();

        if (t && (!pt || newDay || t - pt > TIME_GAP_MS)) {
            out.push({ kind: 'time', key: `t${i}`, ...timeLabel(msg.created_at) });
        }

        const last = out[out.length - 1];
        const sameGroup = last && (last.kind === 'bubble' || last.kind === 'proposal') && last.role === msg.role;
        const groupStart = !sameGroup;

        out.push({ kind: 'bubble', key: `m${i}`, role: msg.role, msg, groupStart, time: formatTime(msg.created_at) });

        for (const p of proposalsOf(msg)) {
            out.push({ kind: 'proposal', key: `p${i}-${p.recommendation_id}`, role: msg.role, p });
            if (p.status && p.status !== 'pending') {
                out.push({ kind: 'decision', key: `d${i}-${p.recommendation_id}`, p });
            }
        }
        for (const [ai, a] of changesOf(msg).entries()) {
            out.push({ kind: 'change', key: `c${i}-${ai}`, a });
        }

        prev = msg;
    });

    // Spitze: letzte Blase einer Gruppe (eine Karte trägt keine).
    for (let i = 0; i < out.length; i++) {
        const r = out[i];
        if (r.kind !== 'bubble') continue;
        let j = i + 1;
        while (j < out.length && out[j].kind === 'proposal' && out[j].role === r.role) j++;
        const next = out[j];
        r.tail = !(next && next.kind === 'bubble' && next.role === r.role) && j === i + 1;
        r.groupEnd = !(out[i + 1] && (out[i + 1].kind === 'bubble' || out[i + 1].kind === 'proposal') && out[i + 1].role === r.role);
    }

    return out;
});

/** „Zugestellt“ unter deiner letzten Nachricht, solange sie die letzte ist. */
const deliveredKey = computed(() => {
    const last = messages.value[messages.value.length - 1];
    if (!last || last.role !== 'user' || last.sending) return null;
    return `m${messages.value.length - 1}`;
});
const sendingKey = computed(() => {
    const last = messages.value[messages.value.length - 1];
    return last?.role === 'user' && last.sending ? `m${messages.value.length - 1}` : null;
});

/* ── Laden, Senden, Warten ────────────────────────────────────────────── */
async function fetchMessages() {
    if (hasLoaded.value) return;
    try {
        const res = await axios.get(route('coach.messages'));
        messages.value = res.data.messages ?? [];
    } catch {
        // leer lassen
    } finally {
        hasLoaded.value = true;
    }
}

/**
 * Fragt nach, bis die Antwort des Coaches da ist. Ein Plan-Umbau mit
 * Werkzeugen dauert auch mal eine Minute; nach drei Minuten gilt es als
 * gescheitert — der Job schreibt dann ohnehin eine Fehlermeldung.
 */
async function waitForReply(messageId) {
    const deadline = Date.now() + 180_000;
    let delay = 1000;

    while (Date.now() < deadline) {
        await new Promise(r => setTimeout(r, delay));
        const res = await axios.get(route('coach.reply', messageId));
        if (!res.data.pending) return res.data;
        delay = Math.min(delay + 500, 3000);
    }

    throw new Error('timeout');
}

async function pushIncoming(msg) {
    const stay = atBottom.value;
    messages.value.push(msg);
    await nextTick();
    if (stay) scrollToBottom(true);
    else newBelow.value = true;
}

async function sendMessage(text) {
    const content = (text ?? inputText.value).trim();
    if (!content || isLoading.value) return;

    inputText.value = '';
    resizeInput();
    isLoading.value = true;

    const mine = { role: 'user', content, created_at: new Date().toISOString(), sending: true };
    messages.value.push(mine);
    await nextTick();
    scrollToBottom(true);

    try {
        // Die Antwort entsteht in der Queue; hier wird nur nachgefragt.
        const sent = await axios.post(route('coach.send'), { message: content });
        const entry = messages.value[messages.value.length - 1];
        if (entry === mine || entry?.content === content) entry.sending = false;
        mine.sending = false;

        const reply = await waitForReply(sent.data.message_id);
        if (reply.response) {
            const actions = reply.actions_taken ?? [];
            await pushIncoming({
                role: 'assistant',
                content: reply.response,
                actions,
                created_at: reply.timestamp ?? new Date().toISOString(),
            });

            // Nachgeladen wird erst beim Schließen: das Layout gehört zur
            // Seite, ein Reload währenddessen würde das Gespräch abreißen.
            if (actions.some(a => a.reload)) pendingReload.value = true;
        }
    } catch (e) {
        mine.sending = false;
        const status = e?.response?.status;
        const fromServer = e?.response?.data?.message;

        const msg = status === 429
            ? `${fromServer ?? 'Tageslimit für KI-Anfragen erreicht.'} Morgen geht es weiter — oder du hebst dein Limit im Admin-Bereich an.`
            : (fromServer ?? 'Entschuldigung, ich konnte gerade nicht antworten. Versuche es gleich nochmal.');

        await pushIncoming({ role: 'assistant', content: msg, created_at: new Date().toISOString(), isError: true });
    } finally {
        isLoading.value = false;
        await nextTick();
        if (atBottom.value) scrollToBottom(true);
    }
}

/* ── Eingabe ──────────────────────────────────────────────────────────────
 * Am iPhone macht Return eine neue Zeile wie in Nachrichten, gesendet wird
 * mit dem Pfeil. Am Desktop sendet Enter, Shift+Enter bricht um (Jan). */
function handleKeydown(e) {
    if (e.key !== 'Enter' || e.isComposing) return;
    if (!finePointer.value || e.shiftKey) return;
    e.preventDefault();
    sendMessage();
}

/** Wächst mit dem Text bis 6 Zeilen; `field-sizing` kann Safari nicht. */
function resizeInput() {
    nextTick(() => {
        const el = inputEl.value;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 140) + 'px';
    });
}

/* ── Scrollen ─────────────────────────────────────────────────────────────
 * Wer nach oben liest, wird von einer neuen Nachricht nicht weggerissen —
 * stattdessen erscheint „Neue Nachricht“. */
const atBottom = ref(true);
const newBelow = ref(false);

function onScroll() {
    const el = messagesEl.value;
    if (!el) return;
    atBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
    if (atBottom.value) newBelow.value = false;
}

function scrollToBottom(smooth = false) {
    const el = messagesEl.value;
    if (!el) return;
    el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    atBottom.value = true;
    newBelow.value = false;
}

/* ── Uhrzeit per Wischen (Telefon) ────────────────────────────────────────
 * Wie in Nachrichten: der Verlauf lässt sich nach links ziehen, rechts
 * erscheinen die Uhrzeiten; loslassen schnappt zurück. */
const reveal = ref(0);
const revealing = ref(false);
let touch = null;

function onTouchStart(e) {
    const t = e.touches[0];
    touch = { x: t.clientX, y: t.clientY, axis: null };
}
function onTouchMove(e) {
    if (!touch) return;
    const t = e.touches[0];
    const dx = t.clientX - touch.x;
    const dy = t.clientY - touch.y;
    if (!touch.axis && Math.abs(dx) + Math.abs(dy) > 8) touch.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
    if (touch.axis !== 'x') return;
    revealing.value = true;
    reveal.value = Math.max(-64, Math.min(0, dx * 0.7));
}
function onTouchEnd() {
    touch = null;
    revealing.value = false;
    reveal.value = 0;
}

/* ── Tastatur am iPhone ───────────────────────────────────────────────────
 * Safari und die App (WKWebView) verkleinern bei offener Tastatur nicht das
 * Layout, sondern schieben die Seite hoch — der Kopf verschwand, und die
 * Eingabe sprang. Darum folgt das Panel dem sichtbaren Bereich
 * (visualViewport): Höhe = sichtbare Höhe, oben = dessen Versatz. */
const vp = ref({ height: null, top: 0, keyboard: false });
const isPhone = ref(false);

function syncViewport() {
    isPhone.value = window.innerWidth < 640;
    const vv = window.visualViewport;
    if (!vv || !isPhone.value) {
        vp.value = { height: null, top: 0, keyboard: false };
        return;
    }
    const keyboard = window.innerHeight - vv.height > 120;
    const wasBottom = atBottom.value;
    vp.value = { height: vv.height, top: vv.offsetTop, keyboard };
    if (wasBottom) nextTick(() => scrollToBottom());
}

const panelStyle = computed(() => vp.value.height
    ? { height: `${vp.value.height}px`, top: `${vp.value.top}px`, bottom: 'auto' }
    : {});

/* ── Vorschläge ────────────────────────────────────────────────────────── */
function proposalRows(a) {
    const fields = [
        ['distance_km', 'Distanz', 'km'], ['duration_min', 'Dauer', 'min'],
        ['pace_target', 'Pace', '/km'], ['zone', 'Zone', ''],
    ];
    // Das Modell schreibt „min/km" gern selbst in die Pace — die Einheit steht daneben.
    const fmt = (v) => (v == null || v === '' ? '—' : String(v).replace(/\s*(min)?\/km$/, '').replace('.', ','));

    return fields
        .filter(([k]) => String(a.before?.[k] ?? '') !== String(a.after?.[k] ?? ''))
        .map(([k, label, unit]) => ({ label, from: fmt(a.before?.[k]), to: fmt(a.after?.[k]), unit }));
}

const deciding = ref(null);

async function decideProposal(action, decision) {
    if (deciding.value) return;
    deciding.value = action.recommendation_id;
    try {
        const res = await axios.post(route(`recommendations.${decision}`, action.recommendation_id), {}, {
            headers: { Accept: 'application/json' },
        });
        action.status = res.data.status;
        if (action.status === 'accepted') pendingReload.value = true;
    } catch {
        action.status = 'expired';
    } finally {
        deciding.value = null;
        await nextTick();
        if (atBottom.value) scrollToBottom(true);
    }
}

function decisionText(p) {
    if (p.status === 'accepted') return { lead: 'Du hast den Vorschlag angenommen', bold: `${p.when ?? 'Einheit'} angepasst` };
    if (p.status === 'rejected') return { lead: 'Du hast den Vorschlag abgelehnt', bold: 'alles bleibt wie geplant' };
    return { lead: 'Vorschlag nicht mehr offen', bold: null };
}

const changeIcons = {
    memory:            'M8 2.5a3.5 3.5 0 0 0-2 6.37V11h4V8.87A3.5 3.5 0 0 0 8 2.5ZM6.5 13.5h3',
    session_created:   'M8 3v10M3 8h10',
    session_moved:     'M5.5 4.5 3 7l2.5 2.5M3 7h8M10.5 11.5 13 9l-2.5-2.5M13 9H5',
    session_deleted:   'M3.5 4.5h9M6.5 4.5V3h3v1.5M5 4.5l.5 8.5h5l.5-8.5',
    sessions_skipped:  'M4 3.5v9l6-4.5zM12 3.5v9',
    training_paused:   'M6 4v8M10 4v8',
    training_resumed:  'M5 3.5v9l7-4.5z',
    event_updated:     'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2Zm0 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5Z',
};
const editIcon = 'M10.5 2.5 13 5 6 12H3.5V9.5z';

/* ── Schließen, Aufräumen ─────────────────────────────────────────────── */
function closePanel() {
    sheet.value = null;
    confirming.value = null;
    close();

    if (pendingReload.value) {
        pendingReload.value = false;
        router.reload({ preserveScroll: true });
    }
}

// Verlauf und Gemerktes sind zwei verschiedene Dinge: der Verlauf ist die
// Unterhaltung, die Notizen sind das, was der Coach über dich gelernt hat.
const confirming = ref(null);   // 'messages' | 'notes' | null
const clearing = ref(false);

const confirmText = computed(() => confirming.value === 'notes'
    ? 'Alles löschen, was sich der Coach über dich gemerkt hat? Der Gesprächsverlauf bleibt.'
    : 'Den gesamten Gesprächsverlauf löschen? Was sich der Coach gemerkt hat, bleibt.');

async function confirmClear() {
    clearing.value = true;
    try {
        if (confirming.value === 'notes') {
            await axios.delete(route('coach.notes.destroy'));
        } else {
            await axios.delete(route('coach.messages.destroy'));
            messages.value = [];
        }
        confirming.value = null;
        sheet.value = null;
    } finally {
        clearing.value = false;
    }
}

function onKeydown(e) {
    if (e.key !== 'Escape' || !isOpen.value) return;
    if (confirming.value) { confirming.value = null; return; }
    if (sheet.value)      { sheet.value = null; return; }
    closePanel();
}

/* Hinter dem Vollbild darf die Seite nicht mitscrollen. */
function lockPage(lock) {
    document.documentElement.style.overflow = lock ? 'hidden' : '';
}

/* Wächst das Eingabefeld oder geht die Tastatur auf, wird der Verlauf
 * kleiner — wer unten war, bleibt unten. */
let resizeObs = null;
watch(messagesEl, (el, old) => {
    if (old) resizeObs?.unobserve(old);
    if (!el) return;
    resizeObs ??= new ResizeObserver(() => { if (atBottom.value) scrollToBottom(); });
    resizeObs.observe(el);
    if (el.firstElementChild) resizeObs.observe(el.firstElementChild);
});

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.visualViewport?.addEventListener('resize', syncViewport);
    window.visualViewport?.addEventListener('scroll', syncViewport);
    window.addEventListener('resize', syncViewport);
    syncViewport();
});
onUnmounted(() => {
    resizeObs?.disconnect();
    window.removeEventListener('keydown', onKeydown);
    window.visualViewport?.removeEventListener('resize', syncViewport);
    window.visualViewport?.removeEventListener('scroll', syncViewport);
    window.removeEventListener('resize', syncViewport);
    lockPage(false);
});

watch(isOpen, async (val) => {
    lockPage(val);
    if (!val) return;

    syncViewport();
    await fetchMessages();
    await nextTick();
    scrollToBottom();

    // Wer den Chat aus der Zielprüfung heraus öffnet, hat seine Frage
    // schon gestellt — sie kommt als pendingMessage mit und geht direkt raus.
    const pending = takePending();
    if (pending) {
        await sendMessage(pending);
        return;
    }

    // Am Telefon nicht sofort die Tastatur aufreißen — man will erst lesen.
    if (finePointer.value) inputEl.value?.focus();
}, { immediate: true });

/** Schlichtes Markdown: fett, Listen, Code, Absätze. */
function renderMarkdown(text) {
    if (!text) return '';
    return text
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code class="rounded bg-surface-3 px-1 text-[0.85em]">$1</code>')
        .replace(/^#{1,3} (.+)$/gm, '<p class="mt-2 mb-0.5 font-semibold">$1</p>')
        .replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>')
        .replace(/(<li[\s\S]+?<\/li>)/g, '<ul class="my-1 space-y-0.5">$1</ul>')
        .replace(/\n\n/g, '</p><p class="mt-2">')
        .replace(/\n/g, '<br>');
}

const isEmpty = computed(() => hasLoaded.value && messages.value.length === 0 && !isLoading.value);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="isOpen" class="fixed inset-0 z-40 hidden bg-black/30 sm:block" @click="closePanel" />
        </Transition>

        <Transition
            enter-active-class="transition-transform duration-300 ease-sheet"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200 ease-out-strong"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="isOpen"
                ref="panelEl"
                class="chat fixed inset-0 z-50 flex w-full flex-col overflow-hidden sm:left-auto sm:max-w-[480px] sm:shadow-[-8px_0_40px_rgba(0,0,0,0.18)]"
                :style="panelStyle"
                role="dialog"
                aria-modal="true"
                :aria-label="`Chat mit ${coachName}`"
            >
                <!-- ── Kopf ──────────────────────────────────────────────
                     Telefon: Zurück links, Avatar mittig wie in Nachrichten.
                     Desktop: Avatar und Name links, Schließen rechts. -->
                <header
                    v-if="!isEmpty || !isPhone"
                    class="relative z-10 shrink-0 border-b-[0.5px] border-black/15 bg-[#F9F9F9]/95 pt-safe backdrop-blur-xl dark:border-white/15 dark:bg-[#161618]/95"
                >
                    <div class="flex h-[72px] items-start justify-between px-2 sm:hidden">
                        <button aria-label="Zurück" class="flex h-11 w-11 items-center justify-center text-accent active:opacity-50" @click="closePanel">
                            <svg width="13" height="22" viewBox="0 0 13 22" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 2 2 11l9 9" /></svg>
                        </button>
                        <button class="flex flex-col items-center gap-[3px] pt-0.5 active:opacity-60" :aria-label="`${coachName}: Optionen`" @click="sheet = 'coach'">
                            <span class="flex h-[46px] w-[46px] items-center justify-center rounded-full text-[18px] font-semibold text-white" :style="avatarStyle">{{ initials }}</span>
                            <span class="flex items-center gap-0.5 text-[11px] text-ink">
                                {{ coachName }}
                                <svg width="6" height="10" viewBox="0 0 6 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="text-ink-3" aria-hidden="true"><path d="m1 1 4 4-4 4" /></svg>
                            </span>
                        </button>
                        <span class="w-11" />
                    </div>

                    <div class="hidden h-16 items-center gap-3 pl-[18px] pr-3 sm:flex">
                        <button class="flex flex-1 items-center gap-2.5 text-left" :aria-label="`${coachName}: Optionen`" @click="sheet = 'coach'">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full text-[14px] font-semibold text-white" :style="avatarStyle">{{ initials }}</span>
                            <span class="flex flex-col">
                                <span class="text-[15px] font-semibold leading-tight text-ink">{{ coachName }}</span>
                                <span class="text-[12px] text-ink-3">Dein Coach</span>
                            </span>
                        </button>
                        <button aria-label="Schließen" class="flex h-9 w-9 items-center justify-center rounded-full bg-surface-2 text-ink-3 transition active:scale-95" @click="closePanel">
                            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M2 2l8 8M10 2l-8 8" /></svg>
                        </button>
                    </div>
                </header>

                <!-- Erster Start am Telefon: nur Zurück -->
                <header v-else class="shrink-0 pt-safe">
                    <div class="flex h-11 items-center px-2">
                        <button aria-label="Zurück" class="flex h-11 w-11 items-center justify-center text-accent active:opacity-50" @click="closePanel">
                            <svg width="13" height="22" viewBox="0 0 13 22" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 2 2 11l9 9" /></svg>
                        </button>
                    </div>
                </header>

                <!-- ── Erster Start ────────────────────────────────────── -->
                <div v-if="isEmpty" class="flex min-h-0 flex-1 flex-col overflow-y-auto px-4 pb-2">
                    <div class="mt-9 flex flex-col items-center text-center">
                        <span class="flex h-[88px] w-[88px] items-center justify-center rounded-full text-[34px] font-semibold text-white" :style="avatarStyle">{{ initials }}</span>
                        <h2 class="mt-3.5 text-[28px] font-extrabold tracking-[-0.03em] text-ink">{{ coachName }}</h2>
                        <p class="mt-1 text-[15px] text-ink-3">Dein Coach · antwortet meist in Sekunden</p>
                        <p class="mx-3 mt-[18px] max-w-sm text-[15px] leading-[21px] text-ink-2">
                            {{ coachName }} kennt deinen Plan, deine Läufe und deine Tagesform. Frag, was du willst, oder sag, was sich ändern soll.
                        </p>
                    </div>
                    <div class="flex-1" />
                    <p class="mx-1 mb-2 mt-6 text-right text-[12px] font-semibold text-ink-3">Zum Beispiel</p>
                    <div class="flex flex-col items-end gap-1.5">
                        <button v-for="p in emptyPrompts" :key="p.text"
                            class="rounded-[18px] bg-canvas px-3.5 py-[9px] text-left text-[15px] text-accent-ink transition active:scale-[0.98] active:bg-surface-2"
                            @click="sendMessage(p.text)">
                            {{ p.text }}
                        </button>
                    </div>
                </div>

                <!-- ── Verlauf ─────────────────────────────────────────── -->
                <div
                    v-else
                    ref="messagesEl"
                    class="relative min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-contain px-3 pb-2 pt-2.5"
                    @scroll.passive="onScroll"
                    @touchstart.passive="onTouchStart"
                    @touchmove.passive="onTouchMove"
                    @touchend="onTouchEnd"
                    @touchcancel="onTouchEnd"
                >
                    <div class="flex min-h-full flex-col justify-end">
                        <template v-for="r in rows" :key="r.key">
                            <!-- Zeitzeile -->
                            <p v-if="r.kind === 'time'" class="mb-2.5 mt-4 text-center text-[11px] text-ink-3 first:mt-0">
                                <b class="font-semibold">{{ r.day }}</b> {{ r.time }}
                            </p>

                            <!-- Blase -->
                            <div v-else-if="r.kind === 'bubble'"
                                class="group relative flex items-end gap-2"
                                :class="[
                                    r.role === 'user' ? 'flex-row-reverse' : 'flex-row',
                                    r.groupStart ? 'mt-3 first:mt-0' : 'mt-0.5',
                                ]">
                                <div class="flex w-full items-end gap-2 transition-transform"
                                    :class="[r.role === 'user' ? 'flex-row-reverse' : 'flex-row', revealing ? 'duration-0' : 'duration-300 ease-out-strong']"
                                    :style="{ transform: `translateX(${reveal}px)` }">
                                    <div
                                        class="bubble relative max-w-[min(78%,300px)] select-text rounded-[19px] px-[13px] pb-2 pt-[7px] text-[17px] leading-[22px] tracking-[-0.015em] sm:text-[15px] sm:leading-5"
                                        :class="[
                                            r.role === 'user' ? 'me mr-1 whitespace-pre-wrap' : (r.msg.isError ? 'err ml-1 whitespace-pre-wrap' : 'them ml-1'),
                                            r.tail ? 'tail' : '',
                                        ]"
                                        v-html="r.role === 'assistant' && !r.msg.isError ? renderMarkdown(r.msg.content) : r.msg.content"
                                    />
                                    <!-- Uhrzeit beim Darüberfahren (Desktop) -->
                                    <span v-if="r.time" class="mb-1 hidden shrink-0 text-[11px] text-ink-3 group-hover:inline">{{ r.time }}</span>
                                </div>
                                <!-- Uhrzeit beim Wischen (Telefon) -->
                                <span v-if="r.time"
                                    class="pointer-events-none absolute bottom-1.5 right-[-56px] w-12 text-left text-[11px] text-ink-3 transition-transform"
                                    :class="revealing ? 'duration-0' : 'duration-300 ease-out-strong'"
                                    :style="{ transform: `translateX(${reveal}px)`, opacity: Math.min(1, -reveal / 40) }">{{ r.time }}</span>
                            </div>

                            <!-- Vorschlag als Blase -->
                            <article v-else-if="r.kind === 'proposal'"
                                class="them ml-1 mt-0.5 w-[min(82%,300px)] self-start rounded-[19px] px-3.5 py-3">
                                <p class="text-[12px] font-semibold text-accent-ink">Vorschlag · {{ r.p.when }}</p>
                                <p v-if="r.p.before?.title" class="mt-1 text-[14px] text-ink-3 line-through">{{ r.p.before.title }}</p>
                                <p class="text-[17px] font-semibold leading-[22px] text-ink sm:text-[16px]">{{ r.p.after?.title }}</p>
                                <div v-if="proposalRows(r.p).length" class="mt-2 space-y-[3px] text-[14px] sm:text-[13px]">
                                    <p v-for="row in proposalRows(r.p)" :key="row.label" class="flex justify-between gap-3">
                                        <span class="text-ink-3">{{ row.label }}</span>
                                        <span class="tabular-nums text-ink">
                                            <span class="text-ink-3">{{ row.from }}</span> → <b class="font-semibold">{{ row.to }}</b><span v-if="row.unit && row.to !== '—'">&nbsp;{{ row.unit }}</span>
                                        </span>
                                    </p>
                                </div>
                                <p v-if="r.p.reason" class="mt-2 text-[14px] leading-[19px] text-ink-2 sm:text-[13px]">{{ r.p.reason }}</p>
                                <div v-if="!r.p.status || r.p.status === 'pending'" class="mt-[11px] grid grid-cols-2 gap-2">
                                    <button class="h-9 rounded-full bg-surface text-[15px] font-medium text-ink transition active:scale-[0.97] disabled:opacity-40 sm:h-8 sm:text-[14px]"
                                        :disabled="deciding === r.p.recommendation_id" @click="decideProposal(r.p, 'reject')">Ablehnen</button>
                                    <button class="h-9 rounded-full bg-accent text-[15px] font-semibold text-white transition active:scale-[0.97] disabled:opacity-40 sm:h-8 sm:text-[14px]"
                                        :disabled="deciding === r.p.recommendation_id" @click="decideProposal(r.p, 'accept')">Annehmen</button>
                                </div>
                                <p v-else-if="r.p.status === 'accepted'" class="mt-2 flex items-center gap-1.5 text-[14px] text-success-ink">
                                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m2.5 7.5 3 3 6-7" /></svg>
                                    Angenommen
                                </p>
                                <p v-else class="mt-2 text-[14px] text-ink-3">{{ r.p.status === 'rejected' ? 'Abgelehnt' : 'Nicht mehr offen' }}</p>
                            </article>

                            <!-- Entscheidung als Systemzeile -->
                            <p v-else-if="r.kind === 'decision'" class="mx-6 mt-2.5 text-center text-[11px] leading-4 text-ink-3">
                                {{ decisionText(r.p).lead }}<template v-if="decisionText(r.p).bold"> · <b class="font-semibold text-ink-2">{{ decisionText(r.p).bold }}</b></template>
                            </p>

                            <!-- Was der Coach geändert hat -->
                            <p v-else-if="r.kind === 'change'" class="mx-6 mt-2.5 flex items-start justify-center gap-1.5 text-center text-[11px] leading-4 text-ink-3">
                                <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 shrink-0" aria-hidden="true"><path :d="changeIcons[r.a.type] ?? editIcon" /></svg>
                                <span><b class="font-semibold text-ink-2">{{ r.a.label }}</b></span>
                            </p>

                            <!-- Zugestellt / Wird gesendet -->
                            <p v-if="r.key === deliveredKey" class="mr-1.5 mt-[3px] text-right text-[11px] font-medium text-ink-3">Zugestellt</p>
                            <p v-if="r.key === sendingKey" class="mr-1.5 mt-[3px] text-right text-[11px] font-medium text-ink-3">Wird gesendet …</p>
                        </template>

                        <!-- Der Coach schreibt -->
                        <div v-if="isLoading" class="them ml-1 mt-3 flex w-fit items-center gap-[5px] self-start rounded-[19px] px-3.5 py-3" :aria-label="`${coachName} schreibt`">
                            <span class="typing-dot" /><span class="typing-dot" style="animation-delay:.18s" /><span class="typing-dot" style="animation-delay:.36s" />
                        </div>
                    </div>
                </div>

                <!-- Neue Nachricht, während man weiter oben liest -->
                <div class="pointer-events-none relative z-10 h-0">
                    <Transition enter-active-class="transition duration-200 ease-out-strong" enter-from-class="opacity-0 translate-y-2"
                        leave-active-class="transition duration-150 ease-out-strong" leave-to-class="opacity-0 translate-y-2">
                        <button v-if="newBelow" class="pointer-events-auto absolute bottom-2 left-1/2 flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-accent px-3.5 py-1.5 text-[13px] font-semibold text-white shadow-lg active:scale-95"
                            @click="scrollToBottom(true)">
                            <svg width="11" height="11" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 2v8M2.5 6.5 6 10l3.5-3.5" /></svg>
                            Neue Nachricht
                        </button>
                    </Transition>
                </div>

                <!-- ── Eingabe ─────────────────────────────────────────── -->
                <footer class="chat-bg shrink-0 px-3 pt-1.5 sm:px-3.5"
                    :class="vp.keyboard ? 'pb-2' : 'pb-safe-chat'">
                    <div class="flex items-end gap-2">
                        <button aria-label="Fragen und mehr"
                            class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full bg-surface-2 text-ink-3 transition active:scale-95 sm:h-8 sm:w-8"
                            :class="sheet === 'prompts' ? 'rotate-45' : ''"
                            @click="sheet = sheet === 'prompts' ? null : 'prompts'">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M8 2v12M2 8h12" /></svg>
                        </button>
                        <label class="flex min-h-[34px] flex-1 items-end gap-1.5 rounded-[18px] border border-line-strong py-[3px] pl-[13px] pr-[3px] transition-colors focus-within:border-accent sm:min-h-8">
                            <span class="sr-only">Nachricht</span>
                            <textarea
                                ref="inputEl"
                                v-model="inputText"
                                rows="1"
                                enterkeyhint="enter"
                                :placeholder="isEmpty ? `Nachricht an ${coachName}` : (isPhone ? 'Nachricht' : `Nachricht an ${coachName}`)"
                                class="block max-h-[140px] min-w-0 flex-1 resize-none border-0 bg-transparent p-0 py-[3px] text-[17px] leading-[22px] text-ink placeholder:text-ink-3 focus:ring-0 sm:text-[15px] sm:leading-5"
                                @input="resizeInput"
                                @keydown="handleKeydown"
                                @focus="sheet = null"
                            />
                            <button v-if="inputText.trim()"
                                aria-label="Senden"
                                :disabled="isLoading"
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent text-white transition active:scale-90 disabled:opacity-40 sm:h-[26px] sm:w-[26px]"
                                @mousedown.prevent
                                @click="sendMessage()">
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 12V2M2.5 6.5 7 2l4.5 4.5" /></svg>
                            </button>
                        </label>
                    </div>
                    <p class="mt-2 hidden text-center text-[11px] text-ink-3 sm:block">Enter sendet · Shift + Enter neue Zeile · Uhrzeit beim Darüberfahren</p>
                </footer>

                <!-- ── Blätter: Schnell fragen / Coach ─────────────────── -->
                <Transition enter-active-class="transition-opacity duration-200" enter-from-class="opacity-0"
                    leave-active-class="transition-opacity duration-150" leave-to-class="opacity-0">
                    <div v-if="sheet" class="absolute inset-0 z-20 bg-black/20" @click="sheet = null; confirming = null" />
                </Transition>
                <Transition enter-active-class="transition-transform duration-300 ease-sheet" enter-from-class="translate-y-full"
                    leave-active-class="transition-transform duration-200 ease-out-strong" leave-to-class="translate-y-full">
                    <section v-if="sheet === 'prompts'" class="absolute inset-x-0 bottom-0 z-30 rounded-t-[14px] bg-surface pb-safe-chat pt-1.5 shadow-sheet" aria-label="Schnell fragen">
                        <div class="mx-auto mb-2.5 h-[5px] w-9 rounded-full bg-line-strong" />
                        <p class="mx-4 mb-1 text-[13px] font-semibold text-ink-3">Schnell fragen</p>
                        <button v-for="(p, i) in quickPrompts" :key="p.text"
                            class="flex h-[52px] w-full items-center gap-3 px-4 text-left text-[17px] text-ink transition active:bg-surface-2 sm:h-12 sm:text-[15px]"
                            @click="pickPrompt(p)">
                            <span class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-lg text-white" :style="{ background: p.color }">
                                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="promptIcons[p.icon]" /></svg>
                            </span>
                            <span class="flex h-full flex-1 items-center" :class="i < quickPrompts.length - 1 ? 'border-b-[0.5px] border-line' : ''">{{ p.label ?? p.text }}</span>
                        </button>
                    </section>
                </Transition>
                <Transition enter-active-class="transition-transform duration-300 ease-sheet" enter-from-class="translate-y-full"
                    leave-active-class="transition-transform duration-200 ease-out-strong" leave-to-class="translate-y-full">
                    <section v-if="sheet === 'coach'" class="absolute inset-x-0 bottom-0 z-30 rounded-t-[14px] bg-surface px-4 pb-safe-chat pt-1.5 shadow-sheet" :aria-label="coachName">
                        <div class="mx-auto mb-4 h-[5px] w-9 rounded-full bg-line-strong" />
                        <div class="flex flex-col items-center text-center">
                            <span class="flex h-16 w-16 items-center justify-center rounded-full text-[24px] font-semibold text-white" :style="avatarStyle">{{ initials }}</span>
                            <p class="mt-2 text-[20px] font-bold text-ink">{{ coachName }}</p>
                            <p v-if="coach?.tagline" class="mt-0.5 text-[13px] text-ink-3">{{ coach.tagline }}</p>
                        </div>

                        <div v-if="confirming" class="mt-4 rounded-[12px] bg-danger-soft p-3.5">
                            <p class="text-[15px] leading-snug text-danger-ink">{{ confirmText }}</p>
                            <div class="mt-3 flex gap-2">
                                <AppButton variant="danger" size="sm" :loading="clearing" @click="confirmClear">Ja, löschen</AppButton>
                                <AppButton variant="ghost" size="sm" @click="confirming = null">Abbrechen</AppButton>
                            </div>
                        </div>
                        <div v-else class="mt-4 overflow-hidden rounded-[12px] bg-canvas">
                            <button class="block w-full px-4 py-3 text-left text-[17px] text-danger active:bg-surface-2 sm:text-[15px]" @click="confirming = 'messages'">
                                Gesprächsverlauf löschen
                            </button>
                            <button class="block w-full border-t-[0.5px] border-line px-4 py-3 text-left text-[17px] text-danger active:bg-surface-2 sm:text-[15px]" @click="confirming = 'notes'">
                                Gemerktes löschen
                                <span class="mt-0.5 block text-[13px] text-ink-3">Was {{ coachName }} über dich weiß</span>
                            </button>
                        </div>
                        <button class="mt-2 mb-1 h-12 w-full rounded-[12px] bg-canvas text-[17px] font-semibold text-accent active:bg-surface-2 sm:text-[15px]" @click="sheet = null; confirming = null">Fertig</button>
                    </section>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
/* Grund des Chats: weiß / schwarz wie Nachrichten. Die Spitzen der Blasen
 * schneiden mit dieser Farbe aus, darum eine Variable. */
.chat { --chat-bg: 255 255 255; background: rgb(var(--chat-bg)); }
.dark .chat { --chat-bg: 0 0 0; }
.chat-bg { background: rgb(var(--chat-bg)); }

.pb-safe-chat { padding-bottom: max(0.75rem, env(safe-area-inset-bottom)); }

.me   { background: rgb(var(--z-accent)); color: #fff; }
.them { background: rgb(var(--z-surface-2)); color: rgb(var(--z-ink)); }
.err  { background: rgb(var(--z-danger-soft)); color: rgb(var(--z-danger-ink)); }

.bubble :deep(a) { text-decoration: underline; }

/* Spitze wie in Nachrichten: eine Rundung in Blasenfarbe, darüber eine
 * zweite in Grundfarbe, die sie aushöhlt. */
.tail::before, .tail::after { content: ""; position: absolute; bottom: 0; height: 20px; }
.me.tail::before   { right: -7px; width: 20px; background: rgb(var(--z-accent)); border-bottom-left-radius: 16px 14px; }
.me.tail::after    { right: -26px; width: 26px; background: rgb(var(--chat-bg)); border-bottom-left-radius: 10px; }
.them.tail::before { left: -7px; width: 20px; background: rgb(var(--z-surface-2)); border-bottom-right-radius: 16px 14px; }
.them.tail::after  { left: -26px; width: 26px; background: rgb(var(--chat-bg)); border-bottom-right-radius: 10px; }
.err.tail::before  { left: -7px; width: 20px; background: rgb(var(--z-danger-soft)); border-bottom-right-radius: 16px 14px; }
.err.tail::after   { left: -26px; width: 26px; background: rgb(var(--chat-bg)); border-bottom-right-radius: 10px; }

.typing-dot {
    width: 8px; height: 8px; border-radius: 9999px;
    background: rgb(var(--z-ink-3));
    animation: typing 1.2s infinite ease-in-out;
}
@keyframes typing {
    0%, 60%, 100% { opacity: .35; transform: translateY(0); }
    30%           { opacity: 1;   transform: translateY(-2px); }
}
@media (prefers-reduced-motion: reduce) {
    .typing-dot { animation-name: typing-fade; }
    @keyframes typing-fade { 0%, 60%, 100% { opacity: .35; } 30% { opacity: 1; } }
}
</style>
