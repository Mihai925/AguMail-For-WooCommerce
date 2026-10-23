import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const E2E_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const STATE_DIR = path.join(E2E_DIR, '.state');
export const AUDIT_DIR = path.join(STATE_DIR, 'audit');
export const COMPOSE_PROJECT = 'agumail-woo-e2e';

/** The store, as the browser (and WordPress itself) reaches it. */
export const SITE_URL = 'http://localhost:8089';
/** The fake AguMail from the host (browser, specs). */
export const FAKE_URL = 'http://localhost:8090';
/** The fake AguMail from inside the WordPress containers. */
export const FAKE_INTERNAL_URL = 'http://fake-agumail:8090';
/** Non-routable: connects hang until the client times out. */
export const UNROUTABLE_URL = 'http://10.255.255.1:81';
/** The key the specs give the plugin (the fake accepts any agm_ key). */
export const E2E_API_KEY = 'agm_e2e0000000000000000000000000000000000';

export type CheckoutType = 'classic' | 'block' | 'checkoutwc';
export type PluginUnderTest = 'v1' | 'v2';

/**
 * AGUMAIL_E2E_PLUGIN=v1|v2 (default v2, the plugin in agumail).
 * `none` runs a spec with no AguMail plugin at all: an A/A calibration of the environment's noise.
 */
export function pluginUnderTest(): PluginUnderTest | 'none' {
  const value = (process.env.AGUMAIL_E2E_PLUGIN || 'v2').toLowerCase();
  if (value !== 'v1' && value !== 'v2' && value !== 'none') {
    throw new Error(`AGUMAIL_E2E_PLUGIN must be v1, v2 or none, got "${value}"`);
  }
  return value;
}

export type SiteProduct = {
  id: number;
  sku: string;
  name: string;
  type: 'simple' | 'variable';
  price?: string;
  slug: string;
  url: string;
  variations?: { id: number; sku: string; attribute: string; value: string; price: string }[];
};

export type Site = {
  url: string;
  admin: { user: string; password: string };
  pages: Record<'blockCheckout' | 'blockCart' | 'classicCheckout' | 'classicCart' | 'myAccount' | 'shop', { id: number; url: string }>;
  products: SiteProduct[];
};

export function readSite(): Site {
  const file = path.join(STATE_DIR, 'site.json');
  if (!fs.existsSync(file)) throw new Error('No .state/site.json: run ./wp-setup.sh up first.');
  return JSON.parse(fs.readFileSync(file, 'utf8')) as Site;
}

export type SeedSummary = {
  orders: number;
  paidOrders: number;
  customers: number;
  registered: number;
  guests: number;
  optedInRegistered: number;
  optedInGuests: number;
  ordersWithin72h: number;
  oldestOrder: string | null;
  newestOrder: string | null;
  emailPattern: string;
};

export function readSeed(): SeedSummary {
  const file = path.join(STATE_DIR, 'seed.json');
  if (!fs.existsSync(file)) throw new Error('No .state/seed.json: run ./wp-setup.sh seed first.');
  return JSON.parse(fs.readFileSync(file, 'utf8')) as SeedSummary;
}

export type StateConfig = { apiUrl?: string; appUrl?: string; realCron?: boolean };

/** .state/config.json is read by wp-config.php on every request (AGUMAIL_API_URL, cron switch). */
export function writeStateConfig(patch: StateConfig): StateConfig {
  const file = path.join(STATE_DIR, 'config.json');
  let current: StateConfig = {};
  try {
    current = JSON.parse(fs.readFileSync(file, 'utf8')) as StateConfig;
  } catch {
    current = {};
  }
  const next = { ...current, ...patch };
  fs.writeFileSync(file, JSON.stringify(next, null, 2) + '\n');
  return next;
}

export function readStateConfig(): StateConfig {
  try {
    return JSON.parse(fs.readFileSync(path.join(STATE_DIR, 'config.json'), 'utf8')) as StateConfig;
  } catch {
    return {};
  }
}
