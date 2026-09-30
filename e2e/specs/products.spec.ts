import { expect, test, type APIRequestContext } from '@playwright/test';
import { adminContext } from '../helpers/admin';
import { drainAll, expectNoShopperCalls, lastLine, productsFor, startV2 } from '../helpers/agumail';
import { placeGuestOrder } from '../helpers/checkout';
import { SITE_URL, readSite } from '../helpers/env';
import { fakeRequests } from '../helpers/fake';
import { wp, wpEval } from '../helpers/wp';

// Products reach AguMail with the values they have AFTER the save (1.x read them from save_post,
// before WooCommerce had written the new price and SKU), from every way a product is edited: the
// product screen, quick edit and the REST API. A change to the stock alone, which every order makes,
// sends nothing.

test.describe.configure({ mode: 'serial' });

const product = () => readSite().products.find((p) => p.sku === 'AGM-NOTE')!;
const mug = () => readSite().products.find((p) => p.sku === 'AGM-MUG')!;
let appPassword = '';

async function lastSeq(): Promise<number> {
  return (await fakeRequests()).reduce((m, r) => Math.max(m, r.seq), 0);
}

async function productState(id: number): Promise<{ price: string; regular: string; sku: string; stock: number | null; manage: boolean }> {
  const out = await wpEval(
    `$p = wc_get_product(${id}); echo wp_json_encode(array('price' => $p->get_price(), 'regular' => $p->get_regular_price(), 'sku' => $p->get_sku(), 'stock' => $p->get_stock_quantity(), 'manage' => $p->get_manage_stock()));`,
  );
  return JSON.parse(lastLine(out));
}

/** Puts the catalogue product back as setup-store.php made it. */
async function restoreProduct(id: number, sku: string, price: string): Promise<void> {
  await wpEval(
    `$p = wc_get_product(${id}); $p->set_regular_price('${price}'); $p->set_sale_price(''); $p->set_sku('${sku}'); $p->set_manage_stock(false); $p->set_stock_status('instock'); $p->save();`,
  );
}

function rest(request: APIRequestContext) {
  const auth = 'Basic ' + Buffer.from(`admin:${appPassword}`).toString('base64');
  return (id: number, data: Record<string, unknown>) =>
    request.put(`${SITE_URL}/wp-json/wc/v3/products/${id}`, { headers: { Authorization: auth, 'Content-Type': 'application/json' }, data });
}

test.beforeAll(async () => {
  await startV2({ checkout: 'classic' });
  await restoreProduct(product().id, 'AGM-NOTE', '9.50');
  await restoreProduct(mug().id, 'AGM-MUG', '14.00');
  // A REST client of the store, authenticated with an application password (WP_ENVIRONMENT_TYPE is local).
  appPassword = lastLine(await wp('user', 'application-password', 'create', 'admin', `agumail-e2e-${Date.now()}`, '--porcelain'));
  await drainAll();
});

test.afterAll(async () => {
  await restoreProduct(product().id, 'AGM-NOTE', '9.50');
  await restoreProduct(mug().id, 'AGM-MUG', '14.00');
});

test('an edit on the product screen sends the new price and SKU', async ({ browser }) => {
  const since = await lastSeq();
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/post.php?post=${product().id}&action=edit`);
    await page.locator('#_regular_price').fill('11.25');
    await page.locator('li.inventory_options a').click();
    await page.locator('#_sku').fill('AGM-NOTE-A5');
    await page.locator('#publish').click();
    await expect(page.getByText('Product updated.').first()).toBeVisible();
  } finally {
    await admin.close();
  }
  expect(await productState(product().id)).toMatchObject({ regular: '11.25', sku: 'AGM-NOTE-A5' });

  await drainAll();
  const sent = (await productsFor(product().id)).filter((s) => s.request.seq > since);
  expect(sent.length).toBeGreaterThan(0);
  const record = sent[sent.length - 1].record;
  expect(record.price).toBe(11.25);
  expect(record.sku).toBe('AGM-NOTE-A5');
  expect(record.status).toBe('active');
  expect(record.title).toBe('Dot Grid Notebook');
  expectNoShopperCalls();
});

test('quick edit sends the new price and SKU', async ({ browser }) => {
  const since = await lastSeq();
  const admin = await adminContext(browser);
  const page = await admin.newPage();
  try {
    await page.goto(`${SITE_URL}/wp-admin/edit.php?post_type=product`);
    const row = page.locator(`#post-${product().id}`);
    await row.hover();
    await row.locator('button.editinline').click();
    const editor = page.locator(`#edit-${product().id}`);
    await editor.locator('input[name="_regular_price"]').fill('12.75');
    await editor.locator('input[name="_sku"]').fill('AGM-NOTE-QE');
    await editor.locator('button.save').click();
    await expect(page.locator(`#post-${product().id}`)).toContainText('AGM-NOTE-QE');
  } finally {
    await admin.close();
  }
  expect(await productState(product().id)).toMatchObject({ regular: '12.75', sku: 'AGM-NOTE-QE' });

  await drainAll();
  const sent = (await productsFor(product().id)).filter((s) => s.request.seq > since);
  expect(sent.length).toBeGreaterThan(0);
  const record = sent[sent.length - 1].record;
  expect(record.price).toBe(12.75);
  expect(record.sku).toBe('AGM-NOTE-QE');
  expectNoShopperCalls();
});

test('a price change through the REST API sends the new price', async ({ request }) => {
  const since = await lastSeq();
  const res = await rest(request)(product().id, { regular_price: '13.40', sku: 'AGM-NOTE-API' });
  expect(res.status(), await res.text()).toBe(200);
  await drainAll();
  const sent = (await productsFor(product().id)).filter((s) => s.request.seq > since);
  expect(sent.length).toBeGreaterThan(0);
  expect(sent[sent.length - 1].record.price).toBe(13.4);
  expect(sent[sent.length - 1].record.sku).toBe('AGM-NOTE-API');
  expectNoShopperCalls();
});

test('a change to the stock alone sends nothing, from the REST API or an order', async ({ request, browser }) => {
  // Stock management on first (not a field AguMail shows), then drained away.
  const put = rest(request);
  expect((await put(mug().id, { manage_stock: true, stock_quantity: 50 })).status()).toBe(200);
  await drainAll();
  const since = await lastSeq();

  expect((await put(mug().id, { stock_quantity: 42 })).status()).toBe(200);
  expect((await productState(mug().id)).stock).toBe(42);
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    await placeGuestOrder(page, { checkout: 'classic', productId: mug().id, quantity: 2 });
  } finally {
    await context.close();
  }
  expect((await productState(mug().id)).stock).toBe(40);

  await drainAll();
  expect((await productsFor(mug().id)).filter((s) => s.request.seq > since), 'no product record for a stock change').toEqual([]);
  expectNoShopperCalls();
});
