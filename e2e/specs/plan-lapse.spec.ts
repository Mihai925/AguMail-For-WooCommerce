import { expect, test, type Browser, type Page } from '@playwright/test';
import { adminContext, openSettings, section } from '../helpers/admin';
import { contactsFor, lastLine, ordersFor, outboxRows, startV2 } from '../helpers/agumail';
import { placeGuestOrder, uniqueEmail } from '../helpers/checkout';
import { fakeControl } from '../helpers/fake';
import { drainQueue, wpEval } from '../helpers/wp';

// A store whose AguMail plan stops carrying store sync, as when the trial ends without a Commerce plan:
//   - AguMail refuses orders with 402 PLAN_FEATURE storeSync; the plugin holds store data only, so the order
//     waits (no attempt counted) while the shopper who ticked the box still joins the list;
//   - the AguMail page says store sync is paused, with the plans;
//   - once the plan has store sync again, the next hourly check lets the waiting order go, with nothing to reconnect.
// The store ends connected to the fake with store sync, as every other spec expects.

test.describe.configure({ mode: 'serial' });

async function asShopper<T>(browser: Browser, fn: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    return await fn(page);
  } finally {
    await context.close();
  }
}

test.beforeAll(async () => {
  await startV2({ checkout: 'classic' });
  await wpEval("delete_option('agumail_store_sync_refused'); delete_option('agumail_store_sync_available'); delete_option('agumail_store_notice');");
});

test.afterAll(async () => {
  await fakeControl({ storeSync: true });
  await wpEval("delete_option('agumail_store_sync_refused'); delete_option('agumail_store_sync_available'); delete_option('agumail_store_notice');");
  await startV2();
});

test('without store sync the order waits, the checkout subscriber still joins, and the order goes once it is back', async ({ browser }) => {
  await fakeControl({ storeSync: false });
  const email = uniqueEmail('lapse');
  const order = await asShopper(browser, (page) => placeGuestOrder(page, { checkout: 'classic', email, optIn: true, paymentMethod: 'cod' }));
  await drainQueue({ rounds: 4 });

  expect(lastLine(await wpEval("echo agumail_store_sync_held() ? 'held' : 'on';"))).toBe('held');
  expect(await ordersFor(order.orderId)).toEqual([]);
  const waiting = (await outboxRows()).filter((row) => row.type === 'order' && row.object_key === String(order.orderId));
  expect(waiting.length).toBe(1);
  expect(waiting[0].status).toBe('pending');
  expect(waiting[0].attempts).toBe(0);
  const contacts = await contactsFor(email);
  expect(contacts.some((c) => c.record.consent?.optedIn === true), 'the shopper who ticked the box did not join the list').toBe(true);
  expect(lastLine(await wpEval("echo agumail_connection()['status'];"))).not.toBe('error');

  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    await expect(section(page, 'connection')).toContainText('Store sync is paused: your AguMail plan does not include it.');
    await expect(section(page, 'sync')).toHaveCount(0);
  } finally {
    await admin.close();
  }

  // The plan has store sync again; an hour later the drain asks once more.
  await fakeControl({ storeSync: true });
  await wpEval("update_option('agumail_store_sync_available', array('available' => false, 'at' => time() - 2 * HOUR_IN_SECONDS), false);");
  await drainQueue({ rounds: 4 });
  expect(lastLine(await wpEval("echo agumail_store_sync_held() ? 'held' : 'on';"))).toBe('on');
  expect((await ordersFor(order.orderId)).length).toBe(1);
});
