<script setup>
/**
 * „Lauf teilen“ auf der Aktivitätsseite (Jan, 10.11.2026, Mockup
 * „Zone3 Kacheln und Lauf teilen“): Vorschau groß, darunter die Vorlagen
 * und „Eigenes“ für ein Foto aus der Mediathek. Gezeichnet wird im Browser
 * (`useRunStory`); das eigene Foto verlässt das Gerät nicht.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';
import { DEFAULT_PHOTO, TEMPLATES, drawRunStory } from '@/Composables/useRunStory';
import { shareStory } from '@/Composables/useStoryImage';

const props = defineProps({
    show: { type: Boolean, default: false },
    data: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const template = ref('photo');
const ownPhoto = ref(null);     // Object-URL des eigenen Fotos
const preview  = ref(null);     // Object-URL der Vorschau
const blob     = ref(null);
const busy     = ref(false);
const note     = ref(null);
const fileInput = ref(null);

const available = computed(() => TEMPLATES.filter(t => t.key !== 'route' || (props.data.coords?.length ?? 0) > 1));

let run = 0;
async function render() {
    if (!props.show) return;
    const mine = ++run;
    busy.value = true;
    note.value = null;
    try {
        const b = await drawRunStory(template.value, props.data, ownPhoto.value);
        if (mine !== run) return;
        if (preview.value) URL.revokeObjectURL(preview.value);
        blob.value = b;
        preview.value = URL.createObjectURL(b);
    } catch {
        if (mine === run) note.value = 'Das Bild konnte nicht erstellt werden.';
    } finally {
        if (mine === run) busy.value = false;
    }
}

watch(() => [props.show, template.value, ownPhoto.value], render, { immediate: true });

function pick(key) {
    template.value = key;
}

function choosePhoto() {
    fileInput.value?.click();
}

function onPhoto(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (ownPhoto.value) URL.revokeObjectURL(ownPhoto.value);
    ownPhoto.value = URL.createObjectURL(file);
    template.value = 'photo';
}

function useTemplatePhoto() {
    if (ownPhoto.value) URL.revokeObjectURL(ownPhoto.value);
    ownPhoto.value = null;
    template.value = 'photo';
}

async function share() {
    if (!blob.value) return;
    const name = template.value === 'sticker' ? 'zone3-sticker.png' : 'zone3-lauf.png';
    const how = await shareStory(blob.value, name);
    if (how === 'downloaded') note.value = 'Bild gespeichert. Du findest es in deinen Downloads.';
}

onBeforeUnmount(() => {
    if (preview.value) URL.revokeObjectURL(preview.value);
    if (ownPhoto.value) URL.revokeObjectURL(ownPhoto.value);
});

const isSticker = computed(() => template.value === 'sticker');
</script>

<template>
    <AppSheet :show="show" tall title="Lauf teilen" @close="emit('close')">
        <div class="flex flex-col items-center">
            <!-- Vorschau: 9:16, beim Sticker das Schachbrett für „durchsichtig“ -->
            <div class="relative w-[190px] overflow-hidden rounded-[14px]"
                :class="isSticker ? 'aspect-[27/16] w-[260px] bg-[repeating-conic-gradient(#3a3a3c_0_25%,#2c2c2e_0_50%)] bg-[length:14px_14px]' : 'aspect-[9/16] bg-surface-2'">
                <img v-if="preview" :src="preview" alt="Vorschau des Story-Bilds" class="h-full w-full object-cover" />
                <div v-if="busy" class="absolute inset-0 flex items-center justify-center bg-black/20">
                    <svg class="h-6 w-6 animate-spin text-white" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    </svg>
                </div>
            </div>
            <p v-if="isSticker" class="mt-3 max-w-xs text-center text-[13px] leading-snug text-ink-3">
                Durchsichtig zum Aufkleben: im Teilen-Menü „Kopieren“ wählen und in der Instagram-Story einfügen.
            </p>
        </div>

        <p class="mb-2 mt-5 text-[13px] font-semibold text-ink-3">Vorlage</p>
        <div class="no-scrollbar -mx-4 flex gap-2.5 overflow-x-auto px-4 pb-1" role="radiogroup" aria-label="Vorlage">
            <button v-for="t in available" :key="t.key" type="button" role="radio" :aria-checked="template === t.key"
                class="flex shrink-0 flex-col items-center gap-1.5 active:scale-95" @click="t.key === 'photo' && ownPhoto ? useTemplatePhoto() : pick(t.key)">
                <span class="flex h-[92px] w-[62px] items-center justify-center overflow-hidden rounded-[10px]"
                    :class="[template === t.key && !(t.key === 'photo' && ownPhoto) ? 'ring-[2.5px] ring-accent ring-offset-2 ring-offset-surface' : '',
                        { photo: 'bg-surface-2', route: 'bg-[#0B0E12]', numbers: 'bg-white', sticker: 'bg-[repeating-conic-gradient(#3a3a3c_0_25%,#2c2c2e_0_50%)] bg-[length:12px_12px]' }[t.key]]">
                    <img v-if="t.key === 'photo'" :src="DEFAULT_PHOTO" alt="" class="h-full w-full object-cover" />
                    <svg v-else-if="t.key === 'route'" class="h-10 w-10" viewBox="0 0 40 40" aria-hidden="true"><path d="M6 28 C10 16 18 14 22 9 S34 8 33 18 S24 30 14 31Z" fill="none" stroke="#2997ff" stroke-width="2.4" /></svg>
                    <span v-else-if="t.key === 'numbers'" class="font-rounded text-[17px] font-extrabold text-black">{{ data.big.value }}</span>
                    <span v-else class="text-center text-[10px] font-bold leading-tight text-white">ohne<br>Grund</span>
                </span>
                <span class="text-[11px]" :class="template === t.key && !(t.key === 'photo' && ownPhoto) ? 'font-semibold text-ink' : 'text-ink-3'">{{ t.label }}</span>
            </button>

            <button type="button" class="flex shrink-0 flex-col items-center gap-1.5 active:scale-95" @click="choosePhoto"
                :aria-label="ownPhoto ? 'Anderes eigenes Foto wählen' : 'Eigenes Foto wählen'">
                <span class="flex h-[92px] w-[62px] items-center justify-center overflow-hidden rounded-[10px]"
                    :class="ownPhoto && template === 'photo' ? 'ring-[2.5px] ring-accent ring-offset-2 ring-offset-surface' : 'border-[1.5px] border-dashed border-line-strong'">
                    <img v-if="ownPhoto" :src="ownPhoto" alt="" class="h-full w-full object-cover" />
                    <svg v-else class="h-6 w-6 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3" /><circle cx="12" cy="12" r="3.2" /><path d="M8 5l1.5-2h5L16 5" /></svg>
                </span>
                <span class="text-[11px]" :class="ownPhoto && template === 'photo' ? 'font-semibold text-ink' : 'text-accent'">Eigenes</span>
            </button>
            <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onPhoto" />
        </div>

        <p v-if="template === 'route'" class="mt-3 text-[13px] leading-snug text-ink-3">Start und Ziel sind um 200 m gekürzt, damit niemand deine Haustür abliest.</p>
        <p v-if="note" class="mt-3 text-[13px] text-ink-2">{{ note }}</p>

        <AppButton class="mt-5 w-full" :disabled="!blob || busy" @click="share">Bild teilen</AppButton>
    </AppSheet>
</template>
