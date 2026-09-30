import { expect, test, type Page, type Request } from '@playwright/test';
import { adminContext, openSettings, section, settingsNotice } from '../helpers/admin';
import { contactsFor, drainAll, expectNoShopperCalls, lastLine, orderFacts, php, resetPluginQueue, startV2 } from '../helpers/agumail';
import { optInBox } from '../helpers/capture-suite';
import { addToCart, checkoutPageUrl, placeGuestOrder, tagRequests, uniqueEmail, uniqueTag } from '../helpers/checkout';
import { readSite, SITE_URL } from '../helpers/env';
import { setCheckout, wp, wpEval } from '../helpers/wp';

// The checkout box, level with Mailchimp for WooCommerce:
//   - "Tick the box by default" (WooCommerce > AguMail > Checkout): the box starts ticked on the classic and block
//     checkouts, and an order placed without touching it carries the tick and records yes; a box the shopper unticks
//     stays unticked and records no; a signed-in customer who said no starts unticked;
//   - a link in the label shows on the classic checkout; the block checkout, which shows its label as text, gets the
//     words only;
//   - the block checkout's thank-you page and order emails no longer list the box under "Additional information".
// Every test ends with the settings back at their defaults.

test.describe.configure({ mode: 'serial' });

const PRIVACY_URL = `${SITE_URL}/agumail-privacy/`;
const LABEL_HTML = `Email me about new products and offers. <a href="${PRIVACY_URL}" target="_blank" rel="noopener">Privacy policy</a>`;
const LABEL_WORDS = 'Email me about new products and offers. Privacy policy';

let checkoutBefore = 'classic';

function mugId(): number {
  return readSite().products.find((p) => p.sku === 'AGM-MUG')!.id;
}

async function setParity(on: boolean): Promise<void> {
  await wpEval(
    on
      ? `update_option('agumail_checkout_optin_default', 'yes'); update_option('agumail_consent_label', ${php(LABEL_HTML)});`
      : "delete_option('agumail_checkout_optin_default'); delete_option('agumail_consent_label');",
  );
}

async function openCheckout(page: Page, checkout: 'classic' | 'block', tag: string): Promise<void> {
  await tagRequests(page, tag);
  await addToCart(page, mugId());
  await page.goto(checkoutPageUrl(checkout));
}

/** The request that places the order (a block checkout's draft save is a POST overriding to PUT). */
function isPlaceOrder(checkout: 'classic' | 'block') {
  return (r: Request) =>
    r.method() === 'POST' &&
    (checkout === 'block'
      ? r.url().includes('/wc/store/v1/checkout') && !r.headers()['x-http-method-override']
      : r.url().includes('wc-ajax=checkout'));
}

test.beforeAll(async () => {
  checkoutBefore = lastLine(await wpEval("echo get_option('agumail_e2e_checkout', 'classic');")) || 'classic';
  await startV2();
  await setParity(false);
});

test.afterAll(async () => {
  await setParity(false);
  await setCheckout(checkoutBefore === 'block' || checkoutBefore === 'checkoutwc' ? checkoutBefore : 'classic');
});

test('the settings page saves a label with a link and "Tick the box by default"', async ({ browser }) => {
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await openSettings(page);
    const checkout = section(page, 'checkout');
    await expect(checkout).toContainText('Links show on the classic checkout and registration forms. The block checkout shows the words only.');
    const tick = checkout.getByLabel('Tick the box by default');
    await expect(tick).not.toBeChecked();
    await checkout.locator('#agumail_consent_label').fill(LABEL_HTML);
    await tick.check();
    await checkout.getByRole('button', { name: 'Save' }).click();
    await page.waitForURL(/page=agumail/);
    expect(await settingsNotice(page)).toBe('Checkout settings saved.');
    await expect(section(page, 'checkout').getByLabel('Tick the box by default')).toBeChecked();
    await expect(section(page, 'checkout').locator('#agumail_consent_label')).toHaveValue(LABEL_HTML);
  } finally {
    await admin.close();
  }
  const saved = JSON.parse(
    lastLine(await wpEval("echo wp_json_encode(array(get_option('agumail_checkout_optin_default'), get_option('agumail_consent_label')));")),
  );
  expect(saved).toEqual(['yes', LABEL_HTML]);
});

test('classic: the box starts ticked with its link, keeps an untick, and an order placed without touching it records yes', async ({ browser }) => {
  await setParity(true);
  await setCheckout('classic');
  const context = await browser.newContext();
  const page = await context.newPage();
  const email = uniqueEmail('parity-classic');
  let orderId = 0;
  try {
    // The classic checkout redraws the order review once as it loads; the box is drawn before that and again by it.
    const firstReview = page.waitForResponse((r) => r.url().includes('wc-ajax=update_order_review'), { timeout: 30_000 });
    await openCheckout(page, 'classic', uniqueTag('parity-classic'));
    await firstReview;
    const box = page.locator('#agumail_optin');
    await expect(box).toBeChecked();
    const link = page.locator(`.agumail-optin a[href="${PRIVACY_URL}"]`);
    await expect(link).toHaveText('Privacy policy');
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener');

    // The order review is redrawn from the posted form: the shopper's untick stands.
    await page.waitForFunction(() => document.querySelectorAll('.blockUI.blockOverlay').length === 0);
    await box.uncheck();
    const review = page.waitForResponse((r) => r.url().includes('wc-ajax=update_order_review'), { timeout: 15_000 });
    await page.evaluate(() => (window as unknown as { jQuery?: (s: unknown) => { trigger: (e: string) => void } }).jQuery?.(document.body).trigger('update_checkout'));
    await review;
    await page.waitForFunction(() => document.querySelectorAll('.blockUI.blockOverlay').length === 0);
    await expect(page.locator('#agumail_optin')).not.toBeChecked();

    // A fresh checkout starts ticked again; the shopper places the order without touching the box.
    const placed = page.waitForRequest(isPlaceOrder('classic'), { timeout: 120_000 });
    const order = await placeGuestOrder(page, { checkout: 'classic', email, productId: mugId() });
    orderId = order.orderId;
    const posted = new URLSearchParams((await placed).postData() ?? '');
    expect(posted.get('agumail_optin')).toBe('1');
    expect(posted.get('agumail_optin_shown')).toBe('1');
  } finally {
    await context.close();
  }
  expect((await orderFacts(orderId)).optin).toBe('yes');
  await drainAll();
  const opted = (await contactsFor(email)).filter((c) => c.record.consent?.optedIn === true);
  expect(opted.length, 'the order sent its contact as opted in').toBe(1);
  expect(opted[0].record.consent?.source).toBe('checkout');
  expectNoShopperCalls();
});

test('block: the box starts ticked with plain words, an untouched box posts true and records yes, and the thank-you page lists nothing', async ({ browser }) => {
  await setParity(true);
  await setCheckout('block');
  const context = await browser.newContext();
  const page = await context.newPage();
  const email = uniqueEmail('parity-block');
  let orderId = 0;
  try {
    await openCheckout(page, 'block', uniqueTag('parity-block'));
    const box = optInBox(page, 'block');
    await expect(box).toHaveCount(1);
    await expect(box).toBeChecked();
    // WooCommerce shows the label as text: the words, and no link.
    await expect(page.getByLabel(LABEL_WORDS)).toHaveCount(1);
    await expect(page.locator(`a[href="${PRIVACY_URL}"]`)).toHaveCount(0);
    await expect(page.locator('body')).not.toContainText('<a href');

    const placed = page.waitForRequest(isPlaceOrder('block'), { timeout: 120_000 });
    const order = await placeGuestOrder(page, { checkout: 'block', email, productId: mugId() });
    orderId = order.orderId;
    expect((await placed).postDataJSON()).toMatchObject({ additional_fields: { 'agumail/optin': true } });

    // The thank-you page: the order and its customer details (whose hook is where WooCommerce lists additional
    // fields), and no "Additional information" about the box.
    await expect(page.locator('body')).toContainText(String(orderId));
    await expect(page.locator('.woocommerce-customer-details')).toBeAttached();
    await expect(page.locator('body')).not.toContainText('Additional information');
    await expect(page.locator('body')).not.toContainText('Email me about new products and offers');
  } finally {
    await context.close();
  }
  expect((await orderFacts(orderId)).optin).toBe('yes');
  expectNoShopperCalls();
});

test('block: WooCommerce keeps the box out of the thank-you page and the order emails only because the plugin asks', async () => {
  // The last block order carries the box's value; WooCommerce would list it if the field allowed it.
  const out = await wpEval(`
    $ids = wc_get_orders(array('created_via' => 'store-api', 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'return' => 'ids'));
    $order = wc_get_order($ids[0]);
    $frontend = Automattic\\WooCommerce\\Blocks\\Package::container()->get(Automattic\\WooCommerce\\Blocks\\Domain\\Services\\CheckoutFieldsFrontend::class);
    $render = function () use ($order, $frontend) {
      ob_start(); $frontend->render_order_other_fields($order); $page = ob_get_clean();
      ob_start(); WC()->mailer()->additional_checkout_fields($order, false, false); $email = ob_get_clean();
      return array('page' => $page, 'email' => $email);
    };
    if (!has_action('woocommerce_order_details_after_customer_details', array($frontend, 'render_order_other_fields'))) { echo 'not-hooked'; return; }
    $as_registered = $render();
    add_filter('woocommerce_filter_fields_for_order_confirmation', '__return_true');
    $if_shown = $render();
    echo wp_json_encode(array('registered' => $as_registered, 'shown' => $if_shown));
  `);
  const result = JSON.parse(lastLine(out)) as Record<'registered' | 'shown', { page: string; email: string }>;
  expect(result.registered).toEqual({ page: '', email: '' });
  for (const html of [result.shown.page, result.shown.email]) {
    expect(html).toContain('Additional information');
    expect(html).toContain(LABEL_WORDS);
  }
});

test('block: a box the shopper unticks stays unticked in the draft and records no', async ({ browser }) => {
  await setParity(true);
  await setCheckout('block');
  const context = await browser.newContext();
  const page = await context.newPage();
  let orderId = 0;
  try {
    await openCheckout(page, 'block', uniqueTag('parity-block-untick'));
    const box = optInBox(page, 'block');
    await expect(box).toBeChecked();
    const isDraftSave = (r: Request) =>
      r.url().includes('/wc/store/v1/checkout') && (r.method() === 'PUT' || r.headers()['x-http-method-override'] === 'PUT');
    const saved = page.waitForResponse((r) => isDraftSave(r.request()), { timeout: 10_000 });
    await box.uncheck();
    expect((await saved).request().postDataJSON()).toMatchObject({ additional_fields: { 'agumail/optin': false } });

    // The draft keeps the untick: WooCommerce's copy of the customer record does not tick it again.
    await page.reload();
    await expect(optInBox(page, 'block')).not.toBeChecked();

    const placed = page.waitForRequest(isPlaceOrder('block'), { timeout: 120_000 });
    const order = await placeGuestOrder(page, { checkout: 'block', email: uniqueEmail('parity-block-untick'), productId: mugId() });
    orderId = order.orderId;
    expect((await placed).postDataJSON()).toMatchObject({ additional_fields: { 'agumail/optin': false } });
  } finally {
    await context.close();
  }
  expect((await orderFacts(orderId)).optin).toBe('no');
  expectNoShopperCalls();
});

test('a signed-in customer who said no starts with the box unticked on both checkouts', async ({ browser }) => {
  await setParity(true);
  const login = `parity-no-${Date.now().toString(36)}`;
  const password = 'parity-no-password';
  const userId = Number(lastLine(await wp('user', 'create', login, `${login}@e2e.agumail.test`, '--role=customer', `--user_pass=${password}`, '--porcelain')));
  await wp('user', 'meta', 'update', String(userId), '_agumail_optin', 'no');
  const startsTicked = async (checkout: 'classic' | 'block'): Promise<boolean> => {
    await setCheckout(checkout);
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await page.goto(`${SITE_URL}/wp-login.php`);
      // wp-login.php moves the focus to the username a moment after it loads; typing before that lands in the wrong field.
      await page.waitForFunction(() => document.activeElement?.id === 'user_login', undefined, { timeout: 5_000 }).catch(() => undefined);
      await page.fill('#user_login', login);
      await page.fill('#user_pass', password);
      await expect(page.locator('#user_login')).toHaveValue(login);
      await expect(page.locator('#user_pass')).toHaveValue(password);
      await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php')), page.click('#wp-submit')]);
      await openCheckout(page, checkout, uniqueTag(`parity-no-${checkout}`));
      const box = optInBox(page, checkout);
      await expect(box).toHaveCount(1);
      return await box.isChecked();
    } finally {
      await context.close();
    }
  };
  try {
    expect(await startsTicked('classic'), 'classic: the store default overrode the customer\'s no').toBe(false);
    expect(await startsTicked('block'), 'block: the store default overrode the customer\'s no').toBe(false);
    // The same customer with no answer yet gets the store default: it is the no that unticks it.
    await wp('user', 'meta', 'delete', String(userId), '_agumail_optin');
    expect(await startsTicked('block'), 'block: a customer with no answer did not get the store default').toBe(true);
  } finally {
    await wp('user', 'delete', String(userId), '--yes');
    // Creating the account queued it for AguMail; nothing here sends it.
    await resetPluginQueue();
  }
  expectNoShopperCalls();
});

test('with the setting off, both checkouts start unticked again', async ({ browser }) => {
  await setParity(false);
  for (const checkout of ['classic', 'block'] as const) {
    await setCheckout(checkout);
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      await openCheckout(page, checkout, uniqueTag(`parity-off-${checkout}`));
      const box = optInBox(page, checkout);
      await expect(box).toHaveCount(1);
      await expect(box).not.toBeChecked();
    } finally {
      await context.close();
    }
  }
  expectNoShopperCalls();
});
