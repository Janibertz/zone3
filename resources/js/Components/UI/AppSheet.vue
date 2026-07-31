<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue';

/**
 * Bottom sheet on mobile, right-hand side panel from `lg` up.
 *
 * Replaces Modal.vue and keeps its API (`:show` + `@close`) so call sites
 * only swap the import. On touch devices the sheet can be flicked away by
 * dragging the grabber or the header.
 */
const props = defineProps({
    show:      { type: Boolean, default: false },
    title:     { type: String,  default: null },
    subtitle:  { type: String,  default: null },
    /** Set false to force an explicit action (backdrop, Esc and X are disabled). */
    closeable: { type: Boolean, default: true },
    /** Desktop panel width. */
    maxWidth:  { type: String,  default: 'md' },
    /** Mobile sheet takes the full height instead of hugging its content. */
    tall:      { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const panel      = ref(null);
const dragY      = ref(0);
const dragging   = ref(false);
let   startY     = 0;
let   startTime  = 0;
let   lastActive = null;

const widthClass = computed(() => ({
    sm:  'lg:max-w-sm',
    md:  'lg:max-w-md',
    lg:  'lg:max-w-lg',
    xl:  'lg:max-w-xl',
    '2xl': 'lg:max-w-2xl',
}[props.maxWidth] ?? 'lg:max-w-md'));

function close() {
    if (props.closeable) emit('close');
}

// ── Body scroll lock ────────────────────────────────────────
// position:fixed instead of overflow:hidden — iOS Safari ignores the latter.
let savedScroll = 0;

function lockScroll() {
    savedScroll = window.scrollY;
    document.body.style.position = 'fixed';
    document.body.style.top      = `-${savedScroll}px`;
    document.body.style.width    = '100%';
}

function unlockScroll() {
    if (!document.body.style.position) return;
    document.body.style.position = '';
    document.body.style.top      = '';
    document.body.style.width    = '';
    window.scrollTo(0, savedScroll);
}

// ── Keyboard ────────────────────────────────────────────────
function onKeydown(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        close();
        return;
    }

    // Keep focus inside the sheet while it is open.
    if (e.key !== 'Tab' || !panel.value) return;

    const focusable = panel.value.querySelectorAll(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (!focusable.length) return;

    const first = focusable[0];
    const last  = focusable[focusable.length - 1];

    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

// ── Drag to dismiss (touch only) ────────────────────────────
function onDragStart(e) {
    if (!props.closeable) return;
    dragging.value = true;
    startY    = e.touches[0].clientY;
    startTime = Date.now();
}

function onDragMove(e) {
    if (!dragging.value) return;
    const dy = e.touches[0].clientY - startY;
    // Downward only, with resistance once past the dismiss threshold.
    dragY.value = dy < 0 ? 0 : dy > 120 ? 120 + (dy - 120) * 0.3 : dy;
}

function onDragEnd() {
    if (!dragging.value) return;
    dragging.value = false;

    const velocity = dragY.value / Math.max(Date.now() - startTime, 1);

    if (dragY.value > 120 || velocity > 0.5) {
        close();
        // Reset after the leave transition so the next open starts at rest.
        setTimeout(() => { dragY.value = 0; }, 300);
    } else {
        dragY.value = 0;
    }
}

const panelStyle = computed(() => {
    if (!dragY.value) return {};
    return {
        transform:  `translateY(${dragY.value}px)`,
        transition: dragging.value ? 'none' : undefined,
    };
});

// ── Open / close side effects ───────────────────────────────
watch(() => props.show, async (open) => {
    if (open) {
        lastActive = document.activeElement;
        dragY.value = 0;
        lockScroll();
        document.addEventListener('keydown', onKeydown);
        await nextTick();
        // Focus the panel itself rather than the first field — opening a sheet
        // shouldn't pop up the mobile keyboard unasked.
        panel.value?.focus();
    } else {
        unlockScroll();
        document.removeEventListener('keydown', onKeydown);
        lastActive?.focus?.();
    }
});

onUnmounted(() => {
    unlockScroll();
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-300 ease-sheet"
            enter-from-class="opacity-0"
            leave-active-class="transition-opacity duration-200 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 bg-black/50 backdrop-blur-[2px]"
                @click="close"
            />
        </Transition>

        <!-- Panel: slides up on mobile, in from the right on desktop -->
        <Transition
            enter-active-class="transition-transform duration-300 ease-sheet"
            enter-from-class="translate-y-full lg:translate-y-0 lg:translate-x-full"
            leave-active-class="transition-transform duration-200 ease-in"
            leave-to-class="translate-y-full lg:translate-y-0 lg:translate-x-full"
        >
            <div
                v-if="show"
                ref="panel"
                tabindex="-1"
                role="dialog"
                aria-modal="true"
                :aria-label="title ?? undefined"
                :style="panelStyle"
                class="fixed z-50 flex flex-col bg-surface text-ink shadow-sheet outline-none
                       inset-x-0 bottom-0 rounded-t-sheet
                       lg:inset-y-0 lg:left-auto lg:right-0 lg:w-full lg:rounded-none lg:rounded-l-sheet"
                :class="[widthClass, tall ? 'top-[env(safe-area-inset-top)]' : 'max-h-[90dvh]']"
            >
                <!-- Grabber + header double as the drag surface -->
                <div
                    class="shrink-0 touch-none"
                    @touchstart.passive="onDragStart"
                    @touchmove.passive="onDragMove"
                    @touchend="onDragEnd"
                    @touchcancel="onDragEnd"
                >
                    <div class="flex justify-center pt-3 pb-1 lg:hidden">
                        <div class="h-1 w-10 rounded-full bg-line-strong" />
                    </div>

                    <div
                        v-if="title || $slots.header"
                        class="flex items-start gap-3 px-5 pb-3 pt-2 lg:pt-5"
                    >
                        <div class="min-w-0 flex-1">
                            <slot name="header">
                                <h2 class="truncate text-base font-semibold text-ink">{{ title }}</h2>
                                <p v-if="subtitle" class="mt-0.5 text-sm text-ink-3">{{ subtitle }}</p>
                            </slot>
                        </div>
                        <button
                            v-if="closeable"
                            type="button"
                            aria-label="Schließen"
                            class="-mr-1 -mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full
                                   text-ink-3 transition-colors hover:bg-surface-3 hover:text-ink active:scale-95"
                            @click="close"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Scrollable body -->
                <div
                    class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5"
                    :class="$slots.footer ? 'pb-4' : 'pb-safe-sheet'"
                >
                    <slot />
                </div>

                <!-- Sticky actions -->
                <div
                    v-if="$slots.footer"
                    class="shrink-0 border-t border-line bg-surface px-5 pt-4 pb-safe-sheet"
                >
                    <slot name="footer" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
