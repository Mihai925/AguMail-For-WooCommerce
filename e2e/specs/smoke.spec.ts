import { expect, test } from '@playwright/test';
import { resetAudit } from '../helpers/audit';
import { placeGuestOrder } from '../helpers/checkout';
import { FAKE_URL, SITE_URL, readSite } from '../helpers/env';
import { fakeRequests, fakeReset } from '../helpers/fake';
import { activatePlugin, checkoutWcAvailable, orderStatus, setCheckout } from '../helpers/wp';

// The stack itself, with no AguMail plugin active: if these fail, no other spec means anything.

test.beforeAll(async () => {
  await activatePlugin('none');
  await fakeReset();
  resetAudit();
});

test('the store and the fake AguMail are up', async ({ request }) => {
  const home = await request.get(SITE_URL);
  expect(home.status()).toBe(200);
  expect(await home.text()).toContain('AguMail E2E Shop');

  const fake = await request.get(`${FAKE_URL}/__health`);
  expect(await fake.json()).toEqual({ ok: true });
});

test('a product page shows name, price, image and add to cart', async ({ page }) => {
  const product = readSite().products.find((p) => p.sku === 'AGM-TOTE')!;
  await page.goto(product.url);
  await expect(page.locator('h1.product_title')).toHaveText(product.name);
  await expect(page.locator('p.price')).toContainText('24.00');
  await expect(page.locator('.woocommerce-product-gallery__image img').first()).toBeVisible();
  await expect(page.locator('button[name="add-to-cart"]')).toBeVisible();

  const variable = readSite().products.find((p) => p.sku === 'AGM-TEE')!;
  await page.goto(variable.url);
  await expect(page.locator('select#size')).toBeVisible();
});

for (const checkout of ['classic', 'block'] as const) {
  test(`${checkout} checkout places a cash on delivery order (plugin inactive)`, async ({ page }) => {
    await setCheckout(checkout);
    const order = await placeGuestOrder(page, { checkout });
    expect(order.responseStatus).toBe(200);
    await expect(page.locator('body')).toContainText('Thank you');
    expect(await orderStatus(order.orderId)).toBe('processing');
  });
}

test('CheckoutWC checkout places a cash on delivery order (plugin inactive)', async ({ page }) => {
  test.skip(!(await checkoutWcAvailable()), 'CheckoutWC is not mounted (CHECKOUTWC_DIR)');
  await setCheckout('checkoutwc');
  try {
    const order = await placeGuestOrder(page, { checkout: 'checkoutwc' });
    expect(order.responseStatus).toBe(200);
    expect(await orderStatus(order.orderId)).toBe('processing');
  } finally {
    await setCheckout('classic');
  }
});

test('nothing reached the fake AguMail while no plugin was active', async () => {
  expect(await fakeRequests({ path: '/api/v1' })).toEqual([]);
});
