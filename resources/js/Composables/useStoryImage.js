/**
 * Ein Story-Bild (1080 × 1920) für Instagram, im Browser gezeichnet — ohne
 * Server, ohne Modellaufruf (seit 09.11.2026, Jan: „mehr Emotion“).
 *
 * Gezeichnet wird auf ein Canvas: Foto randlos, Verlauf nach unten,
 * „Zone3.“ oben, unten Überschrift, große Zahl und drei Kennzahlen. Inter
 * ist über @fontsource ohnehin geladen; `document.fonts.load` wartet darauf,
 * sonst nähme das Canvas beim ersten Mal die Ersatzschrift.
 *
 * Teilen geht über die Web-Share-API mit Datei (iOS Safari, die App,
 * Android Chrome). Wo das fehlt (Desktop-Firefox), wird das Bild
 * heruntergeladen.
 */

export const W = 1080;
export const H = 1920;
export const ACCENT = '#2997ff';
export const FONT = "'Inter Variable', Inter, system-ui, sans-serif";

export function loadImage(src) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve(img);
        img.onerror = reject;
        img.src = src;
    });
}

/** Das Bild randlos füllen, wie `object-fit: cover`; `focusX` 0–1 verschiebt den Ausschnitt. */
export function cover(ctx, img, focusX = 0.5) {
    const scale = Math.max(W / img.width, H / img.height);
    const w = img.width * scale;
    const h = img.height * scale;
    ctx.drawImage(img, (W - w) * focusX, (H - h) / 2, w, h);
}

export function text(ctx, value, x, y, { size, weight = 800, color = '#fff', tracking = 0 }) {
    ctx.font = `${weight} ${size}px ${FONT}`;
    ctx.fillStyle = color;
    if ('letterSpacing' in ctx) ctx.letterSpacing = `${tracking * size}px`;
    ctx.fillText(value, x, y);
    // Breite mit Laufweite messen, bevor sie zurückgesetzt wird — sonst
    // stünde der Punkt der Wortmarke abgesetzt.
    const width = ctx.measureText(value).width;
    if ('letterSpacing' in ctx) ctx.letterSpacing = '0px';
    return width;
}

/**
 * @param {{ photo: string, eyebrow: string, label: string, value: string,
 *           stats: Array<{ value: string, label: string, color?: string }>, focusX?: number }} story
 * @returns {Promise<Blob>}
 */
export async function drawStory(story) {
    await Promise.all([
        document.fonts?.load(`800 100px ${FONT}`),
        document.fonts?.load(`600 40px ${FONT}`),
    ].filter(Boolean)).catch(() => {});

    const canvas = document.createElement('canvas');
    canvas.width = W;
    canvas.height = H;
    const ctx = canvas.getContext('2d');

    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, W, H);
    cover(ctx, await loadImage(story.photo), story.focusX ?? 0.5);

    const shade = ctx.createLinearGradient(0, 0, 0, H);
    shade.addColorStop(0, 'rgba(0,0,0,0.35)');
    shade.addColorStop(0.22, 'rgba(0,0,0,0)');
    shade.addColorStop(0.45, 'rgba(0,0,0,0.15)');
    shade.addColorStop(0.75, 'rgba(0,0,0,0.85)');
    shade.addColorStop(1, '#000');
    ctx.fillStyle = shade;
    ctx.fillRect(0, 0, W, H);

    ctx.textBaseline = 'alphabetic';

    // Wortmarke oben links: „Zone3“ weiß, Punkt in Akzentblau — wie das Logo.
    const wm = text(ctx, 'Zone3', 72, 150, { size: 66, tracking: -0.035 });
    text(ctx, '.', 72 + wm, 150, { size: 66, color: ACCENT });

    // Von unten nach oben gesetzt, damit lange Zahlen nichts überlappen.
    const statsTop = H - 250;
    const colW = (W - 144) / Math.max(1, story.stats.length);
    story.stats.forEach((s, i) => {
        const x = 72 + i * colW;
        text(ctx, s.value, x, statsTop, { size: 66, color: s.color ?? '#fff', tracking: -0.03 });
        text(ctx, s.label, x, statsTop + 56, { size: 36, weight: 500, color: 'rgba(255,255,255,0.65)' });
    });

    // Die große Zahl so groß wie möglich, höchstens 300 px.
    let size = 300;
    ctx.font = `800 ${size}px ${FONT}`;
    while (ctx.measureText(story.value).width > W - 144 && size > 120) {
        size -= 10;
        ctx.font = `800 ${size}px ${FONT}`;
    }
    const valueY = statsTop - 120;
    text(ctx, story.value, 64, valueY, { size, tracking: -0.055 });
    text(ctx, story.label, 72, valueY - size * 0.86, { size: 60, weight: 700, color: 'rgba(255,255,255,0.85)' });
    text(ctx, story.eyebrow.toUpperCase(), 72, valueY - size * 0.86 - 84, { size: 40, weight: 700, color: ACCENT, tracking: 0.02 });

    return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
}

/**
 * Teilt das Bild oder lädt es herunter.
 *
 * @returns {Promise<'shared'|'downloaded'|'cancelled'>}
 */
export async function shareStory(blob, filename = 'zone3-story.png') {
    const file = new File([blob], filename, { type: 'image/png' });

    if (navigator.canShare?.({ files: [file] })) {
        try {
            await navigator.share({ files: [file] });
            return 'shared';
        } catch (e) {
            // Abbrechen im Teilen-Dialog ist kein Fehler.
            if (e?.name === 'AbortError') return 'cancelled';
        }
    }

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10_000);
    return 'downloaded';
}
