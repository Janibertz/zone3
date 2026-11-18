<script setup>
import AdminSegmented from '@/Components/Admin/AdminSegmented.vue';

defineProps({ current: { type: String, required: true } });

const options = [
    { value: 'status', label: 'Status', href: route('admin.system.index') },
    { value: 'log', label: 'Log', href: route('admin.system.logs') },
    { value: 'ai', label: 'KI-Aufrufe', href: route('admin.ai-logs.index') },
];
</script>

<template>
    <AdminSegmented :model-value="current" :options="options" />
</template>
