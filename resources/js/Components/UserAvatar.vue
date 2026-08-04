<script setup>
import { computed } from 'vue';

const props = defineProps({
    user: { type: Object, required: true },
    size: { type: String, default: 'md' }, // sm | md | lg
});

const sizeClasses = {
    sm: 'h-7 w-7 text-xs',
    md: 'h-9 w-9 text-sm',
    lg: 'h-12 w-12 text-base',
};

const initials = computed(() => {
    const parts = (props.user.name ?? '').trim().split(' ');
    if (parts.length >= 2) return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    return (props.user.name ?? 'U').slice(0, 2).toUpperCase();
});

const avatarUrl = computed(() => {
    if (props.user.avatar) return '/storage/' + props.user.avatar;
    return null;
});
</script>

<template>
    <div
        class="shrink-0 rounded-full bg-gradient-to-br from-accent to-accent flex items-center justify-center shadow-sm overflow-hidden"
        :class="sizeClasses[size]"
    >
        <img v-if="avatarUrl" :src="avatarUrl" :alt="user.name" class="h-full w-full object-cover" />
        <span v-else class="font-bold text-white leading-none">{{ initials }}</span>
    </div>
</template>
