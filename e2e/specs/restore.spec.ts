import { expect, test } from '@playwright/test';
import { cartsFor, drainAll, expectNoShopperCalls, startV2, type CartRecord } from '../helpers/agumail';
import { addToCart, checkoutPageUrl, tagRequests, uniqueEmail, uniqueTag } from '../helpers/checkout';
import { SITE_URL, readSite } from '../helpers/env';

// The restore link of a recorded cart, opened as a recovery email's reader would: in a browser that
// has never seen the store. The cart is rebuilt and the shopper lands on checkout with the link's
// other params (agm_cid, utm_*) kept; a wrong token changes nothing.

test.describe.configure({ mode: 'serial' });

let cart: CartRecord;
let email = '';
const tee = () => readSite().products.find((p) => p.sku === 'AGM-TEE')!;
const tote = () => readSite().products.find((p) => p.sku === 'AGM-TOTE')!;

test.beforeAll(async ({ browser }) => {
  await startV2({ checkout: 'classic' });
  // A guest fills a cart (a simple product twice, one size of a variable one) and leaves after typing the email.
  email = uniqueEmail('restore');
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    await tagRequests(page, uniqueTag('restore-cart'));
    await addToCart(page, tote().id, 2);
    const medium = tee().variations!.find((v) => v.value === 'M')!;
    await page.goto(`${SITE_URL}/?add-to-cart=${medium.id}&quantity=1&${medium.attribute}=${medium.value}`);
    await page.goto(checkoutPageUrl('classic'));
    const captured = page.waitForResponse((r) => r.url().includes('wc-ajax=agumail_capture'));
    await page.fill('#billing_email', email);
    await page.locator('#billing_email').press('Tab');
    await captured;
  } finally {
    await context.close();
  }
  await drainAll();
  const carts = await cartsFor(email);
  expect(carts.length).toBeGreaterThan(0);
  cart = carts[carts.length - 1].record;
});

test('the restore link rebuilds the cart in a fresh browser and lands on checkout with its params kept', async ({ browser, request }) => {
  expect(cart.checkoutUrl).toBeTruthy();
  const link = `${cart.checkoutUrl}&agm_cid=camp_restore_1&utm_source=agumail&utm_medium=email&utm_campaign=cart-reminder`;

  // One 302, straight to checkout, with the link's own two params gone and the others kept.
  const first = await request.get(link, { maxRedirects: 0 });
  expect(first.status()).toBe(302);
  const location = new URL(first.headers()['location'], SITE_URL);
  expect(location.pathname).toBe(new URL(checkoutPageUrl('classic')).pathname);
  expect(location.searchParams.get('agm_cid')).toBe('camp_restore_1');
  expect(location.searchParams.get('utm_source')).toBe('agumail');
  expect(location.searchParams.get('utm_medium')).toBe('email');
  expect(location.searchParams.get('utm_campaign')).toBe('cart-reminder');
  expect(location.searchParams.has('agumail_cart')).toBe(false);
  expect(location.searchParams.has('agumail_token')).toBe(false);

  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    await tagRequests(page, uniqueTag('restore-open'));
    await page.goto(link);
    const landed = new URL(page.url());
    expect(landed.pathname).toBe(new URL(checkoutPageUrl('classic')).pathname);
    expect(landed.searchParams.get('agm_cid')).toBe('camp_restore_1');
    expect(landed.searchParams.get('utm_campaign')).toBe('cart-reminder');
    // The cart is back, quantities and variation included, and the checkout knows the email.
    const review = page.locator('.woocommerce-checkout-review-order-table');
    await expect(review).toContainText('Linen Tote');
    await expect(review.locator('tr.cart_item', { hasText: 'Linen Tote' })).toContainText('× 2');
    await expect(review).toContainText('Organic Tee');
    await expect(review.locator('tr.cart_item', { hasText: 'Organic Tee' })).toContainText('M');
    await expect(page.locator('#billing_email')).toHaveValue(email);
    // The campaign in the link is kept for the order's attribution.
    const cookies = await context.cookies(SITE_URL);
    expect(cookies.find((c) => c.name === 'agm_cid')?.value).toBe('camp_restore_1');
  } finally {
    await context.close();
  }
  expectNoShopperCalls();
});

test('a wrong token rebuilds nothing and only drops its own params', async ({ browser, request }) => {
  const url = new URL(cart.checkoutUrl!);
  url.searchParams.set('agumail_token', '0'.repeat(32));
  url.searchParams.set('agm_cid', 'camp_wrong');
  const first = await request.get(url.toString(), { maxRedirects: 0 });
  expect(first.status()).toBe(302);
  const location = new URL(first.headers()['location'], SITE_URL);
  expect(location.pathname).toBe(url.pathname);
  expect(location.searchParams.has('agumail_cart')).toBe(false);
  expect(location.searchParams.has('agumail_token')).toBe(false);
  expect(location.searchParams.get('agm_cid')).toBe('camp_wrong');

  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    await tagRequests(page, uniqueTag('restore-wrong'));
    await page.goto(url.toString());
    // An empty cart: WooCommerce sends an empty checkout on to the cart page.
    await page.goto(readSite().pages.classicCart.url);
    await expect(page.locator('.wc-empty-cart-message, .cart-empty').first()).toBeVisible();
  } finally {
    await context.close();
  }
  expectNoShopperCalls();
});

test('the recorded cart went to AguMail as open, with its lines and the restore link', async () => {
  expect(cart.status).toBe('open');
  expect(cart.items.map((i) => [i.sku, i.qty]).sort()).toEqual([
    ['AGM-TEE-M', 1],
    ['AGM-TOTE', 2],
  ]);
  expect(cart.total).toBeGreaterThan(0);
});
