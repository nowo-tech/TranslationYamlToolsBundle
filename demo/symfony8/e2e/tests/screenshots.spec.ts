import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { loginAsAdmin, waitForToolbar } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('Translation YAML Tools screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('translation-insights — locales, YAML paths and missing keys per domain', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: /Domains & YAML files/ })).toBeVisible();
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'translation-insights.png') });
  });

  test('missing-log-ui — runtime missing keys recorded by the translator', async ({ page }) => {
    // Wide viewport: the log table has many columns (call site, route, path…).
    await page.setViewportSize({ width: 1920, height: 1000 });
    await page.goto('/');
    await page.goto('/missing-log/probes/repeat-hits');
    await loginAsAdmin(page);
    await expect(page.locator('table').getByText('nowo_demo.missing_from_controller').first()).toBeVisible();
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'missing-log-ui.png') });
  });

  test('text-overrides — operator edits a catalogue message per locale', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/_demo/translation-yaml-tools/texts/edit?key=demo.subtitle');
    await page.locator('textarea[name="texts[en]"]').fill('Default locale is English — edited from the text-overrides desk');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.locator('.nowo-ui-flash--success')).toBeVisible();
    await waitForToolbar(page);
    await page.screenshot({ path: resolve(outDir, 'text-overrides.png'), fullPage: true });
    // Leave the demo as it was (restore through the UiKit confirm dialog).
    await page.getByTestId('tyt-overrides-reset').click();
    await page.locator('dialog[open]').getByRole('button', { name: 'Restore shipped text' }).click();
    await expect(page.locator('.nowo-ui-flash--success')).toContainText('Shipped text restored.');
  });
});
