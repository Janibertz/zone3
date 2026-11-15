// Meldet das Testkonto einmal an und merkt sich die Sitzung für alle Tests.
import { test as setup, expect } from '@playwright/test';

export const STATE = 'test-results/.auth/testdev.json';

setup('Testkonto anmelden', async ({ page }) => {
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    // Am 15.11.2026 auf GitHub zweimal gesehen: der Tipp auf „Anmelden“ kam an,
    // das Passwortfeld aber nicht oder verschwand wieder (die Seite wurde nach
    // dem Klick noch einmal aufgebaut). Darum: klicken, bis beide Felder stehen.
    await expect(async () => {
        if (!(await page.locator('#password').isVisible())) {
            await page.getByRole('button', { name: 'Anmelden', exact: true }).click({ timeout: 2_000 });
        }
        await expect(page.locator('#email')).toBeVisible({ timeout: 1_000 });
        await expect(page.locator('#password')).toBeVisible({ timeout: 1_000 });
    }).toPass({ timeout: 20_000 });

    await page.locator('#email').fill('testdev@zone3.test');
    await page.locator('#password').fill('testdev-passwort');
    await page.locator('#password').press('Enter');
    await page.waitForURL(/\/dashboard/);
    await page.context().storageState({ path: STATE });
});
