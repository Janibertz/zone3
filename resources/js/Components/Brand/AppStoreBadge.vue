<script setup>
import { APP_STORE_URL, APP_STORE_BADGE, APP_STORE_BADGE_ALT } from '@/appstore';
import { isNativeApp } from '@/Composables/useNativeApp';

// Nur im Browser und in der Homescreen-App, nie in den Apps.
const show = !isNativeApp();

// Höhe in px; Apple verlangt mindestens 40 px auf dem Bildschirm.
defineProps({ height: { type: Number, default: 48 } });
</script>

<template>
    <a v-if="show" :href="APP_STORE_URL" target="_blank" rel="noopener noreferrer"
        class="inline-block shrink-0 transition active:scale-[0.98]">
        <img :src="APP_STORE_BADGE" :alt="APP_STORE_BADGE_ALT" :style="{ height: height + 'px' }" class="block w-auto" />
    </a>
</template>
