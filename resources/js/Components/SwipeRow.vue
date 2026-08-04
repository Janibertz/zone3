<script setup>
// Reusable swipe-to-reveal row for touch lists.
// Swipe left  → reveals the `right` slot (e.g. delete / skip).
// Swipe right → reveals the `left`  slot (e.g. edit / complete).
// The content layer is opaque and slides over the action zones. On desktop the
// row is untouched — actions there should still be reachable via normal buttons.
import { ref, computed, onMounted } from 'vue';

const props = defineProps({
    leftWidth:  { type: Number, default: 0 }, // px, revealed by swiping right
    rightWidth: { type: Number, default: 0 }, // px, revealed by swiping left
    contentClass: { type: String, default: 'bg-surface' },
    disabled:   { type: Boolean, default: false },
});

// Swipe is a touch-only affordance. On desktop (no touch / fine pointer) the action
// zones are not rendered at all — otherwise they sit permanently behind the content and
// bleed through translucent cards. Desktop reaches the same actions via inline buttons.
const isTouch = ref(false);
onMounted(() => {
    isTouch.value = typeof window !== 'undefined' &&
        (window.matchMedia?.('(pointer: coarse)').matches ?? ('ontouchstart' in window));
});
const leftW  = computed(() => (isTouch.value ? props.leftWidth  : 0));
const rightW = computed(() => (isTouch.value ? props.rightWidth : 0));

const offset   = ref(0);
const dragging = ref(false);
let startX = 0, startY = 0, startOffset = 0, horizontal = null;

function onStart(e) {
    if (props.disabled) return;
    const t = e.touches ? e.touches[0] : e;
    startX = t.clientX; startY = t.clientY; startOffset = offset.value;
    horizontal = null; dragging.value = true;
}
function onMove(e) {
    if (!dragging.value) return;
    const t = e.touches ? e.touches[0] : e;
    const dx = t.clientX - startX;
    const dy = t.clientY - startY;
    if (horizontal === null) {
        if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
        horizontal = Math.abs(dx) > Math.abs(dy);
        if (!horizontal) { dragging.value = false; return; } // let vertical scroll win
    }
    offset.value = Math.max(-rightW.value, Math.min(leftW.value, startOffset + dx));
}
function onEnd() {
    if (!dragging.value) return;
    dragging.value = false;
    if (offset.value <= -rightW.value / 2 && rightW.value) offset.value = -rightW.value;
    else if (offset.value >= leftW.value / 2 && leftW.value)  offset.value = leftW.value;
    else offset.value = 0;
}
function close() { offset.value = 0; }
defineExpose({ close });
</script>

<template>
    <div class="relative overflow-hidden">
        <!-- Left action zone (revealed by swiping right) -->
        <div v-if="leftW" class="absolute inset-y-0 left-0 flex items-stretch" :style="{ width: leftW + 'px' }">
            <slot name="left" :close="close" />
        </div>
        <!-- Right action zone (revealed by swiping left) -->
        <div v-if="rightW" class="absolute inset-y-0 right-0 flex items-stretch" :style="{ width: rightW + 'px' }">
            <slot name="right" :close="close" />
        </div>
        <!-- Sliding content -->
        <div
            class="relative will-change-transform"
            :class="[contentClass, dragging ? '' : 'transition-transform duration-200']"
            :style="{ transform: `translateX(${offset}px)` }"
            @touchstart.passive="onStart"
            @touchmove.passive="onMove"
            @touchend="onEnd"
            @touchcancel="onEnd"
        >
            <slot :open="offset !== 0" :close="close" />
        </div>
    </div>
</template>
