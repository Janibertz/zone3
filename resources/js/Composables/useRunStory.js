/**
 * Einen Lauf als Story-Bild teilen (Jan, 10.11.2026: „ein Teilen-Knopf nach
 * einem Lauf … mit Templates als Vorlage oder auch mit eigenen Bildern“).
 *
 * Vier Vorlagen, alle im Browser gezeichnet wie `useStoryImage` — kein
 * Server, kein Modell, das eigene Foto verlässt das Gerät nicht:
 *
 *   photo    Foto randlos (Vorlage oder eigenes), Zahlen unten
 *   route    die Strecke auf dunklem Grund, Ort statt Foto
 *   numbers  hell, nur Zahlen, mit Zeit je Zone
 *   sticker  transparentes PNG zum Aufkleben aufs eigene Story-Foto
 *
 * Bei der Route fallen die ersten und letzten 200 m weg, damit niemand
 * an der Strecke die Haustür abliest.
 */
import { W, H, ACCENT, FONT, loadImage, cover, text } from '@/Composables/useStoryImage';

export const TEMPLATES = [
    { key: 'photo',   label: 'Foto' },
    { key: 'route',   label: 'Route' },
    { key: 'numbers', label: 'Zahlen' },
    { key: 'sticker', label: 'Sticker' },
];

export const DEFAULT_PHOTO = '/images/moments/rennen.jpg';

const PRIVACY_M = 200;
const ZONE_COLORS = ['#8E8E93', '#34C759', '#0071E3', '#FF9500', '#FF3B30'];

async function fonts() {
    await Promise.all([
        document.fonts?.load(`800 100px ${FONT}`),
        document.fonts?.load(`600 40px ${FONT}`),
    ].filter(Boolean)).catch(() => {});
}

function canvas(w = W, h = H) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    return [c, c.getContext('2d')];
}

function toBlob(c) {
    return new Promise((resolve) => c.toBlob(resolve, 'image/png'));
}

/** Wortmarke „Zone3.“ — Buchstaben in `color`, Punkt immer blau. */
function wordmark(ctx, x, y, size, color = '#fff') {
    const w = text(ctx, 'Zone3', x, y, { size, color, tracking: -0.035 });
    text(ctx, '.', x + w, y, { size, color: ACCENT });
}

/** Große Zahl mit kleiner Einheit daneben; gibt die Gesamtbreite zurück. */
function bigValue(ctx, value, unit, x, y, size, { color = '#fff', unitColor = 'rgba(255,255,255,0.7)' } = {}) {
    const w = text(ctx, value, x, y, { size, color, tracking: -0.055 });
    if (!unit) return w;
    return w + 14 + text(ctx, unit, x + w + 14, y, { size: Math.round(size * 0.24), weight: 700, color: unitColor });
}

/** Die größte Schrift, mit der `value` + Einheit in `maxW` passen. */
function fitSize(ctx, value, unit, maxW, start) {
    let size = start;
    for (; size > 100; size -= 10) {
        ctx.font = `800 ${size}px ${FONT}`;
        let w = ctx.measureText(value).width;
        if (unit) {
            ctx.font = `700 ${Math.round(size * 0.24)}px ${FONT}`;
            w += 14 + ctx.measureText(unit).width;
        }
        if (w <= maxW) break;
    }
    return size;
}

function statsRow(ctx, stats, x, y, width, { color = '#fff', labelColor = 'rgba(255,255,255,0.65)', size = 66 } = {}) {
    const col = width / Math.max(1, stats.length);
    stats.forEach((s, i) => {
        const w = text(ctx, s.value, x + i * col, y, { size, color, tracking: -0.03 });
        if (s.unit) text(ctx, s.unit, x + i * col + w + 6, y, { size: Math.round(size * 0.45), weight: 700, color: labelColor });
        text(ctx, s.label, x + i * col, y + size * 0.85, { size: Math.round(size * 0.53), weight: 500, color: labelColor });
    });
}

// ── Strecke ──────────────────────────────────────────────────────────────

function metres(a, b) {
    const R = 6371000;
    const dLat = (b[0] - a[0]) * Math.PI / 180;
    const dLng = (b[1] - a[1]) * Math.PI / 180;
    const h = Math.sin(dLat / 2) ** 2
        + Math.cos(a[0] * Math.PI / 180) * Math.cos(b[0] * Math.PI / 180) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

/** Die Strecke ohne die ersten und letzten 200 m (oder ein Zehntel bei kurzen). */
export function trimRoute(coords) {
    if (!coords || coords.length < 3) return coords ?? [];
    const cum = [0];
    for (let i = 1; i < coords.length; i++) cum.push(cum[i - 1] + metres(coords[i - 1], coords[i]));
    const total = cum.at(-1);
    const cut = Math.min(PRIVACY_M, total * 0.1);
    const out = coords.filter((_, i) => cum[i] >= cut && cum[i] <= total - cut);
    return out.length > 1 ? out : coords;
}

/** Projiziert die Strecke in ein Rechteck, Seitenverhältnis bleibt. */
function project(coords, box) {
    const lat0 = coords.reduce((a, c) => a + c[0], 0) / coords.length;
    const k = Math.cos(lat0 * Math.PI / 180);
    const pts = coords.map(([lat, lng]) => [lng * k, -lat]);
    const xs = pts.map(p => p[0]);
    const ys = pts.map(p => p[1]);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const scale = Math.min(box.w / (maxX - minX || 1e-9), box.h / (maxY - minY || 1e-9));
    const offX = box.x + (box.w - (maxX - minX) * scale) / 2;
    const offY = box.y + (box.h - (maxY - minY) * scale) / 2;
    return pts.map(([x, y]) => [offX + (x - minX) * scale, offY + (y - minY) * scale]);
}

function roads(ctx, color) {
    // Dasselbe eigene Straßenmuster wie auf den Kacheln, auf 1080 × 1920 gezogen.
    ctx.save();
    ctx.scale(W / 270, H / 480);
    ctx.strokeStyle = color;
    ctx.lineWidth = 1.3;
    ['M-10 90 C60 70 120 140 200 110 S270 60 290 80', 'M-10 260 C50 280 120 230 190 270 S260 320 290 290',
        'M80 -10 C70 120 110 220 90 490', 'M200 -10 C210 140 170 260 210 490', 'M-10 400 L290 390']
        .forEach(d => ctx.stroke(new Path2D(d)));
    ctx.restore();
}

// ── Vorlagen ─────────────────────────────────────────────────────────────

async function drawPhoto(d, photo) {
    const [c, ctx] = canvas();
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, W, H);
    cover(ctx, await loadImage(photo || d.photo || DEFAULT_PHOTO));

    const shade = ctx.createLinearGradient(0, 0, 0, H);
    shade.addColorStop(0, 'rgba(0,0,0,0.2)');
    shade.addColorStop(0.4, 'rgba(0,0,0,0.05)');
    shade.addColorStop(0.72, 'rgba(0,0,0,0.75)');
    shade.addColorStop(1, 'rgba(0,0,0,0.92)');
    ctx.fillStyle = shade;
    ctx.fillRect(0, 0, W, H);

    wordmark(ctx, 72, H - 110, 54);
    statsRow(ctx, d.stats, 72, H - 270, W - 144);
    const size = fitSize(ctx, d.big.value, d.big.unit, W - 144, 300);
    const y = H - 400;
    bigValue(ctx, d.big.value, d.big.unit, 64, y, size, { unitColor: 'rgba(255,255,255,0.75)' });
    text(ctx, d.eyebrow.toUpperCase(), 72, y - size * 0.86, { size: 40, weight: 700, color: ACCENT, tracking: 0.02 });
    return toBlob(c);
}

async function drawRoute(d) {
    const [c, ctx] = canvas();
    ctx.fillStyle = '#0B0E12';
    ctx.fillRect(0, 0, W, H);
    roads(ctx, 'rgba(255,255,255,0.05)');

    const route = trimRoute(d.coords);
    if (route.length > 1) {
        const pts = project(route, { x: 140, y: 330, w: W - 280, h: 1000 });
        ctx.strokeStyle = ACCENT;
        ctx.lineWidth = 18;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        ctx.shadowColor = 'rgba(41,151,255,0.55)';
        ctx.shadowBlur = 30;
        ctx.beginPath();
        pts.forEach(([x, y], i) => (i ? ctx.lineTo(x, y) : ctx.moveTo(x, y)));
        ctx.stroke();
        ctx.shadowBlur = 0;
    }

    wordmark(ctx, 72, 150, 66);
    const line = [d.place, d.dateShort].filter(Boolean).join(' · ').toUpperCase();
    text(ctx, line, 72, H - 330, { size: 40, weight: 700, color: '#8E8E93', tracking: 0.02 });
    const items = [d.big, ...d.stats].slice(0, 3);
    const col = (W - 144) / items.length;
    items.forEach((s, i) => {
        const w = text(ctx, s.value, 72 + i * col, H - 220, { size: 86, tracking: -0.04 });
        if (s.unit) text(ctx, s.unit, 72 + i * col + w + 8, H - 220, { size: 34, weight: 700, color: '#8E8E93' });
        if (s.label) text(ctx, s.label, 72 + i * col, H - 160, { size: 34, weight: 500, color: '#8E8E93' });
    });
    return toBlob(c);
}

async function drawNumbers(d) {
    const [c, ctx] = canvas();
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, W, H);

    wordmark(ctx, 88, 170, 60, '#000');
    text(ctx, d.title, 88, 520, { size: 52, weight: 700, color: d.color || ACCENT });
    const size = fitSize(ctx, d.big.value, d.big.unit, W - 176, 330);
    bigValue(ctx, d.big.value, d.big.unit, 80, 520 + size * 0.95, size, { color: '#000', unitColor: '#8E8E93' });

    const grid = d.stats.concat(d.extra ?? []).slice(0, 4);
    const top = 520 + size * 0.95 + 220;
    grid.forEach((s, i) => {
        const x = 88 + (i % 2) * ((W - 176) / 2);
        const y = top + Math.floor(i / 2) * 230;
        const vw = text(ctx, s.value, x, y, { size: 104, color: '#000', tracking: -0.04 });
        if (s.unit) text(ctx, s.unit, x + vw + 8, y, { size: 40, weight: 700, color: '#8E8E93' });
        text(ctx, s.label, x, y + 62, { size: 40, weight: 600, color: '#8E8E93' });
    });

    if (d.zones?.length) {
        const y = H - 260;
        const w = W - 176;
        let x = 88;
        ctx.save();
        ctx.beginPath();
        ctx.roundRect(88, y, w, 40, 20);
        ctx.clip();
        d.zones.forEach((z, i) => {
            const zw = w * z.pct / 100;
            ctx.fillStyle = ZONE_COLORS[i] ?? '#8E8E93';
            ctx.fillRect(x, y, zw + 1, 40);
            x += zw;
        });
        ctx.restore();
        text(ctx, 'Zeit je Zone', 88, y + 100, { size: 40, weight: 500, color: '#8E8E93' });
    }
    return toBlob(c);
}

async function drawSticker(d) {
    const SW = 1080;
    const SH = 640;
    const [c, ctx] = canvas(SW, SH);
    ctx.shadowColor = 'rgba(0,0,0,0.45)';
    ctx.shadowBlur = 24;
    ctx.shadowOffsetY = 4;
    ctx.textAlign = 'left';

    const size = fitSize(ctx, d.big.value, d.big.unit, SW - 120, 280);
    ctx.font = `800 ${size}px ${FONT}`;
    let w = ctx.measureText(d.big.value).width;
    if (d.big.unit) {
        ctx.font = `700 ${Math.round(size * 0.24)}px ${FONT}`;
        w += 14 + ctx.measureText(d.big.unit).width;
    }
    bigValue(ctx, d.big.value, d.big.unit, (SW - w) / 2, 300, size, { unitColor: 'rgba(255,255,255,0.85)' });

    const line = d.stats.map(s => s.unit ? `${s.value} ${s.unit}` : s.value).slice(0, 2).join(' · ');
    ctx.font = `700 64px ${FONT}`;
    text(ctx, line, (SW - ctx.measureText(line).width) / 2, 420, { size: 64, weight: 700 });

    ctx.font = `800 52px ${FONT}`;
    const wm = ctx.measureText('Zone3.').width;
    wordmark(ctx, (SW - wm) / 2, 540, 52);
    return toBlob(c);
}

/**
 * @param {'photo'|'route'|'numbers'|'sticker'} template
 * @param {{ eyebrow: string, title: string, color?: string, place?: string, dateShort?: string,
 *           big: { value: string, unit?: string },
 *           stats: Array<{ value: string, unit?: string, label: string }>,
 *           extra?: Array<{ value: string, label: string }>,
 *           zones?: Array<{ pct: number }>, coords?: Array<[number, number]> }} data
 * @param {string|null} photo  eigenes Foto als Object-URL (sonst `data.photo`, die Vorlage der Sportart)
 * @returns {Promise<Blob>}
 */
export async function drawRunStory(template, data, photo = null) {
    await fonts();
    if (template === 'route') return drawRoute(data);
    if (template === 'numbers') return drawNumbers(data);
    if (template === 'sticker') return drawSticker(data);
    return drawPhoto(data, photo);
}
