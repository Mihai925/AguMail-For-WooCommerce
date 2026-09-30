import { expect, test, type Browser, type Page } from '@playwright/test';
import { adminContext, openSettings, section, settingsNotice } from '../helpers/admin';
import { drainAll, expectNoShopperCalls, ordersFor, outboxRows, startV2 } from '../helpers/agumail';
import { placeGuestOrder } from '../helpers/checkout';
import { fakeControl, fakeMode } from '../helpers/fake';
import { outboxDueNow, runQueue, wpEval } from '../helpers/wp';

// The Status section of WooCommerce > AguMail: the connection, what is waiting in the queue and how
// old it is, a pause AguMail asked for, and the items AguMail refused, with Retry and Discard.

test.describe.configure({ mode: 'serial' });

async function order(browser: Browser): Promise<number> {
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    return (await placeGuestOrder(page, { checkout: 'classic' })).orderId;
  } finally {
    await context.close();
  }
}

async function withSettings<T>(browser: Browser, fn: (page: Page) => Promise<T>): Promise<T> {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    return await fn(page);
  } finally {
    await admin.close();
  }
}

/** Sends the queue while AguMail refuses the records whose externalId is one of these, as invalid. */
async function drainRefusing(orderIds: number[]): Promise<void> {
  const [id] = orderIds;
  await fakeMode('400-at-index', { match: String(id), paths: ['/api/v1/orders'] });
  await outboxDueNow();
  await runQueue({ dueNow: true });
  await fakeMode('ok');
}

const failedRow = (page: Page, orderId: number) => section(page, 'status').locator('table.agumail-status-failed tr', { hasText: `Order #${orderId}` });

let refusedA = 0;
let refusedB = 0;
let refusedC = 0;

test.beforeAll(async () => {
  await startV2({ checkout: 'classic' });
  await wpEval("delete_option('agumail_last_sent');");
});

test.afterAll(async () => {
  await fakeMode('ok');
  await fakeControl({ match: null });
});

test('the status shows the connection and what is waiting, and how old it is', async ({ browser }) => {
  const first = await order(browser);
  const second = await order(browser);
  const queued = (await outboxRows()).filter((r) => r.type === 'order').map((r) => Number(r.object_key));
  expect(queued).toEqual(expect.arrayContaining([first, second]));

  await withSettings(browser, async (page) => {
    const status = section(page, 'status');
    await expect(status).toContainText('Connected to AguMail E2E Shop');
    await expect(status).toContainText('Nothing has been sent yet.');
    const orders = status.locator('table.agumail-status-queue tr', { hasText: 'Orders' });
    await expect(orders.locator('td').nth(1)).toHaveText(String(queued.length));
    await expect(orders.locator('td').nth(2)).toHaveText('0');
    await expect(status).toContainText(/Oldest waiting item: queued .+ ago\./);
    await expect(status.getByRole('link', { name: 'View the AguMail log' })).toHaveAttribute('href', /page=wc-status&tab=logs&source=agumail/);
  });

  await drainAll();
  await withSettings(browser, async (page) => {
    const status = section(page, 'status');
    await expect(status).toContainText('Nothing is waiting.');
    await expect(status).toContainText(/Last successful sync\s*.+ ago \(\d+ (orders|carts|contacts)\)\./);
  });
  expectNoShopperCalls();
});

test('a pause AguMail asked for is shown with the time it ends', async ({ browser }) => {
  await order(browser);
  await fakeMode('429', { retryAfter: 900 });
  await runQueue({ dueNow: true });
  await fakeMode('ok');
  await withSettings(browser, async (page) => {
    await expect(section(page, 'status')).toContainText(/Sending is paused until .+ nothing is lost\./);
  });
  // Once the wait is over, it goes.
  await outboxDueNow();
  await drainAll();
  await withSettings(browser, async (page) => {
    await expect(section(page, 'status')).not.toContainText('Sending is paused');
    await expect(section(page, 'status')).toContainText('Nothing is waiting.');
  });
});

test('an order AguMail refused is listed as failed, and Retry sends it again', async ({ browser }) => {
  refusedA = await order(browser);
  const fine = await order(browser);
  await drainRefusing([refusedA]);
  expect(await ordersFor(refusedA)).toEqual([]);
  expect((await ordersFor(fine)).length, 'the rest of the batch went').toBe(1);
  const row = (await outboxRows()).find((r) => r.type === 'order' && r.object_key === String(refusedA))!;
  expect(row.status).toBe('failed');

  await withSettings(browser, async (page) => {
    const status = section(page, 'status');
    await expect(status.locator('h3', { hasText: 'Failed items' })).toBeVisible();
    await expect(status.locator('table.agumail-status-queue tr', { hasText: 'Orders' }).locator('td').nth(2)).toHaveText('1');
    const failed = failedRow(page, refusedA);
    await expect(failed).toContainText(/orders\.\d+\.email: Invalid email/);
    await failed.getByRole('link', { name: 'Retry' }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('The item goes again with the next send.');
    await expect(failedRow(page, refusedA)).toHaveCount(0);
  });
  expect((await outboxRows()).find((r) => r.object_key === String(refusedA))?.status).toBe('pending');
  await drainAll();
  expect((await ordersFor(refusedA)).length, 'the retried order reached AguMail').toBe(1);
  expect((await outboxRows()).find((r) => r.object_key === String(refusedA))).toBeUndefined();
  expectNoShopperCalls();
});

test('Discard drops a failed item; Retry all sends every failed item again', async ({ browser }) => {
  refusedB = await order(browser);
  await drainRefusing([refusedB]);
  refusedC = await order(browser);
  await drainRefusing([refusedC]);
  expect((await outboxRows()).filter((r) => r.status === 'failed').map((r) => Number(r.object_key)).sort()).toEqual([refusedB, refusedC].sort());

  await withSettings(browser, async (page) => {
    await failedRow(page, refusedB).getByRole('link', { name: 'Discard' }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('The item was discarded. AguMail will not receive it.');
    await expect(failedRow(page, refusedB)).toHaveCount(0);
    await expect(failedRow(page, refusedC)).toHaveCount(1);

    await section(page, 'status').getByRole('button', { name: 'Retry all' }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('1 item goes again with the next send.');
    await expect(section(page, 'status').locator('h3', { hasText: 'Failed items' })).toHaveCount(0);
  });
  await drainAll();
  expect(await ordersFor(refusedB), 'a discarded order never goes').toEqual([]);
  expect((await ordersFor(refusedC)).length).toBe(1);
  expect(await outboxRows()).toEqual([]);
  expectNoShopperCalls();
});
