import { expect, type Page, type Request, type Response } from '@playwright/test';
import { readRequestTimings, type RequestTiming } from './audit';
import { readSite, SITE_URL, type CheckoutType } from './env';

export const V1_CONSENT_LABEL = 'Email me about new products and offers';

/** The request each checkout places the order with; its server time is what the timing spec measures. */
export const CHECKOUT_REQUEST: Record<CheckoutType, string> = {
  classic: 'wc-ajax=checkout',
  block: '/wc/store/v1/checkout',
  checkoutwc: 'wc-ajax=checkout',
};

export type PlaceOrderOptions = {
  checkout: CheckoutType;
  /** Defaults to the first simple product of the e2e catalogue. */
  productId?: number;
  quantity?: number;
  email?: string;
  /** Set as the agumail_e2e_tag cookie on the store, so audit and timing lines can be matched. */
  tag?: string;
  /** Tick the marketing checkbox when the active plugin renders one. */
  optIn?: boolean;
  consentLabel?: string;
  paymentMethod?: 'cod' | 'cheque' | 'bacs';
  timeoutMs?: number;
};

export type PlacedOrder = {
  orderId: number;
  orderReceivedUrl: string;
  email: string;
  tag: string;
  checkout: CheckoutType;
  /** Browser-observed time from the Place order click to the checkout response. */
  clientMs: number;
  responseStatus: number;
};

let counter = 0;

export function uniqueEmail(prefix = 'guest'): string {
  counter += 1;
  return `${prefix}-${Date.now().toString(36)}-${process.pid}-${counter}@e2e.agumail.test`;
}

export function uniqueTag(prefix = 'e2e'): string {
  counter += 1;
  return `${prefix}-${Date.now().toString(36)}-${counter}`;
}

/** The checkout page the shopper opens for a checkout type (CheckoutWC takes over the classic page). */
export function checkoutPageUrl(type: CheckoutType): string {
  const site = readSite();
  return type === 'block' ? site.pages.blockCheckout.url : site.pages.classicCheckout.url;
}

/**
 * Every request this page's context makes to the store carries the tag (a cookie, so cross-origin
 * requests are untouched). The audit mu-plugin writes it on each log line.
 */
export async function tagRequests(page: Page, tag: string): Promise<void> {
  await page.context().addCookies([{ name: 'agumail_e2e_tag', value: tag, url: SITE_URL }]);
}

/**
 * This shopper sees the store with no AguMail plugin loaded (the e2e env mu-plugin drops it from the
 * active plugins for requests carrying the cookie), while everyone else still has it.
 */
export async function withoutAguMail(page: Page): Promise<void> {
  await page.context().addCookies([{ name: 'agumail_e2e_plugins_off', value: '1', url: SITE_URL }]);
}

export async function addToCart(page: Page, productId: number, quantity = 1): Promise<void> {
  await page.goto(`${SITE_URL}/?add-to-cart=${productId}&quantity=${quantity}`);
}

const SHOPPER = {
  firstName: 'Guest',
  lastName: 'Shopper',
  address: '10 Market Street',
  city: 'San Francisco',
  postcode: '94110',
  phone: '5550100',
};

/**
 * Places a guest order as a real shopper: fresh cart, fill the form, Place order, wait for the
 * order-received page. The page's context must be a fresh guest context.
 */
export async function placeGuestOrder(page: Page, options: PlaceOrderOptions): Promise<PlacedOrder> {
  const site = readSite();
  const productId = options.productId ?? site.products.find((p) => p.type === 'simple')!.id;
  const email = options.email ?? uniqueEmail();
  const tag = options.tag ?? uniqueTag(options.checkout);
  const timeout = options.timeoutMs ?? 120_000;
  const method = options.paymentMethod ?? 'cod';

  await tagRequests(page, tag);
  await addToCart(page, productId, options.quantity ?? 1);
  await page.goto(checkoutPageUrl(options.checkout));

  let response: Response;
  let clientMs: number;
  if (options.checkout === 'block') {
    ({ response, clientMs } = await placeBlockOrder(page, email, method, options, timeout));
  } else if (options.checkout === 'checkoutwc') {
    ({ response, clientMs } = await placeCheckoutWcOrder(page, email, method, options, timeout));
  } else {
    ({ response, clientMs } = await placeClassicOrder(page, email, method, options, timeout));
  }

  await page.waitForURL(/order-received\/\d+/, { timeout });
  const url = page.url();
  const orderId = Number(/order-received\/(\d+)/.exec(url)![1]);
  return { orderId, orderReceivedUrl: url, email, tag, checkout: options.checkout, clientMs, responseStatus: response.status() };
}

async function waitForClassicIdle(page: Page): Promise<void> {
  // update_order_review puts a blockUI overlay on the form while it runs.
  await page.waitForFunction(() => document.querySelectorAll('.blockUI.blockOverlay').length === 0, undefined, { timeout: 60_000 });
}

async function tickOptIn(page: Page, options: PlaceOrderOptions, selectors: string[]): Promise<void> {
  if (!options.optIn) return;
  for (const selector of selectors) {
    const box = page.locator(selector);
    if (await box.count()) {
      await box.first().check();
      return;
    }
  }
  const byLabel = page.getByLabel(options.consentLabel ?? V1_CONSENT_LABEL);
  if (await byLabel.count()) await byLabel.first().check();
}

async function placeClassicOrder(page: Page, email: string, method: string, options: PlaceOrderOptions, timeout: number) {
  await page.fill('#billing_first_name', SHOPPER.firstName);
  await page.fill('#billing_last_name', SHOPPER.lastName);
  await page.fill('#billing_address_1', SHOPPER.address);
  await page.fill('#billing_city', SHOPPER.city);
  await page.fill('#billing_postcode', SHOPPER.postcode);
  if (await page.locator('#billing_phone').count()) await page.fill('#billing_phone', SHOPPER.phone);
  await page.fill('#billing_email', email);
  await page.locator('#billing_email').blur();
  await waitForClassicIdle(page);
  await tickOptIn(page, options, ['#agumail_optin', 'input[name="agumail_optin"]']);
  await choosePaymentMethod(page, method);
  await waitForClassicIdle(page);
  return submit(page, CHECKOUT_REQUEST.classic, () => page.click('#place_order'), timeout);
}

/** Storefront hides the radio behind its list item, so the shopper clicks the method's label. */
async function choosePaymentMethod(page: Page, method: string): Promise<void> {
  const radio = page.locator(`#payment_method_${method}`);
  if (await radio.isChecked()) return;
  await page.locator(`label[for="payment_method_${method}"]`).click();
  await expect(radio).toBeChecked();
}

async function placeBlockOrder(page: Page, email: string, method: string, options: PlaceOrderOptions, timeout: number) {
  await page.locator('#email').fill(email);
  await page.locator('#shipping-first_name').fill(SHOPPER.firstName);
  await page.locator('#shipping-last_name').fill(SHOPPER.lastName);
  await page.locator('#shipping-address_1').fill(SHOPPER.address);
  await page.locator('#shipping-city').fill(SHOPPER.city);
  const state = page.locator('select#shipping-state');
  if (await state.count()) await state.selectOption('CA');
  await page.locator('#shipping-postcode').fill(SHOPPER.postcode);
  const phone = page.locator('#shipping-phone');
  if (await phone.count()) await phone.fill(SHOPPER.phone);
  await tickOptIn(page, options, ['input[id*="agumail"][type="checkbox"]']);
  await page.locator(`#radio-control-wc-payment-method-options-${method}`).check();
  const button = page.locator('.wc-block-components-checkout-place-order-button');
  await button.waitFor({ state: 'visible' });
  // Leave the last field first: a Place order click that also blurs a field starts an address push
  // and the block checkout drops the click. Then wait for the push to finish.
  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
  await page.waitForTimeout(250);
  await waitForBlockIdle(page);
  return submit(page, CHECKOUT_REQUEST.block, () => button.click(), timeout);
}

/** The block checkout drops a Place order click while it is still pushing the address to the Store API. */
async function waitForBlockIdle(page: Page): Promise<void> {
  await page.waitForFunction(
    () => {
      const data = (window as unknown as { wp?: { data?: { select: (store: string) => Record<string, () => boolean> } } }).wp?.data;
      if (!data) return true;
      const checkout = data.select('wc/store/checkout');
      const cart = data.select('wc/store/cart');
      const calculating = checkout?.isCalculating ? checkout.isCalculating() : false;
      const updating = cart?.isCustomerDataUpdating ? cart.isCustomerDataUpdating() : false;
      return !calculating && !updating;
    },
    undefined,
    { timeout: 60_000 },
  );
}

async function placeCheckoutWcOrder(page: Page, email: string, method: string, options: PlaceOrderOptions, timeout: number) {
  // CheckoutWC's templates are steps (information, shipping, payment) over the classic form fields.
  await page.locator('#billing_email').fill(email);
  const fill = async (selector: string, value: string) => {
    const field = page.locator(selector);
    if ((await field.count()) && (await field.first().isVisible())) await field.first().fill(value);
  };
  for (const prefix of ['shipping', 'billing']) {
    await fill(`#${prefix}_first_name`, SHOPPER.firstName);
    await fill(`#${prefix}_last_name`, SHOPPER.lastName);
    await fill(`#${prefix}_address_1`, SHOPPER.address);
    await fill(`#${prefix}_city`, SHOPPER.city);
    await fill(`#${prefix}_postcode`, SHOPPER.postcode);
    await fill(`#${prefix}_phone`, SHOPPER.phone);
  }
  // Information -> Shipping -> Payment. Each step validates over AJAX before the next tab shows.
  const toShipping = page.locator('.cfw-continue-to-shipping-btn:visible');
  if (await toShipping.count()) {
    await toShipping.first().click();
    await page.locator('#cfw-shipping-method').waitFor({ state: 'visible', timeout });
    await waitForClassicIdle(page);
  }
  const toPayment = page.locator('.cfw-continue-to-payment-btn:visible');
  if (await toPayment.count()) {
    await toPayment.first().click();
  }
  await page.locator('#cfw-payment-method').waitFor({ state: 'visible', timeout });
  await waitForClassicIdle(page);
  await tickOptIn(page, options, ['#agumail_optin', 'input[name="agumail_optin"]']);
  const radio = page.locator(`#payment_method_${method}`);
  if (await radio.count()) await radio.check({ force: true });
  await waitForClassicIdle(page);
  return submit(page, CHECKOUT_REQUEST.checkoutwc, () => page.locator('#place_order:visible').first().click(), timeout);
}

async function submit(page: Page, requestIncludes: string, click: () => Promise<void>, timeout: number) {
  const isCheckout = (r: Request) => r.url().includes(requestIncludes) && r.method() === 'POST';
  // A click can be swallowed while the form is still busy; the request itself starts at once when it is not.
  // So: click, and click again only if no checkout request has even started (never a second order).
  let request: Request | null = null;
  let started = 0;
  for (let attempt = 0; attempt < 3 && !request; attempt++) {
    const requestPromise = page.waitForRequest(isCheckout, { timeout: 5_000 }).catch(() => null);
    started = Date.now();
    await click();
    request = await requestPromise;
    if (!request && process.env.AGUMAIL_E2E_DEBUG) console.log(`[checkout] click ${attempt + 1} sent no ${requestIncludes} request`);
  }
  if (!request) throw new Error(`Place order never sent a request matching ${requestIncludes}`);
  const sent = request;
  const response = await Promise.race([
    sent.response(),
    new Promise<never>((_, reject) => setTimeout(() => reject(new Error(`No checkout response within ${timeout} ms`)), timeout)),
  ]);
  if (!response) throw new Error(`Checkout request failed: ${sent.failure()?.errorText ?? 'unknown error'}`);
  return { response, clientMs: Date.now() - started };
}

/** The server time of a placed order's checkout request, from the audit mu-plugin (waits for the line). */
export async function checkoutServerTime(order: PlacedOrder, waitMs = 5_000): Promise<RequestTiming> {
  const deadline = Date.now() + waitMs;
  for (;;) {
    const line = readRequestTimings().find(
      (t) => t.tag === order.tag && t.method === 'POST' && (t.uri ?? '').includes(CHECKOUT_REQUEST[order.checkout]),
    );
    if (line) return line;
    if (Date.now() > deadline) throw new Error(`No server timing line for ${order.checkout} order ${order.orderId} (tag ${order.tag})`);
    await new Promise((r) => setTimeout(r, 100));
  }
}
