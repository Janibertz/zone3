<script setup>
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';

/**
 * "Zu Garmin Connect senden" — ein Sheet für Dashboard und Planseite.
 *
 * Gesendet wird über die Training API der offiziellen Verbindung. Der alte
 * Login mit E-Mail und Passwort ist mit Schritt 6 der Garmin-Integration
 * weggefallen; ohne Verbindung verweist das Sheet ins Profil.
 *
 * Die Seite stellt die Anfrage; das Sheet zeigt nur den Zustand.
 */
defineProps({
    show:      { type: Boolean, default: false },
    /** Offizielle Verbindung mit Workout-Freigabe. */
    connected: { type: Boolean, default: false },
    sending:   { type: Boolean, default: false },
    error:     { type: String,  default: null },
    success:   { type: Boolean, default: false },
    subtitle:  { type: String,  default: 'Das Workout erscheint in deinem Garmin-Kalender' },
    successText:{ type: String, default: 'Es steht jetzt im Garmin-Kalender und kommt mit der nächsten Synchronisierung auf die Uhr.' },
});

const emit = defineEmits(['close', 'send']);
</script>

<template>
    <AppSheet :show="show" title="Zu Garmin Connect senden" :subtitle="subtitle" @close="emit('close')">
        <!-- Success -->
        <div v-if="success" class="rounded-card bg-success-soft p-5 text-center">
            <svg class="mx-auto mb-2 h-8 w-8 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            <p class="text-sm font-semibold text-success-ink">Workout erfolgreich übertragen!</p>
            <p class="mt-1 text-xs text-success-ink/80">{{ successText }}</p>
        </div>

        <template v-else>
            <div v-if="error" class="mb-4 rounded-card bg-danger-soft p-3">
                <p class="text-sm text-danger-ink">{{ error }}</p>
            </div>

            <div v-if="connected" class="flex items-center gap-3 rounded-card bg-success-soft px-4 py-3">
                <svg class="h-4 w-4 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-success-ink">Mit Garmin Connect verbunden</p>
                    <p class="text-xs text-success-ink/80">Kommende Läufe legt Zone3 von selbst auf die Uhr.</p>
                </div>
            </div>

            <p v-else class="text-[15px] leading-relaxed text-ink-2">
                Verbinde zuerst Garmin im Profil unter „Verbindungen“. Danach stehen deine Läufe von selbst im Garmin-Kalender.
            </p>
        </template>

        <template v-if="!success" #footer>
            <div class="flex gap-3">
                <AppButton variant="secondary" block @click="emit('close')">Abbrechen</AppButton>
                <AppButton v-if="connected" block :loading="sending" @click="emit('send')">
                    {{ sending ? 'Wird übertragen…' : 'Senden' }}
                </AppButton>
                <AppButton v-else block :href="route('profile.edit')">Zum Profil</AppButton>
            </div>
        </template>
    </AppSheet>
</template>
