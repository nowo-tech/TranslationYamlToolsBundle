import { expect, type Page } from '@playwright/test';

/** Demo credentials (in-memory user, local demo only — see config/packages/security.yaml). */
export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/login');
  await page.fill('input[name="_username"]', 'admin');
  await page.fill('input[name="_password"]', 'admin');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/_demo\/translation-yaml-tools\/missing-log/);
}

/** Wait for the Symfony WebProfiler toolbar (dev) so it is part of the capture. */
export async function waitForToolbar(page: Page): Promise<void> {
  const toolbar = page.locator('.sf-toolbar');
  if (await toolbar.count()) {
    await expect(toolbar.locator('.sf-toolbarreset, .sf-toolbar-block').first()).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(400);
  }
}
