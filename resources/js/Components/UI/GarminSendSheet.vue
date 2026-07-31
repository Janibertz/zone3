<script setup>
import { ref, watch } from 'vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';

/**
 * "Send to Garmin Connect" — one sheet for Dashboard, Plan and Workouts,
 * which each carried their own near-identical copy of this dialog.
 *
 * The page owns the request; this component only collects credentials and
 * renders state.
 */
const props = defineProps({
    show:      { type: Boolean, default: false },
    /** A stored session exists — no credentials needed. */
    connected: { type: Boolean, default: false },
    savedEmail:{ type: String,  default: null },
    sending:   { type: Boolean, default: false },
    error:     { type: String,  default: null },
    success:   { type: Boolean, default: false },
    subtitle:  { type: String,  default: 'Das Workout erscheint in deiner Garmin Connect Bibliothek' },
    successText:{ type: String, default: 'Es erscheint jetzt in Garmin Connect und ist im Kalender eingetragen.' },
    /** Offer a target date (Workouts page). */
    withDate:  { type: Boolean, default: false },
    /** Offer "Trennen" next to the connected badge (Plan page). */
    withDisconnect: { type: Boolean, default: false },
    date:      { type: String,  default: null },
});

const emit = defineEmits(['close', 'send', 'disconnect', 'update:date']);

const email    = ref('');
const password = ref('');

// A stale session forces the login fields back in.
const sessionExpired = 'Sitzung abgelaufen. Bitte erneut einloggen.';

watch(() => props.show, (open) => {
    if (!open) password.value = '';
    else if (props.savedEmail) email.value = props.savedEmail;
});

function submit() {
    emit('send', { email: email.value, password: password.value, date: props.date });
}
</script>

<template>
    <AppSheet :show="show" title="Zu Garmin Connect senden" :subtitle="subtitle" @close="$emit('close')">
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

            <!-- Target date -->
            <div v-if="withDate" class="mb-4">
                <label class="z-label">Datum</label>
                <input
                    :value="date"
                    type="date"
                    class="z-input"
                    @input="$emit('update:date', $event.target.value)"
                />
            </div>

            <!-- Connected badge -->
            <div v-if="connected && error !== sessionExpired" class="mb-4 flex items-center gap-3 rounded-card bg-success-soft px-4 py-3">
                <svg class="h-4 w-4 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                </svg>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-success-ink">Verbunden als</p>
                    <p class="truncate text-xs text-success-ink/80">{{ savedEmail }}</p>
                </div>
                <button
                    v-if="withDisconnect"
                    type="button"
                    class="shrink-0 text-xs text-success-ink hover:underline"
                    @click="$emit('disconnect')"
                >
                    Trennen
                </button>
            </div>

            <!-- Credentials -->
            <div v-if="!connected || error === sessionExpired" class="space-y-3">
                <div>
                    <label class="z-label">Garmin Connect E-Mail</label>
                    <input v-model="email" type="email" autocomplete="email" placeholder="deine@email.de" class="z-input" />
                </div>
                <div>
                    <label class="z-label">Passwort</label>
                    <input v-model="password" type="password" autocomplete="current-password" placeholder="••••••••" class="z-input" />
                </div>
                <p class="z-hint">
                    Dein Passwort wird nicht gespeichert — nur ein verschlüsselter Login-Token.
                    Beim nächsten Mal musst du dich nicht mehr einloggen.
                </p>
            </div>
        </template>

        <template v-if="!success" #footer>
            <div class="flex gap-3">
                <AppButton variant="secondary" block @click="$emit('close')">Abbrechen</AppButton>
                <AppButton
                    block
                    :loading="sending"
                    :disabled="!connected && (!email || !password)"
                    @click="submit"
                >
                    {{ sending ? 'Wird übertragen…' : 'Senden' }}
                </AppButton>
            </div>
        </template>
    </AppSheet>
</template>
