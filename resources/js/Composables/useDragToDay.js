import { reactive, onBeforeUnmount } from 'vue';

/**
 * Lange druecken, die Einheit hebt sich an, auf einen anderen Tag ziehen.
 *
 * Gewuenscht am 28.10.2026 statt Knoepfen. Das Drag & Drop des Browsers
 * kann Safari auf dem iPhone nicht, darum laeuft es hier ueber Touch- und
 * Mausereignisse selbst:
 *
 * - **Mit dem Finger erst nach dem langen Druck** (`HOLD_MS`). Bewegt sich
 *   der Finger vorher, ist es ein Scrollen oder ein Wischen (SwipeRow), und es
 *   passiert nichts.
 * - **Mit der Maus sofort**, sobald sie sich bei gedrueckter Taste bewegt —
 *   so erwartet man es am Desktop. Ein langer Druck vorher hiess dort: wer
 *   gleich zieht, bricht ab, und das war jeder. Knoepfe und Links in der
 *   Karte starten nichts, und das native Ziehen des Browsers (markierter
 *   Text, Links) wird unterdrueckt, sonst kaemen keine `mousemove` mehr an.
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
    let pending = null;

    function pointOf(e) {
        const t = e.touches?.[0] ?? e.changedTouches?.[0] ?? e;
        return { x: t.clientX, y: t.clientY };
    }

    /**
     * An den Startpunkt haengen: `@touchstart="start($event, s, s.planned_date, s.title)"`
     * und dasselbe fuer `@mousedown`.
     */
    function start(e, item, date, label) {
        const mouse = e.type === 'mousedown';
        if (mouse && (e.button !== 0 || e.target.closest?.('button, a, input, textarea, select'))) return;
        cancel();

        const p = pointOf(e);
        startX = p.x;
        startY = p.y;
        fromDate = date;
        pending = { item, label };

        window.addEventListener('touchmove', onMove, { passive: false });
        window.addEventListener('touchend', onEnd);
        window.addEventListener('touchcancel', cancel);
        window.addEventListener('mousemove', onMove);
        window.addEventListener('mouseup', onEnd);
        window.addEventListener('dragstart', preventNative);

        if (!mouse) timer = setTimeout(() => activate(p), HOLD_MS);
    }

    function activate(p) {
        clearTimeout(timer);
        timer = null;
        Object.assign(drag, { active: true, ...pending, x: p.x, y: p.y, overDate: null });
        window.getSelection?.()?.removeAllRanges();
        document.documentElement.classList.add('select-none');
        autoscroll();
    }

    function preventNative(e) { e.preventDefault(); }

    function onMove(e) {
        const p = pointOf(e);

        if (!drag.active) {
            const moved = Math.abs(p.x - startX) > SLOP_PX || Math.abs(p.y - startY) > SLOP_PX;
            if (!moved) return;
            // Maus: jetzt geht es los. Finger vor dem langen Druck: Scrollen
            // oder Wischen, kein Ziehen.
            if (e.type === 'mousemove') activate(p);
            else return cancel();
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
        pending = null;
        Object.assign(drag, { active: false, item: null, label: '', overDate: null });
        document.documentElement.classList.remove('select-none');

        window.removeEventListener('touchmove', onMove);
        window.removeEventListener('touchend', onEnd);
        window.removeEventListener('touchcancel', cancel);
        window.removeEventListener('mousemove', onMove);
        window.removeEventListener('mouseup', onEnd);
        window.removeEventListener('dragstart', preventNative);
    }

    onBeforeUnmount(cancel);

    return { drag, start };
}
