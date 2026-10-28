import { reactive, onBeforeUnmount } from 'vue';

/**
 * Lange druecken, die Einheit hebt sich an, auf einen anderen Tag ziehen.
 *
 * Gewuenscht am 28.10.2026 statt Knoepfen. Das Drag & Drop des Browsers
 * kann Safari auf dem iPhone nicht, darum laeuft es hier ueber Touch- und
 * Mausereignisse selbst:
 *
 * - **Erst nach dem langen Druck** (`HOLD_MS`) wird gezogen. Bewegt sich der
 *   Finger vorher, ist es ein Scrollen oder ein Wischen (SwipeRow), und es
 *   passiert nichts.
 * - Waehrend des Ziehens wird das Scrollen der Seite unterdrueckt; nahe am
 *   oberen oder unteren Rand scrollt sie von selbst, damit man im Kalender
 *   aus der Tagesansicht ins Raster darueber kommt.
 * - Ziele sind Elemente mit `data-drop-date="YYYY-MM-DD"`.
 *
 * @param {(item: any, date: string) => void} onDrop
 */
export function useDragToDay(onDrop) {
    const HOLD_MS = 350;
    const SLOP_PX = 8;
    const EDGE_PX = 90;

    const drag = reactive({ active: false, item: null, label: '', x: 0, y: 0, overDate: null });

    let timer = null;
    let startX = 0;
    let startY = 0;
    let fromDate = null;
    let raf = null;

    function pointOf(e) {
        const t = e.touches?.[0] ?? e.changedTouches?.[0] ?? e;
        return { x: t.clientX, y: t.clientY };
    }

    /**
     * An den Startpunkt haengen: `@touchstart="start($event, s, s.planned_date, s.title)"`
     * und dasselbe fuer `@mousedown`.
     */
    function start(e, item, date, label) {
        if (e.type === 'mousedown' && e.button !== 0) return;
        cancel();

        const p = pointOf(e);
        startX = p.x;
        startY = p.y;
        fromDate = date;

        window.addEventListener('touchmove', onMove, { passive: false });
        window.addEventListener('touchend', onEnd);
        window.addEventListener('touchcancel', cancel);
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onEnd);

        timer = setTimeout(() => {
            timer = null;
            Object.assign(drag, { active: true, item, label, x: p.x, y: p.y, overDate: null });
            document.documentElement.classList.add('select-none');
            autoscroll();
        }, HOLD_MS);
    }

    function onMove(e) {
        const p = pointOf(e);

        if (!drag.active) {
            // Vor dem langen Druck bewegt: Scrollen oder Wischen, kein Ziehen.
            if (Math.abs(p.x - startX) > SLOP_PX || Math.abs(p.y - startY) > SLOP_PX) cancel();
            return;
        }

        if (e.cancelable) e.preventDefault();
        drag.x = p.x;
        drag.y = p.y;

        const target = document.elementFromPoint(p.x, p.y)?.closest('[data-drop-date]');
        const date = target?.dataset.dropDate ?? null;
        drag.overDate = date && date !== fromDate ? date : null;
    }

    function onEnd() {
        const { active, item, overDate } = drag;
        cancel();
        if (!active) return;

        // Das Loslassen loest sonst einen Klick auf die Karte aus und oeffnet sie.
        const swallow = (e) => { e.stopPropagation(); e.preventDefault(); };
        window.addEventListener('click', swallow, { capture: true, once: true });
        setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 400);

        if (overDate) onDrop(item, overDate);
    }

    function autoscroll() {
        if (!drag.active) return;
        if (drag.y < EDGE_PX) window.scrollBy(0, -10);
        else if (drag.y > window.innerHeight - EDGE_PX) window.scrollBy(0, 10);
        raf = requestAnimationFrame(autoscroll);
    }

    function cancel() {
        clearTimeout(timer);
        timer = null;
        cancelAnimationFrame(raf);
        Object.assign(drag, { active: false, item: null, label: '', overDate: null });
        document.documentElement.classList.remove('select-none');

        window.removeEventListener('touchmove', onMove);
        window.removeEventListener('touchend', onEnd);
        window.removeEventListener('touchcancel', cancel);
        window.removeEventListener('mousemove', onMove);
        window.removeEventListener('mouseup', onEnd);
    }

    onBeforeUnmount(cancel);

    return { drag, start };
}
