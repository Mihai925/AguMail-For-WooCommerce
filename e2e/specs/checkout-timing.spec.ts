import fs from 'node:fs';
import path from 'node:path';
import { expect, test, type Browser } from '@playwright/test';
import { describeCalls, readHttpAudit, readRequestTimings, readSlowRequests, resetAudit, shopperAguMailCalls } from '../helpers/audit';
import {
  addToCart,
  CHECKOUT_REQUEST,
  checkoutPageUrl,
  checkoutServerTime,
  placeGuestOrder,
  tagRequests,
  uniqueEmail,
  uniqueTag,
  withoutAguMail,
} from '../helpers/checkout';
import { FAKE_INTERNAL_URL, STATE_DIR, UNROUTABLE_URL, pluginUnderTest, readSite, type CheckoutType } from '../helpers/env';
import { fakeMode, fakeRequests, fakeReset } from '../helpers/fake';
import { median, percentile, summary } from '../helpers/stats';
import { activatePlugin, connectPlugin, runQueue, setApiUrl, setCheckout, wpEval } from '../helpers/wp';

// Verification point 4 of the WooCommerce plan: checkout is never slowed.
//
// For each degraded AguMail, with the plugin under test active and connected:
//   a. its API at a non-routable address (http://10.255.255.1:81: connects hang until the client gives up)
//   b. the fake AguMail accepting connections and never answering
// place N classic and N block guest checkouts WITH the plugin, and N classic and N block baseline guest
// checkouts WITHOUT it (N = 15, plus one unmeasured warm-up of each). Then, per checkout type:
//   median(with) - median(baseline) <= 75 ms
//   max(with) <= max(baseline) + 250 ms
//   zero outbound AguMail calls from any request that is not cron, WP-CLI, Action Scheduler or admin
// v2 only: the capture endpoint answers with p95 <= 150 ms (server time).
// Once AguMail answers again, the queue delivers every order placed with the plugin.
//
// Baseline and plugin checkouts alternate (ABBA) inside each scenario. A baseline shopper carries the
// agumail_e2e_plugins_off cookie, so the e2e env mu-plugin loads no AguMail plugin for their requests,
// exactly as if it were inactive. On this Docker host, medians of back-to-back batches drift by 50 to
// 150 ms, which a baseline measured minutes earlier would book against the plugin.
//
// Server time is the checkout request's own PHP time (?wc-ajax=checkout, /wc/store/v1/checkout),
// logged at shutdown by the e2e audit mu-plugin and matched to the sample by its agumail_e2e_tag cookie.
//
// AGUMAIL_E2E_PLUGIN=v1|v2 picks the plugin (default v2). AGUMAIL_E2E_SAMPLES overrides N.
// AGUMAIL_E2E_PLUGIN=none is an A/A run (no plugin on either side): it shows the environment's own noise.
// v1 (1.2.0) is expected to FAIL: it pushes paid orders to AguMail inside the checkout request.

const PLUGIN = pluginUnderTest();
const SAMPLES = Number(process.env.AGUMAIL_E2E_SAMPLES || 15);
const TYPES: CheckoutType[] = ['classic', 'block'];
const RESULTS_DIR = path.join(STATE_DIR, 'timing');

const MEDIAN_BUDGET_MS = 75;
const MAX_BUDGET_MS = 250;
const CAPTURE_P95_MS = 150;
const STALL_MS = 1000;
const MAX_REMEASURES = 2;

type Sample = { orderId: number; serverMs: number; clientMs: number; tag: string };
type Batch = Record<string, Sample[]>;
type Remeasure = { arm: 'baseline' | 'plugin'; type: string; original: Sample; again: Sample; stall: unknown };
type Paired = { baseline: Batch; plugin: Batch; remeasured: Remeasure[] };

const SCENARIOS = [
  { id: 'unroutable', title: `AguMail unreachable (${UNROUTABLE_URL})`, apiUrl: UNROUTABLE_URL, mode: 'ok' as const },
  { id: 'hang', title: 'fake AguMail accepts and never answers', apiUrl: FAKE_INTERNAL_URL, mode: 'hang' as const },
];

function save(name: string, data: unknown): void {
  fs.mkdirSync(RESULTS_DIR, { recursive: true });
  fs.writeFileSync(path.join(RESULTS_DIR, `${name}.json`), JSON.stringify(data, null, 2) + '\n');
}

function load<T>(name: string): T | null {
  const file = path.join(RESULTS_DIR, `${name}.json`);
  return fs.existsSync(file) ? (JSON.parse(fs.readFileSync(file, 'utf8')) as T) : null;
}

async function oneCheckout(browser: Browser, type: CheckoutType, tagPrefix: string, withPlugin: boolean): Promise<Sample> {
  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    if (!withPlugin) await withoutAguMail(page);
    const order = await placeGuestOrder(page, { checkout: type, tag: uniqueTag(tagPrefix), timeoutMs: 180_000 });
    const timing = await checkoutServerTime(order);
    return { orderId: order.orderId, serverMs: timing.ms ?? NaN, clientMs: order.clientMs, tag: order.tag };
  } finally {
    await context.close();
  }
}

/**
 * N baseline and N plugin checkouts per type, alternating ABBA so drift lands on both sides.
 *
 * Machine stalls: this Docker host freezes every PHP request and MariaDB for about 1.7 s, roughly once
 * per 50 to 100 checkouts (slow.jsonl shows a trivial query or no query at all holding the time). A
 * sample more than STALL_MS over the baseline median is placed again in the same arm, at most
 * MAX_REMEASURES times per arm and type; the original is kept in `remeasured` and printed. A plugin
 * that is slow every time stays slow on the second try and still fails.
 */
async function samplePaired(browser: Browser, label: string): Promise<Paired> {
  const out: Paired = { baseline: {}, plugin: {}, remeasured: [] };
  for (const type of TYPES) {
    await setCheckout(type);
    // One unmeasured warm-up of each: opcache, caches, first-load work.
    await oneCheckout(browser, type, `${label}-${type}-warmup-base`, false);
    await oneCheckout(browser, type, `${label}-${type}-warmup-plugin`, true);
    out.baseline[type] = [];
    out.plugin[type] = [];
    for (let i = 0; i < SAMPLES; i++) {
      const pluginFirst = i % 2 === 1;
      for (const withPlugin of pluginFirst ? [true, false] : [false, true]) {
        const sample = await oneCheckout(browser, type, `${label}-${type}-${withPlugin ? 'plugin' : 'base'}-${i}`, withPlugin);
        (withPlugin ? out.plugin : out.baseline)[type].push(sample);
      }
    }
    const limit = median(out.baseline[type].map((x) => x.serverMs)) + STALL_MS;
    for (const arm of ['baseline', 'plugin'] as const) {
      let budget = MAX_REMEASURES;
      const samples = out[arm][type];
      for (let i = 0; i < samples.length && budget > 0; i++) {
        if (!(samples[i].serverMs > limit)) continue;
        budget--;
        const again = await oneCheckout(browser, type, `${label}-${type}-${arm === 'plugin' ? 'plugin' : 'base'}-${i}-again`, arm === 'plugin');
        const stall = readSlowRequests().find((r) => r.tag === samples[i].tag && (r.uri ?? '').includes(CHECKOUT_REQUEST[type]));
        out.remeasured.push({ arm, type, original: samples[i], again, stall: stall?.marks ?? null });
        samples[i] = again;
      }
    }
  }
  return out;
}

function report(label: string, batch: Batch): string {
  return TYPES.map((type) => {
    const s = summary(batch[type].map((x) => x.serverMs));
    return `${label.padEnd(28)} ${type.padEnd(8)} n=${s.n} median=${s.median}ms p95=${s.p95}ms max=${s.max}ms min=${s.min}ms`;
  }).join('\n');
}

// Dozens of short-lived shopper contexts per test: a screenshot or trace of each is noise.
test.use({ screenshot: 'off', trace: 'off' });

const perCheckoutTimeout = 200_000;
const batchTimeout = TYPES.length * 2 * (SAMPLES + 1) * perCheckoutTimeout;

test.describe(`checkout timing, plugin ${PLUGIN}`, () => {
  test.beforeAll(async () => {
    await fakeReset();
  });

  test.afterAll(async () => {
    await fakeMode('ok', { releaseHung: true }).catch(() => undefined);
  });

  for (const scenario of SCENARIOS) {
    test(`${PLUGIN} active, ${scenario.title}: checkout is not slowed`, async ({ browser }) => {
      test.setTimeout(batchTimeout);
      await activatePlugin(PLUGIN);
      await connectPlugin(scenario.apiUrl);
      await fakeReset();
      await fakeMode(scenario.mode);
      resetAudit();

      let result: Paired;
      try {
        result = await samplePaired(browser, `${PLUGIN}-${scenario.id}`);
      } finally {
        await fakeMode('ok', { releaseHung: true });
      }
      const calls = shopperAguMailCalls();
      save(`${PLUGIN}-${scenario.id}`, { ...result, shopperAguMailCalls: calls.length });
      console.log(report(`baseline (${scenario.id})`, result.baseline) + '\n' + report(`${PLUGIN} ${scenario.id}`, result.plugin));
      for (const r of result.remeasured) {
        console.log(`  re-measured ${r.type} ${r.arm}: ${r.original.serverMs} ms (order ${r.original.orderId}) -> ${r.again.serverMs} ms`);
      }

      for (const type of TYPES) {
        const base = result.baseline[type].map((x) => x.serverMs);
        const now = result.plugin[type].map((x) => x.serverMs);
        const medianDelta = median(now) - median(base);
        const maxBudget = Math.max(...base) + MAX_BUDGET_MS;
        expect.soft(medianDelta, `${type}: median server time grew by ${Math.round(medianDelta)} ms`).toBeLessThanOrEqual(MEDIAN_BUDGET_MS);
        expect.soft(Math.max(...now), `${type}: slowest checkout vs baseline max + ${MAX_BUDGET_MS} ms`).toBeLessThanOrEqual(maxBudget);
      }
      expect.soft(calls.length, `AguMail calls from shopper requests:\n${describeCalls(calls)}`).toBe(0);
    });
  }

  test(`${PLUGIN}: email capture answers fast (p95 <= ${CAPTURE_P95_MS} ms)`, async ({ browser }) => {
    test.skip(PLUGIN !== 'v2', 'only v2 has the capture endpoint (?wc-ajax=agumail_capture)');
    test.setTimeout(TYPES.length * SAMPLES * 60_000);
    await activatePlugin(PLUGIN);
    await connectPlugin(UNROUTABLE_URL);
    resetAudit();

    const serverMs: number[] = [];
    const missing: string[] = [];
    for (const type of TYPES) {
      await setCheckout(type);
      for (let i = 0; i < SAMPLES; i++) {
        const context = await browser.newContext();
        const page = await context.newPage();
        const tag = uniqueTag(`capture-${type}-${i}`);
        try {
          await tagRequests(page, tag);
          await addToCart(page, readSite().products.find((p) => p.type === 'simple')!.id);
          await page.goto(checkoutPageUrl(type));
          const email = page.locator(type === 'block' ? '#email' : '#billing_email');
          const captured = page
            .waitForResponse((r) => r.url().includes('wc-ajax=agumail_capture'), { timeout: 15_000 })
            .catch(() => null);
          await email.fill(uniqueEmail('capture'));
          await email.press('Tab');
          if (!(await captured)) {
            missing.push(`${type} #${i}`);
            continue;
          }
          const deadline = Date.now() + 5_000;
          let line = undefined as ReturnType<typeof readRequestTimings>[number] | undefined;
          while (!line && Date.now() < deadline) {
            line = readRequestTimings().find((t) => t.tag === tag && (t.uri ?? '').includes('wc-ajax=agumail_capture'));
            if (!line) await new Promise((r) => setTimeout(r, 100));
          }
          if (line?.ms != null) serverMs.push(line.ms);
          else missing.push(`${type} #${i} (no timing line)`);
        } finally {
          await context.close();
        }
      }
    }
    save(`${PLUGIN}-capture`, { serverMs, missing });
    console.log(`capture: ${JSON.stringify(summary(serverMs))}`);
    expect.soft(missing, 'captures that never reached the server').toEqual([]);
    expect.soft(percentile(serverMs, 95), 'capture p95 server ms').toBeLessThanOrEqual(CAPTURE_P95_MS);
    const calls = shopperAguMailCalls();
    expect.soft(calls.length, `AguMail calls from shopper requests:\n${describeCalls(calls)}`).toBe(0);
  });

  test(`${PLUGIN}: the queue delivers every order once AguMail answers again`, async () => {
    test.skip(PLUGIN === 'none', 'A/A run: no plugin, no queue');
    test.setTimeout(10 * 60_000);
    const expected = SCENARIOS.flatMap((s) => {
      const saved = load<Paired>(`${PLUGIN}-${s.id}`);
      if (!saved) return [];
      // Every order placed with the plugin active, including the ones a re-measure replaced.
      const replaced = (saved.remeasured ?? []).filter((r) => r.arm === 'plugin').map((r) => r.original);
      return [...TYPES.flatMap((t) => saved.plugin[t]), ...replaced].map((x) => String(x.orderId));
    });
    if (!expected.length) throw new Error('No orders from the degraded scenarios: run the whole file.');

    await activatePlugin(PLUGIN);
    await setApiUrl(FAKE_INTERNAL_URL);
    await fakeMode('ok', { releaseHung: true });
    const since = (await fakeRequests()).reduce((m, r) => Math.max(m, r.seq), 0);
    resetAudit();

    let missing = expected;
    for (let attempt = 0; attempt < 5 && missing.length; attempt++) {
      await runQueue({ dueNow: true });
      const delivered = new Set<string>();
      for (const r of await fakeRequests({ path: '/api/v1/orders', method: 'POST', since })) {
        if (r.status !== 200) continue;
        for (const o of ((r.body as { orders?: { externalId?: string }[] })?.orders ?? [])) delivered.add(String(o.externalId));
      }
      missing = expected.filter((id) => !delivered.has(id));
    }
    const shopperCalls = shopperAguMailCalls(readHttpAudit());
    save(`${PLUGIN}-drain`, { expected: expected.length, missing });
    expect(missing, `${missing.length} of ${expected.length} orders never reached AguMail`).toEqual([]);
    expect(shopperCalls.length, describeCalls(shopperCalls)).toBe(0);
    if (PLUGIN === 'v2') {
      // Drained: none of these orders is still waiting, or was set aside, in the outbox.
      const left = await wpEval(
        `global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}agumail_outbox WHERE type = 'order' AND object_key IN (${expected.map((id) => `'${Number(id)}'`).join(',')})");`,
      );
      expect(Number(left.split('\n').pop()), 'order rows left in the outbox').toBe(0);
    }
  });
});
