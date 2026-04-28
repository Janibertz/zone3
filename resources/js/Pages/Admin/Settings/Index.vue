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

const sendingPush = ref(false);

function sendTestPush() {
    sendingPush.value = true;
    router.post(route('admin.settings.test-push'), {}, {
        preserveScroll: true,
        onFinish: () => { sendingPush.value = false; },
    });
}

const modelLabels = {
    'gpt-4o':      { label: 'GPT-4o',      sub: 'Leistungsstärkstes Modell — höhere Kosten' },
    'gpt-4o-mini': { label: 'GPT-4o mini', sub: 'Schnell & günstig — leicht reduzierte Qualität' },
};
</script>

<template>
    <Head title="Admin – Einstellungen" />

    <AdminLayout>
        <template #header>
            <h1 class="text-xl font-bold text-gray-900 dark:text-white">Einstellungen</h1>
        </template>

        <div class="p-6 space-y-6 max-w-3xl">

            <!-- Flash -->
            <div v-if="flash.success" class="flex items-center gap-3 px-4 py-3 bg-green-50 dark:bg-green-500/10 border border-green-200 dark:border-green-500/20 rounded-xl text-sm text-green-700 dark:text-green-300">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="flex items-center gap-3 px-4 py-3 bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/20 rounded-xl text-sm text-red-700 dark:text-red-300">
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                {{ flash.error }}
            </div>

            <!-- ── KI-Konfiguration ──────────────────────────────── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl divide-y divide-gray-100 dark:divide-slate-800">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">KI-Konfiguration</h2>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">Konfiguration via Umgebungsvariablen (OPENAI_API_KEY, OPENAI_MODEL)</p>
                </div>

                <!-- Modell -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200">Aktives Modell</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                            {{ modelLabels[config.openai_model]?.sub ?? config.openai_model }}
                        </p>
                    </div>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-violet-100 dark:bg-violet-500/20 text-violet-700 dark:text-violet-300">
                        {{ modelLabels[config.openai_model]?.label ?? config.openai_model }}
                    </span>
                </div>

                <!-- API Key -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200">API-Key</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">OPENAI_API_KEY</p>
                    </div>
                    <span v-if="config.openai_key_set"
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500 dark:bg-green-400"></span>
                        Konfiguriert
                    </span>
                    <span v-else
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500 dark:bg-red-400"></span>
                        Fehlt
                    </span>
                </div>

                <!-- API Status -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200">API-Status</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                            Verbindungstest beim Seitenaufruf
                            <span v-if="openaiStatus.ms" class="ml-1 text-gray-500 dark:text-slate-400">({{ openaiStatus.ms }} ms)</span>
                        </p>
                    </div>
                    <div v-if="openaiStatus.ok"
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500 dark:bg-green-400 animate-pulse"></span>
                        Erreichbar
                    </div>
                    <div v-else
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500 dark:bg-red-400"></span>
                        Nicht erreichbar
                    </div>
                </div>

                <!-- Error detail -->
                <div v-if="!openaiStatus.ok && openaiStatus.error" class="px-6 py-3">
                    <p class="text-xs text-red-500 dark:text-red-400 font-mono break-all">{{ openaiStatus.error }}</p>
                </div>
            </div>

            <!-- ── Push-Benachrichtigungen ───────────────────────── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl divide-y divide-gray-100 dark:divide-slate-800">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">Push-Benachrichtigungen</h2>
                    <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">VAPID-Keys via VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY</p>
                </div>

                <!-- VAPID Key -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200">VAPID-Key</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">Für Web-Push-Verschlüsselung</p>
                    </div>
                    <span v-if="config.push_key_set"
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-500/20 text-green-700 dark:text-green-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-green-500 dark:bg-green-400"></span>
                        Konfiguriert
                    </span>
                    <span v-else
                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-500/20 text-red-700 dark:text-red-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-red-500 dark:bg-red-400"></span>
                        Fehlt
                    </span>
                </div>

                <!-- Test Push -->
                <div class="px-6 py-4 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-800 dark:text-slate-200">Test-Push senden</p>
                        <p class="text-xs text-gray-400 dark:text-slate-500 mt-0.5">
                            Sendet eine Test-Benachrichtigung an deinen Account
                        </p>
                    </div>
                    <button
                        @click="sendTestPush"
                        :disabled="sendingPush || !config.push_key_set"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition-colors"
                        :class="(sendingPush || !config.push_key_set)
                            ? 'bg-gray-300 dark:bg-slate-700 cursor-not-allowed text-gray-500 dark:text-slate-500'
                            : 'bg-red-600 hover:bg-red-700'"
                    >
                        {{ sendingPush ? 'Wird gesendet…' : 'Test senden' }}
                    </button>
                </div>
            </div>

            <!-- ── System-Info ───────────────────────────────────── -->
            <div class="bg-white dark:bg-slate-900 border border-gray-100 dark:border-slate-800 rounded-2xl divide-y divide-gray-100 dark:divide-slate-800">
                <div class="px-6 py-4">
                    <h2 class="text-sm font-semibold text-gray-700 dark:text-slate-300">System-Info</h2>
                </div>

                <div v-for="row in [
                    { label: 'PHP-Version',      value: config.php_version },
                    { label: 'Laravel-Version',  value: config.laravel_version },
                    { label: 'Umgebung',         value: config.app_env },
                    { label: 'App-URL',          value: config.app_url },
                ]" :key="row.label" class="px-6 py-3 flex items-center justify-between">
                    <p class="text-sm text-gray-500 dark:text-slate-400">{{ row.label }}</p>
                    <p class="text-sm font-mono font-medium text-gray-800 dark:text-slate-200">{{ row.value }}</p>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
