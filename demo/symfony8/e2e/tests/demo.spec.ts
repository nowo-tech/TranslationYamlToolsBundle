import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers';

/** REQ-DEMO-013 — Translation YAML Tools demo happy path (insights page → missing-log Web UI). */
test.describe('Translation YAML Tools demo', () => {
  test('insights page reports locales, YAML paths and key gaps', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.getByRole('heading', { level: 1, name: /Translation YAML Tools/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Resolved translation directories' })).toBeVisible();
    await expect(page.getByRole('heading', { name: /Domains & YAML files/ })).toBeVisible();
    await expect(page.locator('main table').first()).toBeVisible();
  });

  test('missing-log Web UI lists keys requested by the demo', async ({ page }) => {
    // Visiting the home page records missing keys (controller probes) in the log.
    await page.goto('/');
    await loginAsAdmin(page);
    await expect(page.locator('table').getByText('nowo_demo.missing_from_controller').first()).toBeVisible();
  });
});
