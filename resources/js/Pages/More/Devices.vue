<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/UI/PageHeader.vue';
import DeviceTiles from '@/Components/Settings/DeviceTiles.vue';
import { useDeviceStatus } from '@/Composables/useDeviceStatus';

/**
 * Geräte & Apps als eigene Seite hinter einer Zeile auf Mehr (Jan,
 * 10.11.2026: Kacheln mitten in der Einstellungsliste wirkten unstimmig).
 */
const props = defineProps({
    devices: { type: Object, required: true },
});

const status = useDeviceStatus(computed(() => props.devices));
const t = computed(() => status.value.tiles);
</script>

<template>
    <Head title="Geräte & Apps" />

    <AuthenticatedLayout>
        <div class="min-h-screen bg-canvas">
            <PageHeader width="max-w-2xl" title="Geräte & Apps" :back="route('more')" />

            <div class="mx-auto max-w-2xl px-4 pb-8 pt-3 lg:px-8">
                <p class="mx-1 mb-4 text-[15px] text-ink-3">Antippen zum Verbinden oder für Einstellungen.</p>
                <DeviceTiles
                    :garmin="t.garmin"
                    :coros="t.coros"
                    :polar="t.polar"
                    :strava="t.strava"
                    :apple="t.apple"
                    :apple-detail="t.appleDetail" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
