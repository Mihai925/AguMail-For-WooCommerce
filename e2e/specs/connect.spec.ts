import { expect, test, type Page } from '@playwright/test';
import { adminContext, openSettings, section, settingsNotice } from '../helpers/admin';
import { expectNoShopperCalls, fakeSeq, lastLine, startV2 } from '../helpers/agumail';
import { E2E_API_KEY, FAKE_URL, SITE_URL } from '../helpers/env';
import { fakeControl, fakeRequests, fakeState } from '../helpers/fake';
import { wpEval } from '../helpers/wp';

// Connecting the store, as a merchant does it from WooCommerce > AguMail:
//   - one click: "Connect to AguMail" goes to AguMail's approval page (the fake app), which sends the
//     merchant back with a code; the plugin trades it (with its PKCE verifier) for an API key;
//   - Cancel on the approval page comes back with error=access_denied and connects nothing;
//   - an expired code, and a callback replayed after it was used, connect nothing;
//   - Advanced: a pasted API key is saved and checked with POST /api/v1/connect.

test.describe.configure({ mode: 'serial' });

async function disconnect(): Promise<void> {
  await wpEval(
    "foreach (array('agumail_api_key', 'agumail_config', 'agumail_connection', 'agumail_connected_at', 'agumail_forms_cache') as $o) delete_option($o); delete_transient('agumail_ping_retry');",
  );
}

async function savedKey(): Promise<string> {
  return lastLine(await wpEval("echo (string) get_option('agumail_api_key', '');"));
}

async function clickConnect(page: Page): Promise<void> {
  await openSettings(page);
  await section(page, 'connection').getByRole('button', { name: 'Connect to AguMail', exact: true }).click();
}

test.beforeAll(async () => {
  await startV2();
});

let since = 0;

test.beforeEach(async () => {
  await disconnect();
  since = await fakeSeq();
  await fakeControl({ connectPage: 'approve', codeTtlMs: 10 * 60 * 1000, apiKey: null });
});

test.afterAll(async () => {
  await fakeControl({ connectPage: 'approve', codeTtlMs: 10 * 60 * 1000, apiKey: null });
  // Leave the store connected for the specs after this one.
  await startV2();
});

test('one click: approve on AguMail and come back connected, with the sync offered', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  const visited: string[] = [];
  // Every document request, the 302 hops included.
  page.on('request', (request) => {
    if (request.isNavigationRequest()) visited.push(request.url());
  });
  try {
    await clickConnect(page);
    await page.waitForURL(/page=agumail/);
    // It went through AguMail's approval page with the store, the way back, the state and the challenge.
    const approval = (await fakeRequests({ path: '/connect/woocommerce', since })).pop();
    expect(approval, 'the approval page was opened').toBeTruthy();
    expect(approval!.query.store).toBe(SITE_URL);
    expect(approval!.query.return).toBe(`${SITE_URL}/wp-admin/admin.php?page=agumail&agumail_connect=callback`);
    expect(approval!.query.state).toMatch(/^[0-9a-f]{32}$/);
    expect(approval!.query.challenge).toMatch(/^[A-Za-z0-9_-]{43}$/);
    // The code came back and was traded for a key; the address bar no longer carries it.
    expect(visited.some((u) => u.includes('agumail_connect=callback') && u.includes('code='))).toBe(true);
    expect(page.url()).not.toContain('code=');
    expect(await settingsNotice(page)).toBe('Connected to AguMail as AguMail E2E Shop.');
    const connection = section(page, 'connection');
    await expect(connection).toContainText('AguMail E2E Shop');
    await expect(connection).toContainText('Connected');
    await expect(connection.getByRole('button', { name: 'Sync store data now' })).toBeVisible();
  } finally {
    await admin.close();
  }
  const exchange = await fakeRequests({ path: '/api/v1/connect/exchange', since });
  expect(exchange.length).toBe(1);
  expect(exchange[0].status).toBe(200);
  const body = exchange[0].body as { code: string; verifier: string; storeUrl: string; currency: string };
  expect(body.storeUrl).toBe(SITE_URL);
  expect(body.currency).toBe('USD');
  expect(body.verifier.length).toBeGreaterThanOrEqual(43);
  expect(exchange[0].headers.authorization, 'the exchange sends no key').toBeUndefined();
  const state = await fakeState();
  expect(await savedKey()).toBe(state.lastIssuedKey);
  // The config from the answer is kept for the site script and the forms.
  const config = JSON.parse(lastLine(await wpEval("echo wp_json_encode(get_option('agumail_config'));")));
  expect(config.siteKey).toBe(state.siteKey);
  expect(config.formsLoaderUrl).toBe(`${FAKE_URL}/embed/agumail-forms.js`);
  expectNoShopperCalls();
});

test('Cancel on the approval page comes back with nothing connected', async ({ browser }) => {
  await fakeControl({ connectPage: 'show' });
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await clickConnect(page);
    await expect(page.locator('#agumail-connect-store')).toHaveText('localhost:8089');
    await page.locator('#agumail-connect-cancel').click();
    await page.waitForURL(/page=agumail/);
    expect(page.url()).not.toContain('error=');
    expect(await settingsNotice(page)).toBe("Connection cancelled. Press Connect to AguMail when you're ready.");
    await expect(section(page, 'connection').getByRole('button', { name: 'Connect to AguMail', exact: true })).toBeVisible();
  } finally {
    await admin.close();
  }
  expect(await savedKey()).toBe('');
  expect(await fakeRequests({ path: '/api/v1/connect/exchange', since })).toEqual([]);
});

test('an expired code, and a callback used twice, connect nothing', async ({ browser }) => {
  // AguMail refuses a code past its ten minutes.
  await fakeControl({ connectPage: 'show', codeTtlMs: -1000 });
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await clickConnect(page);
    await page.locator('#agumail-connect-approve').click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('This connection link has expired. Start again from WordPress.');
    expect(await savedKey()).toBe('');

    // A good code, approved and used once; opening the same way back again finds nothing to finish.
    await fakeControl({ connectPage: 'show', codeTtlMs: 10 * 60 * 1000 });
    await clickConnect(page);
    const approve = await page.locator('#agumail-connect-approve').getAttribute('href');
    await page.locator('#agumail-connect-approve').click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Connected to AguMail as AguMail E2E Shop.');
    const key = await savedKey();
    expect(key).toMatch(/^agm_[0-9a-f]{40}$/);
    await page.goto(approve!);
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('This connection link has expired. Press Connect to AguMail to start again.');
    expect(await savedKey(), 'the replay changed nothing').toBe(key);
  } finally {
    await admin.close();
  }
  const exchanges = await fakeRequests({ path: '/api/v1/connect/exchange', since });
  expect(exchanges.map((r) => r.status)).toEqual([400, 200]);
});

test('Advanced: a pasted API key is saved and checked with AguMail', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    // A key AguMail refuses: nothing is kept.
    await fakeControl({ apiKey: E2E_API_KEY });
    await openSettings(page);
    const advanced = section(page, 'connection').locator('details.agumail-advanced');
    await advanced.locator('summary').click();
    // The API address is pinned by wp-config.php in this stack, so only the key is asked for.
    await expect(advanced).toContainText('Set in wp-config.php.');
    await advanced.locator('#agumail_api_key').fill('agm_ffffffffffffffffffffffffffffffffffffffff');
    await advanced.getByRole('button', { name: 'Save and connect' }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Missing or malformed API key (Authorization: Bearer agm_…).');
    expect(await savedKey()).toBe('');

    // The right key.
    await section(page, 'connection').locator('details.agumail-advanced summary').click();
    await section(page, 'connection').locator('#agumail_api_key').fill(E2E_API_KEY);
    await section(page, 'connection').getByRole('button', { name: 'Save and connect' }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Connected to AguMail as AguMail E2E Shop.');
    await expect(section(page, 'connection')).toContainText('Connected');
  } finally {
    await admin.close();
  }
  expect(await savedKey()).toBe(E2E_API_KEY);
  const connects = await fakeRequests({ path: '/api/v1/connect', method: 'POST', since });
  const calls = connects.filter((r) => r.path === '/api/v1/connect');
  expect(calls.map((r) => r.status)).toEqual([401, 200]);
  expect(calls[1].body).toEqual({ platform: 'woocommerce', storeUrl: SITE_URL, currency: 'USD' });
  expectNoShopperCalls();
});
