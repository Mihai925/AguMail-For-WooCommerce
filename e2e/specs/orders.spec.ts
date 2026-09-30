import { expect, test } from '@playwright/test';
import { adminContext } from '../helpers/admin';
import { cartsFor, contactsFor, drainAll, expectNoShopperCalls, lastLine, orderEditUrl, orderFacts, ordersFor, outboxRows, startV2 } from '../helpers/agumail';
import { placeGuestOrder, uniqueEmail } from '../helpers/checkout';
import { fakeRequests } from '../helpers/fake';
import { wpEval } from '../helpers/wp';

// Orders through a real checkout, as AguMail hears of them:
//   - cash on delivery counts once processing: sent at checkout (by the next queue run), dated when it
//     was placed, with the buyer's answer to the box;
//   - a bank transfer on hold is not sent until the merchant marks it processing;
//   - a refund sends the order again with its new total, and no contact;
//   - the cart it was bought from closes as completed.

test.describe.configure({ mode: 'serial' });

const ticked = { email: '', orderId: 0 };
const unticked = { email: '', orderId: 0 };
const bacs = { email: '', orderId: 0 };

test.beforeAll(async () => {
  await startV2({ checkout: 'classic' });
  // Direct bank transfer on (setup-store.php does the same; this keeps an older stack working).
  await wpEval(
    "$s = get_option('woocommerce_bacs_settings', array()); update_option('woocommerce_bacs_settings', array_merge(is_array($s) ? $s : array(), array('enabled' => 'yes', 'title' => 'Direct bank transfer')));",
  );
});

test('cash on delivery orders are sent once processing, dated when placed, with the answer to the box', async ({ browser }) => {
  for (const [target, optIn] of [
    [ticked, true],
    [unticked, false],
  ] as const) {
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      target.email = uniqueEmail(optIn ? 'cod-yes' : 'cod-no');
      const order = await placeGuestOrder(page, { checkout: 'classic', email: target.email, optIn, paymentMethod: 'cod' });
      target.orderId = order.orderId;
    } finally {
      await context.close();
    }
    const facts = await orderFacts(target.orderId);
    expect(facts.status).toBe('processing');
    expect(facts.optin).toBe(optIn ? 'yes' : 'no');
  }
  // Nothing left the store while the shoppers checked out.
  expect(await ordersFor(ticked.orderId)).toEqual([]);
  expectNoShopperCalls();

  await drainAll();
  for (const [target, optIn] of [
    [ticked, true],
    [unticked, false],
  ] as const) {
    const facts = await orderFacts(target.orderId);
    const sent = await ordersFor(target.orderId);
    expect(sent.length, `order ${target.orderId} reached AguMail`).toBe(1);
    const record = sent[0].record;
    expect(record.email).toBe(target.email.toLowerCase());
    expect(record.total).toBeCloseTo(facts.total, 2);
    expect(record.source).toBe('woocommerce');
    // Cash on delivery: dated from when it was placed (WooCommerce may stamp a paid date at processing).
    expect(Math.abs(Date.parse(record.occurredAt) / 1000 - facts.created)).toBeLessThanOrEqual(60);
    expect(record.items?.[0]?.sku).toBe('AGM-TOTE');
    const contacts = await contactsFor(target.email);
    const withAnswer = contacts.filter((c) => c.record.consent);
    expect(withAnswer.length).toBe(1);
    expect(withAnswer[0].record.consent).toEqual({ optedIn: optIn, source: 'checkout' });
    if (optIn) expect(withAnswer[0].record.consentedAt).toBeTruthy();
    else expect(withAnswer[0].record.consentedAt).toBeUndefined();
    expect(withAnswer[0].request.seq).toBeLessThan(sent[0].request.seq);
    expect(facts.synced, 'marked as held by this AguMail account').toBe('user_e2e');
  }
  expectNoShopperCalls();
});

test('the cart the order was bought from closes as completed', async () => {
  for (const target of [ticked, unticked]) {
    const facts = await orderFacts(target.orderId);
    expect(facts.cartId).toMatch(/^wc-/);
    const carts = await cartsFor(target.email);
    expect(carts.length).toBeGreaterThan(0);
    const last = carts[carts.length - 1].record;
    expect(last.externalId).toBe(facts.cartId);
    expect(last.status).toBe('completed');
  }
});

test('a bank transfer on hold is not sent until it is marked processing', async ({ browser }) => {
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    bacs.email = uniqueEmail('bacs');
    const order = await placeGuestOrder(page, { checkout: 'classic', email: bacs.email, optIn: true, paymentMethod: 'bacs' });
    bacs.orderId = order.orderId;
  } finally {
    await context.close();
  }
  expect((await orderFacts(bacs.orderId)).status).toBe('on-hold');
  await drainAll();
  expect(await ordersFor(bacs.orderId), 'an unpaid order is not a purchase').toEqual([]);
  expect((await outboxRows()).filter((r) => r.type === 'order' && r.object_key === String(bacs.orderId))).toEqual([]);
  // Its cart was bought, though: AguMail hears it closed, so no reminder goes out for it.
  const carts = await cartsFor(bacs.email);
  expect(carts[carts.length - 1]?.record.status).toBe('completed');

  // The merchant sees the money arrive and marks the order processing.
  const admin = await adminContext(browser);
  const adminPage = await admin.newPage();
  try {
    await adminPage.goto(await orderEditUrl(bacs.orderId));
    await adminPage.locator('#order_status').selectOption('wc-processing');
    await adminPage.locator('button.save_order').click();
    await expect(adminPage.getByText('Order updated.').first()).toBeVisible();
  } finally {
    await admin.close();
  }
  const facts = await orderFacts(bacs.orderId);
  expect(facts.status).toBe('processing');
  expect(facts.paid).toBeTruthy();

  await drainAll();
  const sent = await ordersFor(bacs.orderId);
  expect(sent.length).toBe(1);
  // Dated when it was paid: news, even when the transfer took days.
  expect(Math.abs(Date.parse(sent[0].record.occurredAt) / 1000 - (facts.paid as number))).toBeLessThanOrEqual(5);
  const opted = (await contactsFor(bacs.email)).filter((c) => c.record.consent?.optedIn);
  expect(opted.length).toBe(1);
  expectNoShopperCalls();
});

test('a refund sends the order again with its new total, and no contact', async () => {
  const before = await orderFacts(ticked.orderId);
  const contactsBefore = (await contactsFor(ticked.email)).length;
  const since = (await fakeRequests()).reduce((m, r) => Math.max(m, r.seq), 0);
  const out = await wpEval(
    `$r = wc_create_refund(array('order_id' => ${ticked.orderId}, 'amount' => '5.00', 'reason' => 'e2e partial refund')); echo is_wp_error($r) ? 'error: ' . $r->get_error_message() : 'ok';`,
  );
  expect(lastLine(out)).toBe('ok');
  await drainAll();
  const sent = (await ordersFor(ticked.orderId)).filter((s) => s.request.seq > since);
  expect(sent.length).toBe(1);
  expect(sent[0].record.total).toBeCloseTo(before.total - 5, 2);
  expect((await contactsFor(ticked.email)).length, 'a later delivery carries no contact').toBe(contactsBefore);
  expectNoShopperCalls();
});
