import { expect, test, type Page } from '@playwright/test';
import { adminContext, openSettings, section, settingsNotice } from '../helpers/admin';
import { expectNoShopperCalls, fakeSeq, lastLine, startV2 } from '../helpers/agumail';
import { tagRequests, uniqueTag } from '../helpers/checkout';
import { SITE_URL, readSite } from '../helpers/env';
import { fakeRequests } from '../helpers/fake';
import { setCheckout, wpEval } from '../helpers/wp';

// Forms on the storefront: the [agumail_form] shortcode and the agumail/form block print an inert
// placeholder and load AguMail's forms loader once per page, in the footer; the site-wide popup shows
// on storefront pages but never on the cart, checkout or account pages. Pages are built from stored
// options only: rendering one never calls AguMail.

test.describe.configure({ mode: 'serial' });

const pages: Record<'shortcode' | 'block', string> = { shortcode: '', block: '' };

/** A published page with this content, created once (by slug) and kept up to date. */
async function page(slug: string, title: string, content: string): Promise<string> {
  const out = await wpEval(
    `$p = get_page_by_path('${slug}', OBJECT, 'page'); $data = array('post_type' => 'page', 'post_status' => 'publish', 'post_name' => '${slug}', 'post_title' => '${title}', 'post_content' => ${JSON.stringify(content)}); if ($p) { $data['ID'] = $p->ID; wp_update_post($data); $id = $p->ID; } else { $id = wp_insert_post($data); } echo get_permalink($id);`,
  );
  return lastLine(out);
}

const LOADER = 'script[src*="/embed/agumail-forms.js"]';

async function visit(browserPage: Page, url: string): Promise<void> {
  await tagRequests(browserPage, uniqueTag('forms'));
  await browserPage.goto(url);
  await browserPage.waitForLoadState('load');
}

test.beforeAll(async () => {
  await startV2({ checkout: 'classic' });
  await wpEval("delete_option('agumail_popup'); delete_option('agumail_forms_cache');");
  pages.shortcode = await page(
    'agumail-e2e-forms-shortcode',
    'Forms (shortcode)',
    '<!-- wp:shortcode -->[agumail_form slug="newsletter"]<!-- /wp:shortcode -->\n<!-- wp:paragraph --><p>Between the forms.</p><!-- /wp:paragraph -->\n<!-- wp:shortcode -->[agumail_form slug="welcome-popup" mode="popup" delay="8" exit_intent="yes" frequency_days="3"]<!-- /wp:shortcode -->',
  );
  pages.block = await page(
    'agumail-e2e-forms-block',
    'Forms (block)',
    '<!-- wp:agumail/form {"slug":"newsletter"} /-->\n<!-- wp:agumail/form {"slug":"welcome-popup","mode":"popup","scroll":40,"frequencyDays":14} /-->',
  );
});

test.afterAll(async () => {
  await wpEval("delete_option('agumail_popup');");
});

test('Refresh loads the published forms from AguMail into the settings page', async ({ browser }) => {
  const admin = await adminContext(browser);
  const p = await admin.newPage();
  try {
    await openSettings(p);
    await section(p, 'forms').getByRole('button', { name: 'Refresh' }).click();
    await p.waitForURL(/page=agumail/);
    expect(await settingsNotice(p)).toBe('Found 2 published forms.');
    await expect(section(p, 'forms')).toContainText('[agumail_form slug="newsletter"]');
    await expect(section(p, 'forms')).toContainText('[agumail_form slug="welcome-popup"]');
  } finally {
    await admin.close();
  }
  const cache = JSON.parse(lastLine(await wpEval("echo wp_json_encode(get_option('agumail_forms_cache'));")));
  expect(cache.forms.map((f: { slug: string }) => f.slug)).toEqual(['newsletter', 'welcome-popup']);
});

test('the shortcode prints the placeholders and loads the forms script once', async ({ browser }) => {
  const since = await fakeSeq();
  const context = await browser.newContext();
  const p = await context.newPage();
  try {
    await visit(p, pages.shortcode);
    const inline = p.locator('div.agumail-form > script[type="text/plain"][data-agumail-form="newsletter"]');
    await expect(inline).toHaveCount(1);
    expect(await inline.getAttribute('data-mode')).toBe('inline');
    const popup = p.locator('script[type="text/plain"][data-agumail-form="welcome-popup"]');
    await expect(popup).toHaveCount(1);
    // As the server printed it (the fake loader only marks what it rendered).
    expect(await popup.evaluate((el) => Object.fromEntries([...el.attributes].filter((a) => a.name !== 'data-agumail-fake-rendered').map((a) => [a.name, a.value])))).toEqual({
      type: 'text/plain',
      'data-agumail-form': 'welcome-popup',
      'data-mode': 'popup',
      'data-delay': '8',
      'data-exit-intent': 'yes',
      'data-frequency-days': '3',
    });
    await expect(p.locator(LOADER)).toHaveCount(1);
    // In the footer, after the content.
    expect(await p.locator(LOADER).evaluate((el) => !!el.closest('body') && !el.closest('main, article, .entry-content'))).toBe(true);
    // The loader (the fake's stub) found both forms.
    await expect.poll(() => p.evaluate(() => (window as unknown as { AguMailForms?: { rendered: { slug: string }[] } }).AguMailForms?.rendered.map((r) => r.slug).sort())).toEqual(['newsletter', 'welcome-popup']);
  } finally {
    await context.close();
  }
  const loads = (await fakeRequests({ path: '/embed/agumail-forms.js', since })).length;
  expect(loads, 'the loader was fetched once').toBe(1);
  expectNoShopperCalls();
});

test('the block renders the same placeholders as the shortcode', async ({ browser }) => {
  const context = await browser.newContext();
  const p = await context.newPage();
  try {
    await visit(p, pages.block);
    await expect(p.locator('div.agumail-form > script[type="text/plain"][data-agumail-form="newsletter"][data-mode="inline"]')).toHaveCount(1);
    const popup = p.locator('script[type="text/plain"][data-agumail-form="welcome-popup"]');
    // As the server printed it (the fake loader only marks what it rendered).
    expect(await popup.evaluate((el) => Object.fromEntries([...el.attributes].filter((a) => a.name !== 'data-agumail-fake-rendered').map((a) => [a.name, a.value])))).toEqual({
      type: 'text/plain',
      'data-agumail-form': 'welcome-popup',
      'data-mode': 'popup',
      'data-scroll': '40',
      'data-frequency-days': '14',
    });
    await expect(p.locator(LOADER)).toHaveCount(1);
  } finally {
    await context.close();
  }
  expectNoShopperCalls();
});

test('the site-wide popup shows on a product page, never on the cart, checkout or account pages', async ({ browser }) => {
  const admin = await adminContext(browser);
  const settings = await admin.newPage();
  try {
    await openSettings(settings);
    const forms = section(settings, 'forms');
    await forms.locator('#agumail_popup_slug').selectOption('welcome-popup');
    await forms.locator('#agumail_popup_trigger').selectOption('delay');
    await forms.locator('#agumail_popup_delay').fill('4');
    await forms.locator('#agumail_popup_frequency').fill('10');
    await forms.getByRole('button', { name: 'Save popup' }).click();
    await settings.waitForURL(/page=agumail/);
    expect(await settingsNotice(settings)).toBe('Popup saved.');
  } finally {
    await admin.close();
  }

  const site = readSite();
  const context = await browser.newContext();
  const p = await context.newPage();
  try {
    await visit(p, site.products.find((x) => x.sku === 'AGM-TOTE')!.url);
    const popup = p.locator('script[type="text/plain"][data-agumail-form="welcome-popup"]');
    await expect(popup).toHaveCount(1);
    expect(await popup.getAttribute('data-mode')).toBe('popup');
    expect(await popup.getAttribute('data-delay')).toBe('4');
    expect(await popup.getAttribute('data-frequency-days')).toBe('10');
    await expect(p.locator(LOADER)).toHaveCount(1);

    // A page that already places the form, by shortcode or block, does not get it a second time.
    for (const url of [pages.shortcode, pages.block]) {
      await visit(p, url);
      await expect(p.locator('script[type="text/plain"][data-agumail-form="welcome-popup"]'), `one welcome-popup on ${url}`).toHaveCount(1);
      await expect(p.locator(LOADER)).toHaveCount(1);
    }

    // Add something to the cart so the cart and checkout pages render as a shopper sees them.
    await p.goto(`${SITE_URL}/?add-to-cart=${site.products.find((x) => x.sku === 'AGM-TOTE')!.id}`);
    // The store's own cart and checkout pages, with either checkout, and My Account.
    for (const [type, urls] of [
      ['classic', [site.pages.classicCart.url, site.pages.classicCheckout.url, site.pages.myAccount.url]],
      ['block', [site.pages.blockCart.url, site.pages.blockCheckout.url]],
    ] as const) {
      await setCheckout(type);
      for (const url of urls) {
        await visit(p, url);
        await expect(p.locator('script[data-agumail-form]'), `no popup on ${url}`).toHaveCount(0);
        await expect(p.locator(LOADER), `no forms loader on ${url}`).toHaveCount(0);
      }
    }
    await setCheckout('classic');
  } finally {
    await context.close();
  }
  expectNoShopperCalls();
});
