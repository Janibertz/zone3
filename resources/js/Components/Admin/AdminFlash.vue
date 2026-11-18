<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
</script>

<template>
    <div v-if="flash.success || flash.error" role="status"
        class="rounded-[14px] px-3.5 py-3 text-[15px] font-semibold"
        :class="flash.error ? 'bg-danger-soft text-danger-ink' : 'bg-success-soft text-success-ink'">
        {{ flash.error || flash.success }}
    </div>
</template>
