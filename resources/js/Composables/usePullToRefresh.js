import { ref, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Pull-to-refresh for touch devices. Reloads the current Inertia page when the
 * user pulls down from the very top of the document.
 *
 * Native browser pull-to-refresh is suppressed via `overscroll-behavior-y` in
 * CSS — see app.css. Listeners are passive (no scroll jank); the pull only
 * drives a visual indicator, the page itself does not move.
 */
export function usePullToRefresh({ threshold = 70 } = {}) {
    const pullDistance = ref(0);
    const refreshing   = ref(false);
    let startY  = 0;
    let pulling = false;

    function onTouchStart(e) {
        if (refreshing.value || window.scrollY > 0) { pulling = false; return; }
        startY  = e.touches[0].clientY;
        pulling = true;
    }

    function onTouchMove(e) {
        if (!pulling || refreshing.value) return;
        const dy = e.touches[0].clientY - startY;
        // Abort if the user scrolled up or the page moved away from the top.
        if (dy <= 0 || window.scrollY > 0) { pullDistance.value = 0; return; }
        pullDistance.value = Math.min(dy * 0.5, threshold * 1.5); // resistance
    }

    function onTouchEnd() {
        if (!pulling) return;
        pulling = false;
        if (pullDistance.value >= threshold && !refreshing.value) {
            refreshing.value   = true;
            pullDistance.value = threshold;
            router.reload({
                onFinish: () => { refreshing.value = false; pullDistance.value = 0; },
            });
        } else {
            pullDistance.value = 0;
        }
    }

    onMounted(() => {
        if (typeof window === 'undefined' || !('ontouchstart' in window)) return;
        window.addEventListener('touchstart', onTouchStart, { passive: true });
        window.addEventListener('touchmove',  onTouchMove,  { passive: true });
        window.addEventListener('touchend',   onTouchEnd,   { passive: true });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('touchstart', onTouchStart);
        window.removeEventListener('touchmove',  onTouchMove);
        window.removeEventListener('touchend',   onTouchEnd);
    });

    return { pullDistance, refreshing, threshold };
}
