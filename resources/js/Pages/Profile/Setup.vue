<template>
    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-ink leading-tight">🏃‍♂️ Athletenprofil</h2>
        </template>

        <div class="py-4 lg:py-6">
            <div class="px-4 lg:px-6">
                <div class="bg-surface overflow-hidden shadow-card sm:rounded-lg">
                    <div class="p-6 bg-surface border-b border-line">
                        <!-- Success Message -->
                        <div v-if="profile?.has_completed_setup" class="mb-6 p-4 bg-success-soft border border-success/25 rounded-lg">
                            <p class="text-success-ink">✅ Profil erfolgreich eingerichtet!</p>
                        </div>

                        <!-- Error Messages -->
                        <div v-if="Object.keys(errors).length > 0" class="mb-6 p-4 bg-danger-soft border border-danger/25 rounded-lg">
                            <p class="text-danger-ink font-semibold mb-2">Fehler beim Speichern:</p>
                            <ul class="text-danger text-sm space-y-1">
                                <li v-for="(message, field) in errors" :key="field">
                                    <strong>{{ field }}:</strong> {{ Array.isArray(message) ? message[0] : message }}
                                </li>
                            </ul>
                        </div>

                        <!-- Loading Message -->
                        <div v-if="isLoading" class="mb-6 p-4 bg-info-soft border border-info/25 rounded-lg">
                            <p class="text-info-ink">⏳ Wird gespeichert...</p>
                        </div>

                        <form @submit.prevent="submitForm" class="space-y-6">
                            <!-- Threshold Heart Rate -->
                            <div>
                                <label for="threshold_hr" class="block font-medium text-sm text-ink-2 mb-2">Schwellen-Herzfrequenz (bpm)</label>
                                <input
                                    v-model="formData.threshold_heart_rate"
                                    type="text"
                                    id="threshold_hr"
                                    class="block w-full px-4 py-2 border border-line-strong rounded-lg focus:outline-none focus:ring-2 focus:ring-info/40"
                                    placeholder="z.B. 165"
                                    required
                                />
                                <p class="text-sm text-ink-3 mt-1">Lactate Threshold Heart Rate (LTHR)</p>
                            </div>

                            <!-- Max Heart Rate -->
                            <div>
                                <label for="max_hr" class="block font-medium text-sm text-ink-2 mb-2">Maximale Herzfrequenz (bpm)</label>
                                <input
                                    v-model="formData.max_heart_rate"
                                    type="text"
                                    id="max_hr"
                                    class="block w-full px-4 py-2 border border-line-strong rounded-lg focus:outline-none focus:ring-2 focus:ring-info/40"
                                    placeholder="z.B. 195"
                                    required
                                />
                                <p class="text-sm text-ink-3 mt-1">Maximale Herzfrequenz beim Training</p>
                            </div>

                            <!-- Threshold Speed (Pace) -->
                            <div>
                                <label for="threshold_speed" class="block font-medium text-sm text-ink-2 mb-2">Schwellen-Pace (min:sek/km)</label>
                                <input
                                    v-model="formData.threshold_speed"
                                    type="text"
                                    id="threshold_speed"
                                    class="block w-full px-4 py-2 border border-line-strong rounded-lg focus:outline-none focus:ring-2 focus:ring-info/40"
                                    placeholder="z.B. 5:30"
                                    pattern="[0-9]{1,2}:[0-9]{2}"
                                    required
                                />
                                <p class="text-sm text-ink-3 mt-1">Deine Schwellenpace (z.B. 5:30 min/km)</p>
                            </div>

                            <!-- Submit Button -->
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    :disabled="isLoading"
                                    class="bg-info text-canvas px-6 py-2 rounded-lg hover:opacity-90 disabled:bg-ink-3"
                                >
                                    {{ isLoading ? 'Speichert...' : 'Profil speichern' }}
                                </button>
                            </div>
                        </form>

                        <!-- Display Calculating Status -->
                        <div v-if="isCalculating" class="mt-10 p-4 bg-info-soft border border-info/25 rounded-lg">
                            <p class="text-info-ink">⏳ Zonen werden neu berechnet...</p>
                        </div>

                        <!-- Display Zones Preview if Data Valid -->
                        <div v-if="previewZones && previewZones.pace_zones" class="mt-10 border-t pt-10">
                            <h3 class="text-lg font-semibold text-ink mb-6">📊 Deine Laufzonen (Vorschau)</h3>
                            
                            <!-- Pace Zones -->
                            <div class="space-y-2">
                                <div v-for="(zone, idx) in previewZones.pace_zones" :key="idx" class="p-3 bg-gradient-to-r from-info to-info rounded border border-info/25">
                                    <p class="font-medium text-ink">Zone {{ idx + 1 }}: {{ zone.name }}</p>
                                    <p class="text-sm text-ink-2">{{ zone.min_pace }} - {{ zone.max_pace }} min/km</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive, watch } from 'vue';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    profile: Object,
});

const isLoading = ref(false);
const isCalculating = ref(false);
const errors = reactive({});
const previewZones = ref(null);

function formatPaceDisplay(minutes) {
    if (!minutes) return '';
    const mins = Math.floor(minutes);
    const secs = Math.round((minutes - mins) * 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// Initialize form data
const formData = reactive({
    threshold_heart_rate: props.profile?.threshold_heart_rate ? String(props.profile.threshold_heart_rate) : '',
    max_heart_rate: props.profile?.max_heart_rate ? String(props.profile.max_heart_rate) : '',
    threshold_speed: props.profile?.threshold_speed ? formatPaceDisplay(props.profile.threshold_speed) : '',
});

// Initial preview zone state from profile data
previewZones.value = props.profile?.pace_zones ? { pace_zones: props.profile.pace_zones } : null;

// No live recalculation by typing. Zonen werden nur beim Submit neu berechnet und neu geladen.

const submitForm = async () => {
    console.log('=== FORM SUBMISSION ===');
    console.log('Form data:', {
        threshold_heart_rate: formData.threshold_heart_rate,
        max_heart_rate: formData.max_heart_rate,
        threshold_speed: formData.threshold_speed,
    });

    // Clear previous errors
    Object.keys(errors).forEach(key => delete errors[key]);

    isLoading.value = true;

    try {
        console.log('[BEFORE] Sending POST request...');
        
        const response = await axios.post(route('runner.profile.store'), {
            threshold_heart_rate: formData.threshold_heart_rate,
            max_heart_rate: formData.max_heart_rate,
            threshold_speed: formData.threshold_speed,
        });

        console.log('[SUCCESS] Response:', response.data);
        
        // Wait a bit and reload
        setTimeout(() => {
            console.log('[FINISH] Reloading page...');
            window.location.reload();
        }, 1000);
    } catch (error) {
        console.error('[ERROR] Submission failed:', error.response);
        
        isLoading.value = false;

        // Handle validation errors
        if (error.response?.status === 422) {
            const responseErrors = error.response.data.errors || {};
            Object.assign(errors, responseErrors);
            console.log('[ERRORS] Validation errors:', responseErrors);
        } else {
            errors.general = error.response?.data?.message || 'Unbekannter Fehler beim Speichern';
            console.error('[ERROR] Server error:', errors.general);
        }
    }
};
</script>
