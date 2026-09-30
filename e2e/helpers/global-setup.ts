import { FAKE_URL, SITE_URL, readSite, writeStateConfig } from './env';

/**
 * Checks the stack is up, and sets the cron mode for the run:
 * WP-Cron and Action Scheduler's async runner are off unless AGUMAIL_E2E_REAL_CRON=1,
 * so a spec decides when the queue runs (runQueue()).
 */
export default async function globalSetup(): Promise<void> {
  for (const [name, url] of [
    ['WordPress', `${SITE_URL}/wp-login.php`],
    ['fake AguMail', `${FAKE_URL}/__health`],
  ]) {
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
    } catch (err) {
      throw new Error(`${name} is not reachable at ${url} (${(err as Error).message}). Start the stack: ./wp-setup.sh up`);
    }
  }
  readSite();
  writeStateConfig({ realCron: process.env.AGUMAIL_E2E_REAL_CRON === '1' });
}
