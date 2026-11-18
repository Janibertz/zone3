<script setup>
/** Status-Punkt: success grün, warn orange, danger rot, off grau. */
defineProps({ tone: { type: String, default: 'off' } });
</script>

<template>
    <span class="h-2 w-2 shrink-0 rounded-full"
        :class="{ success: 'bg-success', warn: 'bg-warn', danger: 'bg-danger', plain: 'bg-line-strong', off: 'bg-line-strong' }[tone] ?? 'bg-line-strong'" />
</template>
