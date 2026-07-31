<script setup>
import AppSheet from '@/Components/UI/AppSheet.vue';
import AppButton from '@/Components/UI/AppButton.vue';

/**
 * "Are you sure?" as a sheet. Covers the confirmation dialogs that were each
 * hand-built on top of Modal.vue.
 */
defineProps({
    show:         { type: Boolean, default: false },
    title:        { type: String,  default: 'Bist du sicher?' },
    message:      { type: String,  default: null },
    confirmLabel: { type: String,  default: 'Bestätigen' },
    cancelLabel:  { type: String,  default: 'Abbrechen' },
    /** danger | primary — styles the confirm button. */
    tone:         { type: String,  default: 'danger' },
    loading:      { type: Boolean, default: false },
});

defineEmits(['confirm', 'close']);
</script>

<template>
    <AppSheet :show="show" :title="title" :closeable="!loading" @close="$emit('close')">
        <p v-if="message" class="text-sm leading-relaxed text-ink-2">{{ message }}</p>
        <slot />

        <template #footer>
            <div class="flex gap-3">
                <AppButton variant="secondary" block :disabled="loading" @click="$emit('close')">
                    {{ cancelLabel }}
                </AppButton>
                <AppButton :variant="tone" block :loading="loading" @click="$emit('confirm')">
                    {{ confirmLabel }}
                </AppButton>
            </div>
        </template>
    </AppSheet>
</template>
