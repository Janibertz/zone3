<script setup>
/** Die angehobene Einheit unter dem Finger, waehrend sie gezogen wird (useDragToDay). */
defineProps({ drag: { type: Object, required: true } });
</script>

<template>
    <Teleport to="body">
        <div
            v-if="drag.active"
            class="pointer-events-none fixed left-0 top-0 z-[60] max-w-[70vw] -translate-x-1/2 -translate-y-[130%] rounded-card bg-surface px-4 py-2.5 text-[15px] font-semibold text-ink shadow-xl ring-2 ring-accent"
            :style="{ left: drag.x + 'px', top: drag.y + 'px' }"
        >
            <p class="truncate">{{ drag.label }}</p>
            <p class="text-[12px] font-medium" :class="drag.overDate ? 'text-accent' : 'text-ink-3'">
                {{ drag.overDate
                    ? 'Auf ' + new Date(drag.overDate + 'T00:00:00').toLocaleDateString('de-DE', { weekday: 'short', day: 'numeric', month: 'numeric' })
                    : 'Auf einen Tag ziehen' }}
            </p>
        </div>
    </Teleport>
</template>
