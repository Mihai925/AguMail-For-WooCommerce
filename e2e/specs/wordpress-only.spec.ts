import { expect, test, type Browser, type Page } from '@playwright/test';
import { adminContext, openSettings, section, settingsNotice } from '../helpers/admin';
import { lastLine, ordersFor, resetPluginQueue, sentRecords, startV2 } from '../helpers/agumail';
import { buildersOff, buildersOn, readBuilders } from '../helpers/builders';
import { placeGuestOrder, uniqueEmail } from '../helpers/checkout';
import { E2E_API_KEY, FAKE_INTERNAL_URL, SITE_URL, writeStateConfig } from '../helpers/env';
import { fakeControl, fakeRequests, fakeReset } from '../helpers/fake';
import { drainQueue, setCheckout, wp, wpEval } from '../helpers/wp';

// The plugin on a WordPress site without WooCommerce, then the same site turning WooCommerce on:
//   - activating it lands on the AguMail page, its own item in the admin menu, with no store sections;
//   - the site connects with an API key, checked with ping alone (no store is created in AguMail);
//   - a form and a Contact Form 7 signup reach AguMail, sent by the Action Scheduler the plugin bundles;
//   - with WooCommerce activated, nothing stops, the store is offered store sync, and once it presses
//     Sync your store its orders reach AguMail.
// The store ends with WooCommerce on and connected to the fake, as every other spec expects.

test.describe.configure({ mode: 'serial' });

const PLUGIN_ROW = 'tr[data-plugin="agumail/agumail.php"]';

async function disconnect(): Promise<void> {
  await wpEval(
    "foreach (array('agumail_api_key', 'agumail_config', 'agumail_connection', 'agumail_connected_at', 'agumail_forms_cache', 'agumail_connect_notice_dismissed', 'agumail_store_sync_refused', 'agumail_store_sync_available', 'agumail_store_notice') as $o) delete_option($o); delete_transient('agumail_ping_retry'); delete_transient('agumail_activation_redirect');",
  );
}

async function woocommerce(on: boolean): Promise<void> {
  if (on) {
    await wp('plugin', 'activate', 'woocommerce');
  } else {
    await wp('plugin', 'deactivate', 'checkout-for-woocommerce', 'woocommerce').catch(() => '');
  }
}

async function asVisitor<T>(browser: Browser, fn: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    return await fn(page);
  } finally {
    await context.close();
  }
}

test.beforeAll(async () => {
  await startV2();
  await woocommerce(false);
  writeStateConfig({ apiUrl: FAKE_INTERNAL_URL });
  await disconnect();
  await resetPluginQueue();
  await fakeReset();
});

test.afterAll(async () => {
  await buildersOff().catch(() => undefined);
  await woocommerce(true);
  await wpEval("delete_option('agumail_connect_notice_dismissed'); delete_transient('agumail_activation_redirect');");
  await startV2();
});

test('activating on a site without WooCommerce lands on the AguMail page, with no store sections', async ({ browser }) => {
  expect(lastLine(await wpEval("echo class_exists('WooCommerce') ? 'woo' : 'plain';"))).toBe('plain');
  await wp('plugin', 'deactivate', 'agumail');
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/plugins.php`);
    await page.locator(`${PLUGIN_ROW} .row-actions .activate a`).click();
    await page.waitForURL(/\/wp-admin\/admin\.php\?page=agumail$/);
    await expect(page.locator('.agumail-settings h1')).toHaveText('AguMail');
    await expect(page.locator('#toplevel_page_agumail')).toBeVisible();

    for (const id of ['connection', 'accounts', 'tracking', 'forms', 'status']) {
      await expect(section(page, id), `the ${id} section`).toHaveCount(1);
    }
    for (const id of ['checkout', 'sync']) {
      await expect(section(page, id), `a store's ${id} section`).toHaveCount(0);
    }
    await expect(section(page, 'accounts').locator('h2')).toHaveText('Registration');

    const connection = section(page, 'connection');
    const signup = connection.getByRole('link', { name: /Create your free AguMail account/ });
    expect(await signup.getAttribute('href')).toContain('/signup?utm_source=wordpress&utm_medium=plugin&utm_campaign=connect-welcome');
    await expect(connection.getByRole('button', { name: 'Connect to AguMail', exact: true })).toHaveCount(0);

    // Elsewhere in wp-admin, a notice leads here and to a free account.
    await page.goto(`${SITE_URL}/wp-admin/index.php`);
    const notice = page.locator('.agumail-connect-notice');
    await expect(notice).toContainText('Grow your email list with AguMail');
    await expect(notice.getByRole('link', { name: 'Get started' })).toHaveAttribute('href', `${SITE_URL}/wp-admin/admin.php?page=agumail`);
  } finally {
    await admin.close();
  }
});

test('the site connects with an API key, checked with ping alone', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    const connection = section(page, 'connection');
    await connection.locator('#agumail_connect_key').fill(E2E_API_KEY);
    await connection.locator('.agumail-key-form button[type="submit"]').click();
    await page.waitForURL(/page=agumail/);
    // Storefront, the theme here, adds a notice of its own while WooCommerce is off.
    await expect(page.locator('.agumail-settings .notice p', { hasText: 'Connected to AguMail as AguMail E2E Shop.' })).toHaveCount(1);
    await expect(section(page, 'connection')).toContainText('Connected');
  } finally {
    await admin.close();
  }
  const pings = await fakeRequests({ path: '/api/v1/ping' });
  expect(pings.length).toBeGreaterThan(0);
  expect(pings.every((ping) => !ping.query.platform), 'ping asked about a store the site does not have').toBe(true);
  expect(await fakeRequests({ path: '/api/v1/connect', method: 'POST' })).toEqual([]);
  expect(lastLine(await wpEval("echo agumail_connection_platform();"))).toBe('wordpress');

  // The Action Scheduler running AguMail's jobs is the copy the plugin bundles.
  const scheduler = lastLine(await wpEval("echo (new ReflectionClass('ActionScheduler'))->getFileName();"));
  expect(scheduler).toContain('/plugins/agumail/vendor/woocommerce/action-scheduler/');
});

test('a form shows and a Contact Form 7 signup reaches AguMail through the bundled Action Scheduler', async ({ browser }) => {
  const pageId = lastLine(await wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=AguMail form', '--post_content=[agumail_form slug="newsletter"]', '--porcelain'));
  const pageUrl = lastLine(await wpEval(`echo get_permalink(${Number(pageId)});`));
  await asVisitor(browser, async (page) => {
    await page.goto(pageUrl);
    await expect(page.locator('script[data-agumail-form="newsletter"]')).toHaveCount(1);
  });

  await buildersOn();
  const builders = readBuilders();
  const email = uniqueEmail('wp-only-cf7');
  await asVisitor(browser, async (page) => {
    await page.goto(builders.cf7.url);
    const form = page.locator('form.wpcf7-form');
    await form.locator('[name="your-name"]').fill('Cy Ray');
    await form.locator('[name="your-email"]').fill(email);
    await form.locator('[name="newsletter"]').check();
    await form.locator('[type="submit"]').click();
    await expect(form).toHaveAttribute('data-status', /^(sent|failed)$/);
  });
  await drainQueue();
  const signups = await sentRecords<{ email: string; form: { builder: string } }>('/api/v1/signups', 'signups', (s) => s.email === email);
  expect(signups.length).toBe(1);
  expect(signups[0].record.form.builder).toBe('cf7');
  await buildersOff();
});

test('turning WooCommerce on keeps everything going and offers store sync, which then sends orders', async ({ browser }) => {
  await woocommerce(true);
  await setCheckout('classic');
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    const connection = section(page, 'connection');
    await expect(connection).toContainText('Connected for forms and signups.');
    // The store's own sections come back with WooCommerce, but no store data sync before store sync.
    await expect(section(page, 'checkout')).toHaveCount(1);
    await expect(section(page, 'sync')).toHaveCount(0);

    await connection.getByRole('button', { name: 'Sync your store', exact: true }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Your store is connected. Orders, carts and products sync from now on.');
    await expect(section(page, 'connection')).toContainText('Sync store data now');
    await expect(section(page, 'sync')).toHaveCount(1);
  } finally {
    await admin.close();
  }
  expect(lastLine(await wpEval("echo agumail_connection_platform();"))).toBe('woocommerce');
  const connects = await fakeRequests({ path: '/api/v1/connect', method: 'POST' });
  expect(connects.length).toBe(1);
  expect((connects[0].body as { platform: string }).platform).toBe('woocommerce');

  const order = await asVisitor(browser, (shopper) => placeGuestOrder(shopper, { checkout: 'classic', email: uniqueEmail('wp-then-woo'), optIn: true, paymentMethod: 'cod' }));
  await drainQueue();
  expect((await ordersFor(order.orderId)).length).toBe(1);
});
