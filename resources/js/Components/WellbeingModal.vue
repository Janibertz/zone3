<template>
    <div class="fixed inset-0 bg-black bg-opacity-50 flex items-end sm:items-center justify-center z-50 p-0 sm:p-4" v-if="show">
        <div class="bg-white dark:bg-slate-900 rounded-t-3xl sm:rounded-2xl shadow-xl max-w-2xl w-full max-h-[92vh] overflow-y-auto">
            <!-- Header -->
            <div class="sticky top-0 bg-gradient-to-r from-purple-500 to-pink-500 text-white p-6 border-b">
                <div class="flex justify-between items-center">
                    <h3 class="text-2xl font-bold">💪 Tägliches Wellbeing</h3>
                    <button @click="$emit('close')" class="text-white hover:text-gray-200 text-2xl">&times;</button>
                </div>
                <p class="text-white text-opacity-90 mt-1">{{ formatDate(today) }}</p>
            </div>

            <!-- Wellbeing Form -->
            <div class="p-6 space-y-6">
                <!-- Energy Level -->
                <div>
                    <label class="block font-medium text-gray-700 mb-2">⚡ Energielevel</label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model.number="form.energy_level"
                            type="range"
                            min="1"
                            max="10"
                            class="flex-1 h-2 bg-yellow-300 rounded-lg appearance-none cursor-pointer"
                        />
                        <span class="text-xl font-bold text-yellow-600 bg-yellow-50 px-3 py-1 rounded">{{ form.energy_level }}</span>
                    </div>
                </div>

                <!-- Mood -->
                <div>
                    <label class="block font-medium text-gray-700 mb-2">😊 Stimmung</label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model.number="form.mood"
                            type="range"
                            min="1"
                            max="10"
                            class="flex-1 h-2 bg-blue-300 rounded-lg appearance-none cursor-pointer"
                        />
                        <span class="text-xl font-bold text-blue-600 bg-blue-50 px-3 py-1 rounded">{{ form.mood }}</span>
                    </div>
                </div>

                <!-- Sleep Quality -->
                <div>
                    <label class="block font-medium text-gray-700 mb-2">😴 Schlafqualität</label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model.number="form.sleep_quality"
                            type="range"
                            min="1"
                            max="10"
                            class="flex-1 h-2 bg-indigo-300 rounded-lg appearance-none cursor-pointer"
                        />
                        <span class="text-xl font-bold text-indigo-600 bg-indigo-50 px-3 py-1 rounded">{{ form.sleep_quality }}</span>
                    </div>
                </div>

                <!-- Muscle Soreness -->
                <div>
                    <label class="block font-medium text-gray-700 mb-2">🦵 Muskelkater</label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model.number="form.muscle_soreness"
                            type="range"
                            min="1"
                            max="10"
                            class="flex-1 h-2 bg-red-300 rounded-lg appearance-none cursor-pointer"
                        />
                        <span class="text-xl font-bold text-red-600 bg-red-50 px-3 py-1 rounded">{{ form.muscle_soreness }}</span>
                    </div>
                </div>

                <!-- Stress Level -->
                <div>
                    <label class="block font-medium text-gray-700 mb-2">😰 Stress-Level</label>
                    <div class="flex items-center gap-3">
                        <input
                            v-model.number="form.stress_level"
                            type="range"
                            min="1"
                            max="10"
                            class="flex-1 h-2 bg-orange-300 rounded-lg appearance-none cursor-pointer"
                        />
                        <span class="text-xl font-bold text-orange-600 bg-orange-50 px-3 py-1 rounded">{{ form.stress_level }}</span>
                    </div>
                </div>

                <!-- Injury/Sickness Flags -->
                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <p class="font-medium text-gray-700 mb-3">Status</p>
                    <div class="space-y-2">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input
                                v-model="form.is_sick"
                                type="checkbox"
                                class="w-4 h-4 rounded border-gray-300 text-red-500 focus:ring-red-500"
                            />
                            <span class="text-gray-700">🦠 Ich bin krank</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input
                                v-model="form.is_injured"
                                type="checkbox"
                                class="w-4 h-4 rounded border-gray-300 text-red-500 focus:ring-red-500"
                            />
                            <span class="text-gray-700">🤕 Ich bin verletzt</span>
                        </label>
                    </div>
                </div>

                <!-- Notes -->
                <div>
                    <label for="notes" class="block font-medium text-gray-700 mb-2">📝 Notizen (optional)</label>
                    <textarea
                        v-model="form.notes"
                        id="notes"
                        rows="3"
                        class="block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="z.B. Gutes Training heute, leichte Verletzung am linken Fuß..."
                    ></textarea>
                </div>

                <!-- Wellbeing Status -->
                <div v-if="wellbeingScore" class="p-4 bg-gradient-to-r from-purple-50 to-pink-50 rounded-lg border border-purple-200">
                    <p class="font-medium text-gray-700 mb-2">Dein Wellbeing-Status:</p>
                    <p class="text-2xl font-bold mb-2">
                        {{ wellbeingStatus }} 
                        <span class="text-lg">({{ wellbeingScore }}/10)</span>
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div class="bg-gray-50 p-6 border-t flex justify-end gap-3">
                <button
                    @click="$emit('close')"
                    class="px-6 py-2 border border-gray-300 rounded-lg hover:bg-gray-100 text-gray-700 font-medium"
                >
                    Abbrechen
                </button>
                <button
                    @click="submitWellbeing"
                    :disabled="submitting"
                    class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:bg-gray-400 font-medium"
                >
                    {{ submitting ? 'Speichert...' : 'Speichern & Schließen' }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    show: Boolean,
});

const emit = defineEmits(['close', 'saved']);

const today = new Date();

const form = ref({
    energy_level: 5,
    mood: 5,
    sleep_quality: 5,
    muscle_soreness: 5,
    stress_level: 5,
    notes: '',
    is_sick: false,
    is_injured: false,
});

const submitting = ref(false);

// Calculate wellbeing score
const wellbeingScore = computed(() => {
    const total = form.value.energy_level + form.value.mood + form.value.sleep_quality +
                  (10 - form.value.muscle_soreness) + (10 - form.value.stress_level);
    return (total / 5).toFixed(1);
});

// Get wellbeing status
const wellbeingStatus = computed(() => {
    const score = parseFloat(wellbeingScore.value);

    if (form.value.is_sick || form.value.is_injured) {
        return '🤕 Nicht trainieren';
    } else if (score >= 8) {
        return '🚀 Perfekt für hartes Training';
    } else if (score >= 6) {
        return '💪 Moderates Training möglich';
    } else if (score >= 4) {
        return '😴 Nur leichtes Training';
    } else {
        return '⚠️ Ruhetag empfohlen';
    }
});

const formatDate = (date) => {
    return date.toLocaleDateString('de-DE', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const submitWellbeing = async () => {
    submitting.value = true;
    try {
        await axios.post('/api/wellbeing', form.value);
        emit('saved');
        emit('close');
    } catch (error) {
        console.error('Fehler beim Speichern:', error);
        alert('Fehler beim Speichern des Wellbeing-Eintrags');
    } finally {
        submitting.value = false;
    }
};

// Load today's entry when modal opens
watch(() => props.show, async (newVal) => {
    if (newVal) {
        try {
            const response = await axios.get('/api/wellbeing/today');
            if (response.data) {
                form.value = {
                    energy_level: response.data.energy_level || 5,
                    mood: response.data.mood || 5,
                    sleep_quality: response.data.sleep_quality || 5,
                    muscle_soreness: response.data.muscle_soreness || 5,
                    stress_level: response.data.stress_level || 5,
                    notes: response.data.notes || '',
                    is_sick: response.data.is_sick || false,
                    is_injured: response.data.is_injured || false,
                };
            }
        } catch (error) {
            console.error('Fehler beim Laden:', error);
        }
    }
});
</script>
