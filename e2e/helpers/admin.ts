import fs from 'node:fs';
import path from 'node:path';
import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test';
import { SITE_URL, STATE_DIR } from './env';

const STORAGE = path.join(STATE_DIR, 'admin-storage.json');

export const SETTINGS_URL = `${SITE_URL}/wp-admin/admin.php?page=agumail`;

/** Logs in as admin / admin on this page's context. */
export async function loginAdmin(page: Page): Promise<void> {
  await page.goto(`${SITE_URL}/wp-login.php`);
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'admin');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

/**
 * A browser context signed in as the store admin. The session cookie is kept in .state so each spec
 * does not log in again; a stale one is replaced.
 */
export async function adminContext(browser: Browser): Promise<BrowserContext> {
  if (fs.existsSync(STORAGE)) {
    const context = await browser.newContext({ storageState: STORAGE });
    const page = await context.newPage();
    const res = await page.goto(`${SITE_URL}/wp-admin/profile.php`);
    const ok = res?.ok() && page.url().includes('/wp-admin/profile.php');
    await page.close();
    if (ok) return context;
    await context.close();
  }
  const context = await browser.newContext();
  const page = await context.newPage();
  await loginAdmin(page);
  await context.storageState({ path: STORAGE });
  await page.close();
  return context;
}

/** Opens WooCommerce > AguMail. */
export async function openSettings(page: Page, query = ''): Promise<void> {
  await page.goto(SETTINGS_URL + query);
  await expect(page.locator('.agumail-settings h1')).toHaveText('AguMail');
}

/**
 * The notice the settings page shows after an action, or '' when none. WordPress moves every admin
 * notice (Action Scheduler's, WooCommerce's) up next to the page title, so only the plugin's own
 * dismissible one, printed right under its title, counts.
 */
export async function settingsNotice(page: Page): Promise<string> {
  const notice = page.locator('.agumail-settings > h1 + .notice.is-dismissible p, .agumail-settings > .notice.is-dismissible p').filter({ hasNotText: /^Action Scheduler:/ }).first();
  return (await notice.count()) ? ((await notice.textContent()) ?? '').trim() : '';
}

/** A settings section by its id (connection, sync, checkout, accounts, forms, tracking, status). */
export function section(page: Page, id: string) {
  return page.locator(`#agumail-section-${id}`);
}
