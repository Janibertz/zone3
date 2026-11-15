// Meldet das Testkonto einmal an und merkt sich die Sitzung für alle Tests.
import { test as setup, expect } from '@playwright/test';

export const STATE = 'test-results/.auth/testdev.json';

setup('Testkonto anmelden', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('button', { name: 'Anmelden' }).click();
    await expect(page.locator('#email')).toBeVisible();
    await page.locator('#email').fill('testdev@zone3.test');
    await page.locator('#password').fill('testdev-passwort');
    await page.locator('#password').press('Enter');
    await page.waitForURL(/\/dashboard/);
    await page.context().storageState({ path: STATE });
});
