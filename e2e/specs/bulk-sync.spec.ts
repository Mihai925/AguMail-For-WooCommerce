import { expect, test } from '@playwright/test';
import { adminContext, openSettings, section, settingsNotice } from '../helpers/admin';
import { expectNoShopperCalls, lastLine, resetPluginQueue, sentRecords, type ContactRecord, type OrderRecord, type ProductRecord } from '../helpers/agumail';
import { readSeed } from '../helpers/env';
import { fakeRequests, fakeReset, fakeState } from '../helpers/fake';
import { resetAudit } from '../helpers/audit';
import { activatePlugin, runQueue, wp, wpEval } from '../helpers/wp';

// "Sync store data now" on a store with 1,200 past orders (./wp-setup.sh seed), run from the admin
// right after a one-click connect, with the queue driven until the sync is done:
//   - every request says backfill: true (history never starts a flow);
//   - every contact carries customerSince; consentedAt only for those who opted in;
//   - subscribers go first;
//   - the counts the settings page shows are exactly what AguMail (the fake) received.

const SEED_COUNT = 1200;
const SEEDED = /^shopper(\d{4})@e2e\.agumail\.test$/;

test.describe.configure({ mode: 'serial' });

type BulkState = { status: string; phase: string; totals: Record<string, number>; done: Record<string, number>; contacts: Record<string, number>; orders: Record<string, number>; products: { upserted: number }; error: string };

async function bulkState(): Promise<BulkState> {
  return JSON.parse(lastLine(await wpEval("echo wp_json_encode(agumail_bulk_state());")));
}

test.beforeAll(async () => {
  test.setTimeout(20 * 60_000);
  // Only the seeded history: the orders other specs placed are removed, with no AguMail plugin active
  // so nothing hears of it, and the 1,200 are converged by the seed itself.
  await activatePlugin('none');
  await wpEval(
    "$ids = wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => array_merge(array_keys(wc_get_order_statuses()), array('checkout-draft', 'trash')), 'type' => 'shop_order')); $n = 0; foreach ($ids as $id) { $o = wc_get_order($id); if ($o && $o->get_meta('_agumail_e2e_seed') === '') { $o->delete(true); $n++; } elseif ($o && $o->get_meta('_agumail_synced') !== '') { $o->delete_meta_data('_agumail_synced'); $o->save_meta_data(); } } echo $n;",
  );
  await wpEval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->usermeta} WHERE meta_key = '_agumail_optin_at'\");");
  await wp('eval-file', '/e2e/wp/seed.php', String(SEED_COUNT));
  await activatePlugin('v2');
  await resetPluginQueue();
  await wpEval(
    "foreach (array('agumail_bulk_state', 'agumail_bulk_cancel', 'agumail_api_key', 'agumail_config', 'agumail_connection', 'agumail_connected_at', 'agumail_last_sent', 'agumail_reverse_sync_cursor') as $o) delete_option($o);",
  );
  await fakeReset();
  resetAudit();
});

test('connect in one click, then Sync store data now, and drive the queue until it is done', async ({ browser }) => {
  test.setTimeout(20 * 60_000);
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    await section(page, 'connection').getByRole('button', { name: 'Connect to AguMail', exact: true }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Connected to AguMail as AguMail E2E Shop.');
    await section(page, 'connection').getByRole('button', { name: 'Sync store data now' }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Store data sync started. It runs in the background, so you can leave this page.');
    const panel = page.locator('[data-agumail-bulk]');
    await expect(panel).toHaveAttribute('data-status', 'running');
    await expect(panel).toContainText('Syncing store data.');
  } finally {
    await admin.close();
  }

  const started = await bulkState();
  const seed = readSeed();
  expect(started.totals.subscribers).toBe(seed.optedInRegistered);
  expect(started.totals.checkout_subscribers).toBe(seed.optedInGuests);
  expect(started.totals.orders).toBe(seed.paidOrders);

  // Cron would run the steps one after another; here each run takes every step that is due.
  let state = started;
  for (let round = 0; round < 40 && state.status === 'running'; round++) {
    await runQueue({ dueNow: true });
    state = await bulkState();
  }
  expect(state.status, state.error).toBe('done');
  expectNoShopperCalls();
});

test('every request was history, contacts first, and each contact carries the right dates', async () => {
  const seed = readSeed();
  const records = await fakeRequests({ method: 'POST' });
  const bulk = records.filter((r) => ['/api/v1/contacts', '/api/v1/orders', '/api/v1/products'].includes(r.path));
  expect(bulk.length).toBeGreaterThan(0);
  for (const r of bulk) {
    expect((r.body as { backfill?: boolean }).backfill, `${r.path} request ${r.seq} without backfill: true`).toBe(true);
    expect(r.status).toBe(200);
  }

  const contacts = (await sentRecords<ContactRecord>('/api/v1/contacts', 'contacts')).map((s) => s.record);
  const now = Date.now();
  for (const c of contacts) {
    expect(c.customerSince, `${c.email} has no customerSince`).toBeTruthy();
    // Real dates from the store: registration or the first order, all at least a day old here.
    expect(Date.parse(c.customerSince!), `${c.email} customerSince ${c.customerSince}`).toBeLessThan(now - 23 * 3600 * 1000);
  }
  const opted = contacts.filter((c) => c.consentedAt);
  for (const c of contacts) {
    const i = Number(SEEDED.exec(c.email)?.[1]);
    const shouldOptIn = i % 4 === 0;
    if (shouldOptIn) {
      expect(c.consent, `${c.email} opted in`).toEqual({ optedIn: true, source: 'bulk_sync' });
      expect(c.consentedAt, `${c.email} opted in, so it has consentedAt`).toBeTruthy();
      expect(Date.parse(c.consentedAt!)).toBeLessThanOrEqual(now);
    } else {
      expect(c.consentedAt, `${c.email} never opted in`).toBeUndefined();
      expect(c.consent, `${c.email} claims no consent`).toBeUndefined();
    }
  }
  expect(opted.length).toBe(seed.optedInRegistered + seed.optedInGuests);
  // Subscribers first: every opted-in contact went before the first one who did not opt in.
  const firstOther = contacts.findIndex((c) => !c.consentedAt);
  const lastOpted = contacts.map((c) => Boolean(c.consentedAt)).lastIndexOf(true);
  expect(lastOpted, 'an opted-in contact went after the others').toBeLessThan(firstOther);
  // And every contact went before any order.
  const firstOrder = bulk.find((r) => r.path === '/api/v1/orders')!.seq;
  const lastSubscriberRequest = bulk.filter((r) => r.path === '/api/v1/contacts' && ((r.body as { contacts: ContactRecord[] }).contacts ?? []).some((c) => c.consentedAt)).pop()!.seq;
  expect(lastSubscriberRequest).toBeLessThan(firstOrder);
});

test('the counts on the settings page are exactly what AguMail received', async ({ browser }) => {
  const seed = readSeed();
  const state = await bulkState();
  const fake = await fakeState();
  const contacts = await sentRecords<ContactRecord>('/api/v1/contacts', 'contacts');
  const orders = await sentRecords<OrderRecord>('/api/v1/orders', 'orders');
  const products = await sentRecords<ProductRecord>('/api/v1/products', 'products');
  const published = Number(lastLine(await wpEval("echo count(wc_get_products(array('status' => 'publish', 'limit' => -1, 'return' => 'ids', 'type' => array('simple', 'variable', 'grouped', 'external'))));")));

  // The store's side: every seeded customer once, every paid order once, every published product.
  expect(fake.counts.contacts).toBe(seed.customers);
  expect(contacts.length, 'no contact was sent twice').toBe(seed.customers);
  expect(fake.counts.orders).toBe(seed.paidOrders);
  expect(orders.length).toBe(seed.paidOrders);
  expect(fake.counts.products).toBe(published);

  // The plugin's own totals are the sums of AguMail's answers.
  expect(state.contacts.created).toBe(fake.counts.contacts);
  expect(state.contacts.updated).toBe(contacts.length - fake.counts.contacts);
  expect(state.orders.created).toBe(fake.counts.orders);
  expect(state.products.upserted).toBe(products.length);
  expect(state.done.orders).toBe(seed.paidOrders);

  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    const panel = page.locator('[data-agumail-bulk]');
    await expect(panel).toHaveAttribute('data-status', 'done');
    await expect(panel).toContainText(/Finished on /);
    const results = panel.locator('ul.agumail-bulk-results');
    const fmt = (n: number) => n.toLocaleString('en-US');
    await expect(results).toContainText(`Contacts: ${fmt(fake.counts.contacts)} added, ${fmt(contacts.length - fake.counts.contacts)} updated.`);
    await expect(results).toContainText(`Products: ${fmt(products.length)} synced.`);
    await expect(results).toContainText(`Orders: ${fmt(fake.counts.orders)} added, 0 updated, 0 already up to date.`);
    await expect(results).not.toContainText('refused');
    await expect(section(page, 'status')).toContainText(`${fmt(fake.counts.contacts)} contacts and ${fmt(fake.counts.orders)} orders added.`);
  } finally {
    await admin.close();
  }
  // Every order the sync sent is marked as held by this AguMail account, so a later refund updates it
  // instead of sending history again.
  const synced = Number(lastLine(await wpEval(`echo count(wc_get_orders(array('limit' => -1, 'return' => 'ids', 'status' => 'any', 'meta_key' => '_agumail_synced', 'meta_value' => 'user_e2e')));`)));
  expect(synced).toBe(seed.paidOrders);
  expectNoShopperCalls();
});
