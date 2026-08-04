<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    config:       Object,
    openaiStatus: Object,
});

const page  = usePage();
const flash = computed(() => page.props.flash ?? {});

const sendingPush      = ref(false);
const togglingMaintenance = ref(false);
const broadcastForm    = ref({ title: '', message: '', url: '' });
const broadcasting     = ref(false);

function sendTestPush() {
    sendingPush.value = true;
    router.post(route('admin.settings.test-push'), {}, {
        preserveScroll: true,
        onFinish: () => { sendingPush.value = false; },
    });
}

function toggleMaintenance() {
    togglingMaintenance.value = true;
    router.post(route('admin.settings.maintenance'), {}, {
        preserveScroll: true,
        onFinish: () => { togglingMaintenance.value = false; },
    });
}

function broadcastPush() {
    broadcasting.value = true;
    router.post(route('admin.settings.broadcast-push'), broadcastForm.value, {
        preserveScroll: true,
        onSuccess: () => { broadcastForm.value = { title: '', message: '', url: '' }; },
        onFinish: () => { broadcasting.value = false; },
    });
}

const modelLabels = {
    'gpt-5.5-2026-04-23': { label: 'GPT-5.5',      sub: 'Event-Plan, Schwellenpace, Coach-Chat' },
    'gpt-5.4-mini':        { label: 'GPT-5.4 mini', sub: 'Profil-Schätzung, Empfehlung, Review, Messages, Ernährung u.a.' },
    'gpt-4o':              { label: 'GPT-4o',        sub: 'Leistungsstärkstes GPT-4 Modell' },
    'gpt-4o-mini':         { label: 'GPT-4o mini',   sub: 'Schnell & günstig' },
};
</script>

<template>
    <Head title="Admin – Einstellungen" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-ink">Einstellungen</h1>
        </template>

        <div class="p-4 sm:p-6 space-y-6 max-w-3xl">

            <!-- Flash -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-success-soft border border-success/25 rounded-field text-sm text-success-ink">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="flex items-center gap-3 px-4 py-3 bg-danger-soft border border-danger/25 rounded-field text-sm text-danger-ink">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                {{ flash.error }}
            </div>

            <!-- ── KI-Konfiguration ──────────────────────────────── -->
            <div class="bg-surface rounded-card divide-y divide-line">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-ink-2">KI-Konfiguration</h2>
                    <p class="text-xs text-ink-3 mt-0.5">Konfiguration via OPENAI_API_KEY, OPENAI_MODEL, OPENAI_MODEL_MINI</p>
                </div>

                <!-- Hauptmodell -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-ink">Hauptmodell</p>
                        <p class="text-xs text-ink-3 mt-0.5">
                            {{ modelLabels[config.openai_model]?.sub ?? 'OPENAI_MODEL' }}
                        </p>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-accent-soft text-accent-ink">
                        {{ modelLabels[config.openai_model]?.label ?? config.openai_model }}
                    </span>
                </div>

                <!-- Mini-Modell -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-ink">Mini-Modell</p>
                        <p class="text-xs text-ink-3 mt-0.5">
                            {{ modelLabels[config.openai_model_mini]?.sub ?? 'OPENAI_MODEL_MINI' }}
                        </p>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-info-soft text-info-ink">
                        {{ modelLabels[config.openai_model_mini]?.label ?? config.openai_model_mini }}
                    </span>
                </div>

                <!-- API Key -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-ink">API-Key</p>
                        <p class="text-xs text-ink-3 mt-0.5">OPENAI_API_KEY</p>
                    </div>
                    <span v-if="config.openai_key_set"
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-success-soft text-success-ink">
                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                        Konfiguriert
                    </span>
                    <span v-else
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-danger-soft text-danger-ink">
                        <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                        Fehlt
                    </span>
                </div>

                <!-- API Status -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-ink">API-Status</p>
                        <p class="text-xs text-ink-3 mt-0.5">
                            Verbindungstest beim Seitenaufruf
                            <span v-if="openaiStatus.ms" class="ml-1 text-ink-3">({{ openaiStatus.ms }} ms)</span>
                        </p>
                    </div>
                    <div v-if="openaiStatus.ok"
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-success-soft text-success-ink">
                        <span class="h-1.5 w-1.5 rounded-full bg-success animate-pulse"></span>
                        Erreichbar
                    </div>
                    <div v-else
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-danger-soft text-danger-ink">
                        <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                        Nicht erreichbar
                    </div>
                </div>

                <!-- Error detail -->
                <div v-if="!openaiStatus.ok && openaiStatus.error" class="px-6 py-3">
                    <p class="text-xs text-danger font-mono break-all">{{ openaiStatus.error }}</p>
                </div>
            </div>

            <!-- ── E-Mail ───────────────────────────────────────── -->
            <div class="bg-surface rounded-card divide-y divide-line">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-ink-2">E-Mail</h2>
                    <p class="text-xs text-ink-3 mt-0.5">Konfiguration via MAIL_MAILER, MAIL_HOST, MAIL_FROM_ADDRESS</p>
                </div>

                <div v-for="row in [
                    { label: 'Mailer',    value: config.mail_mailer },
                    { label: 'SMTP-Host', value: config.mail_host },
                    { label: 'Absender',  value: config.mail_from },
                ]" :key="row.label" class="px-6 py-3 flex items-center justify-between">
                    <p class="text-sm text-ink-3">{{ row.label }}</p>
                    <span class="text-sm font-mono font-medium"
                        :class="row.label === 'Mailer' && row.value === 'log'
                            ? 'text-warn-ink'
                            : 'text-ink'">
                        {{ row.value }}
                        <span v-if="row.label === 'Mailer' && row.value === 'log'" class="text-xs font-sans font-normal text-warn"> ⚠ Mails werden nur geloggt</span>
                    </span>
                </div>
            </div>

            <!-- ── Push-Benachrichtigungen ───────────────────────── -->
            <div class="bg-surface rounded-card divide-y divide-line">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-ink-2">Push-Benachrichtigungen</h2>
                    <p class="text-xs text-ink-3 mt-0.5">VAPID-Keys via VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY</p>
                </div>

                <!-- VAPID Key -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-ink">VAPID-Key</p>
                        <p class="text-xs text-ink-3 mt-0.5">Für Web-Push-Verschlüsselung</p>
                    </div>
                    <span v-if="config.push_key_set"
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-success-soft text-success-ink">
                        <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                        Konfiguriert
                    </span>
                    <span v-else
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-danger-soft text-danger-ink">
                        <span class="h-1.5 w-1.5 rounded-full bg-danger"></span>
                        Fehlt
                    </span>
                </div>

                <!-- Test Push -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-ink">Test-Push senden</p>
                        <p class="text-xs text-ink-3 mt-0.5">
                            Sendet eine Test-Benachrichtigung an deinen Account
                        </p>
                    </div>
                    <button
                        @click="sendTestPush"
                        :disabled="sendingPush || !config.push_key_set"
                        class="px-4 py-2 rounded-field text-sm font-semibold text-white transition-colors"
                        :class="(sendingPush || !config.push_key_set)
                            ? 'bg-surface-3 cursor-not-allowed text-ink-3'
                            : 'bg-danger hover:opacity-90'"
                    >
                        {{ sendingPush ? 'Wird gesendet…' : 'Test senden' }}
                    </button>
                </div>
            </div>

            <!-- ── Broadcast Push ──────────────────────────────── -->
            <div class="bg-surface rounded-card divide-y divide-line">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-ink-2">Push-Broadcast</h2>
                    <p class="text-xs text-ink-3 mt-0.5">Nachricht an alle Nutzer mit aktivierten Push-Benachrichtigungen senden</p>
                </div>
                <div class="px-6 py-4 space-y-3">
                    <input v-model="broadcastForm.title" type="text" placeholder="Titel…"
                        class="w-full rounded-field bg-surface px-3 py-2 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40" />
                    <input v-model="broadcastForm.message" type="text" placeholder="Nachricht…"
                        class="w-full rounded-field bg-surface px-3 py-2 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40" />
                    <input v-model="broadcastForm.url" type="text" placeholder="URL (optional, z.B. /dashboard)"
                        class="w-full rounded-field bg-surface px-3 py-2 text-sm text-ink placeholder-ink-3 focus:outline-none focus:ring-2 focus:ring-accent/40" />
                    <button @click="broadcastPush" :disabled="broadcasting || !broadcastForm.title || !broadcastForm.message || !config.push_key_set"
                        class="px-4 py-2 rounded-field text-sm font-semibold text-white transition-colors"
                        :class="(broadcasting || !broadcastForm.title || !broadcastForm.message || !config.push_key_set)
                            ? 'bg-surface-3 cursor-not-allowed text-ink-3'
                            : 'bg-accent hover:opacity-90'">
                        {{ broadcasting ? 'Wird gesendet…' : 'An alle senden' }}
                    </button>
                </div>
            </div>

            <!-- ── Wartungsmodus ────────────────────────────────── -->
            <div class="bg-surface rounded-card divide-y divide-line">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-ink-2">Wartungsmodus</h2>
                    <p class="text-xs text-ink-3 mt-0.5">Normale Nutzer sehen eine Wartungsseite. Admins haben weiterhin Zugriff.</p>
                </div>
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-ink">Status</p>
                        <p class="text-xs mt-0.5" :class="config.maintenance_mode ? 'text-warn' : 'text-ink-3'">
                            {{ config.maintenance_mode ? '⚠ Wartungsmodus aktiv' : 'App läuft normal' }}
                        </p>
                    </div>
                    <button @click="toggleMaintenance" :disabled="togglingMaintenance"
                        class="px-4 py-2 rounded-field text-sm font-semibold text-white transition-colors disabled:opacity-50"
                        :class="config.maintenance_mode ? 'bg-success hover:opacity-90' : 'bg-warn hover:opacity-90'">
                        {{ togglingMaintenance ? '…' : config.maintenance_mode ? 'Deaktivieren' : 'Aktivieren' }}
                    </button>
                </div>
            </div>

            <!-- ── System-Info ───────────────────────────────────── -->
            <div class="bg-surface rounded-card divide-y divide-line">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-ink-2">System-Info</h2>
                </div>

                <div v-for="row in [
                    { label: 'PHP-Version',      value: config.php_version },
                    { label: 'Laravel-Version',  value: config.laravel_version },
                    { label: 'Umgebung',         value: config.app_env },
                    { label: 'App-URL',          value: config.app_url },
                ]" :key="row.label" class="px-6 py-3 flex items-center justify-between">
                    <p class="text-sm text-ink-3">{{ row.label }}</p>
                    <p class="text-sm font-mono font-medium text-ink">{{ row.value }}</p>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
