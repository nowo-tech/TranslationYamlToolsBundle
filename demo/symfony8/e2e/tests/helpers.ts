import { expect, type Page } from '@playwright/test';

/** Demo credentials (in-memory user, local demo only — see config/packages/security.yaml). */
export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/login');
  await page.fill('input[name="_username"]', 'admin');
  await page.fill('input[name="_password"]', 'admin');
  await page.getByRole('button', { name: 'Sign in' }).click();
  await expect(page).toHaveURL(/\/_demo\/translation-yaml-tools\/missing-log/);
}

/** README screenshots never show the Symfony Web Debug Toolbar. */
export async function hideToolbar(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sf-toolbar, .sf-minitoolbar, [id^="sfwdt"] { display: none !important; }' });
}
