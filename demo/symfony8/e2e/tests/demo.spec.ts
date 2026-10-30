import { test, expect } from '@playwright/test';
import { loginAsAdmin } from './helpers';

/** REQ-DEMO-013 — Translation YAML Tools demo happy path (insights page → missing-log Web UI → text-overrides desk). */
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

  test('missing-log Web UI switches status tabs (UiKit list page)', async ({ page }) => {
    await page.goto('/');
    await loginAsAdmin(page);
    const list = page.getByTestId('tyt-missing-log');
    await expect(list).toHaveAttribute('data-status', 'pending');
    await page.getByTestId('tyt-missing-log-status-added').click();
    await expect(page.getByTestId('tyt-missing-log')).toHaveAttribute('data-status', 'added');
    await expect(page.getByTestId('tyt-missing-log-clear-all')).toBeVisible();
  });

  test('text-overrides desk edits a message and restores the shipped text behind the UiKit confirm dialog', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/_demo/translation-yaml-tools/texts/?q=demo.subtitle');
    await expect(page.getByTestId('tyt-overrides-index').locator('table')).toContainText('demo.subtitle');
    await page.getByTestId('tyt-overrides-index').getByRole('link', { name: 'Edit' }).first().click();

    await expect(page.getByTestId('tyt-overrides-key')).toHaveText('demo.subtitle');
    await page.locator('textarea[name="texts[en]"]').fill('Default locale is English (edited in the desk)');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.nowo-ui-flash--success')).toContainText('Text saved.');

    await page.getByTestId('tyt-overrides-reset').click();
    const dialog = page.locator('dialog[open]');
    await expect(dialog).toContainText('Restore the shipped text?');
    await dialog.getByRole('button', { name: 'Restore shipped text' }).click();
    await expect(page.locator('.nowo-ui-flash--success')).toContainText('Shipped text restored.');
    await expect(page.getByTestId('tyt-overrides-reset')).toHaveCount(0);
  });
});
