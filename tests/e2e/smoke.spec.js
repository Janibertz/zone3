// Smoke-Tests der Kernseiten mit dem Testkonto (php artisan testdev:create),
// angemeldet über auth.setup.js. Jede Seite: kein Serverfehler, kein
// JS-Fehler in der Konsole, und das Kernelement ist sichtbar.
import { test, expect } from '@playwright/test';

// Fehler, die nichts mit der App zu tun haben: Kartenkacheln von außen sind im
// Testlauf nicht erreichbar oder werden beim Seitenwechsel abgebrochen.
const IGNORED = [
    /openfreemap\.org/,
    /Failed to load resource: net::ERR_/,
];

let problems;

test.beforeEach(async ({ page }) => {
    problems = [];
    page.on('pageerror', (e) => problems.push(`JS-Fehler: ${e.message}`));
    page.on('console', (msg) => {
        if (msg.type() !== 'error') return;
        if (IGNORED.some((re) => re.test(msg.text()))) return;
        problems.push(`Konsole: ${msg.text()}`);
    });
    page.on('response', (res) => {
        if (res.status() >= 500) problems.push(`HTTP ${res.status()}: ${res.url()}`);
    });
    // Der Hinweis „Zone3 als App“ liegt am iPhone über der Tab-Leiste.
    await page.addInitScript(() => localStorage.setItem('pwa_install_dismissed_at', String(Date.now())));
});

test.afterEach(async () => {
    expect(problems, problems.join('\n')).toEqual([]);
});

/** Sichtbarer Text — Seitenleiste (Desktop) und Tab-Leiste (iPhone) gibt es beide im DOM. */
const visible = (page, text) => page.getByText(text).filter({ visible: true }).first();

/**
 * Tippen, bis es wirkt. Auf GitHub und lokal ging gelegentlich ein Tipp direkt
 * nach dem Laden verloren (die Seite baute sich noch einmal auf) — einmal
 * klicken hiess dann 30 s warten und rot. Erst wenn `done` stimmt, gilt es.
 */
async function tapUntil(page, target, done) {
    await page.waitForLoadState('networkidle');
    await expect(async () => {
        if (!(await done())) await target().click({ timeout: 2_000 });
        expect(await done()).toBe(true);
    }).toPass({ timeout: 20_000, intervals: [500, 1_000, 2_000] });
}

test('Heute zeigt die Einheit des Tages', async ({ page }) => {
    await page.goto('/dashboard');
    await expect(page.getByRole('region', { name: /^Heute:/ })).toBeVisible();
    await expect(visible(page, 'Erste Schritte')).toBeVisible();
});

test('Details der Einheit zeigen den Ablauf', async ({ page }) => {
    await page.goto('/dashboard');
    await tapUntil(page, () => page.getByRole('link', { name: 'Details' }).first(),
        async () => /\/events\/\d+\/plan/.test(page.url()));
    const sheet = page.getByRole('dialog').filter({ visible: true });
    await expect(sheet.getByText('Ablauf')).toBeVisible();
    await expect(sheet.getByText('Kein Training')).toBeVisible();
});

test('Plan zeigt Rennen und Wochen', async ({ page }) => {
    await page.goto('/plan');
    await expect(visible(page, 'Testdev-Halbmarathon')).toBeVisible();
    await expect(visible(page, 'Diese Woche')).toBeVisible();
});

test('Kalender zeigt den Monat', async ({ page }) => {
    await page.goto('/calendar');
    const month = new Date().toLocaleString('de-DE', { month: 'long', timeZone: 'Europe/Berlin' });
    await expect(page.getByRole('heading', { name: month }).filter({ visible: true }).first()).toBeVisible();
});

test('Aktivitäten listen die Läufe und öffnen einen', async ({ page }) => {
    await page.goto('/activities');
    await tapUntil(page, () => visible(page, 'Langer Lauf'),
        async () => /\/activities\/\d+/.test(page.url()));
    await expect(visible(page, /16,1/)).toBeVisible();
});

test('Coach-Chat öffnet mit der Nachricht', async ({ page }) => {
    await page.goto('/dashboard');
    await tapUntil(page, () => page.getByRole('button', { name: /Chat mit/ }).filter({ visible: true }).first(),
        () => visible(page, /Willkommen im Testkonto/).isVisible());
});

test('Tagesform öffnet sich', async ({ page }) => {
    await page.goto('/dashboard');
    await tapUntil(page, () => page.getByRole('button', { name: 'Tagesform eintragen' }).filter({ visible: true }),
        () => page.getByRole('dialog').filter({ visible: true }).first().isVisible());
});

test('Mehr zeigt die Einstellungen', async ({ page }) => {
    await page.goto('/mehr');
    await expect(visible(page, 'Verbindungen')).toBeVisible();
});

test('Geräte & Apps zeigt die Kacheln', async ({ page }) => {
    await page.goto('/mehr/geraete');
    await expect(visible(page, /Garmin/i)).toBeVisible();
});

test('Profil lädt', async ({ page }) => {
    await page.goto('/profile');
    await expect(visible(page, /Athletenprofil/)).toBeVisible();
});

test('Rennen zeigen das Ziel', async ({ page }) => {
    await page.goto('/events');
    await expect(visible(page, 'Testdev-Halbmarathon')).toBeVisible();
});
