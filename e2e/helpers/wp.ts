import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { COMPOSE_PROJECT, E2E_DIR, writeStateConfig, type CheckoutType, type PluginUnderTest } from './env';

const run = promisify(execFile);

/** Runs wp-cli in the stack's wpcli container and returns stdout (trimmed). */
export async function wp(...args: string[]): Promise<string> {
  try {
    const { stdout } = await run('docker', ['compose', '-p', COMPOSE_PROJECT, 'exec', '-T', 'wpcli', 'wp', ...args], {
      cwd: E2E_DIR,
      maxBuffer: 64 * 1024 * 1024,
      timeout: 10 * 60 * 1000,
    });
    return stdout.trim();
  } catch (err) {
    const e = err as { stderr?: string; stdout?: string; message: string };
    throw new Error(`wp ${args.join(' ')} failed: ${e.stderr || e.stdout || e.message}`);
  }
}

/** Runs PHP inside WordPress (wp eval). */
export function wpEval(php: string): Promise<string> {
  return wp('eval', php);
}

export async function setCheckout(type: CheckoutType): Promise<void> {
  await wp('eval-file', '/e2e/wp/switch-checkout.php', type);
}

export async function checkoutWcAvailable(): Promise<boolean> {
  const out = await wpEval("echo file_exists(WP_PLUGIN_DIR . '/checkout-for-woocommerce/checkout-for-woocommerce.php') ? 'yes' : 'no';");
  return out.endsWith('yes');
}

const PLUGIN_SLUGS: Record<PluginUnderTest, string> = { v1: 'agumail-v1', v2: 'agumail' };

/** Which AguMail plugin is active: none, v1 (the 1.2.0 fixture) or v2 (agumail). */
export async function activatePlugin(which: 'none' | PluginUnderTest): Promise<void> {
  const active = (await wp('plugin', 'list', '--status=active', '--field=name')).split('\n');
  for (const [version, slug] of Object.entries(PLUGIN_SLUGS)) {
    if (version !== which && active.includes(slug)) await wp('plugin', 'deactivate', slug);
  }
  if (which !== 'none' && !active.includes(PLUGIN_SLUGS[which])) {
    await wp('plugin', 'activate', PLUGIN_SLUGS[which]);
  }
}

/**
 * Gives the plugin a key and points it at apiUrl, as if the store had connected (wp/connect.php):
 * v1 reads the agumail_api_url / agumail_api_key options; v2 reads AGUMAIL_API_URL (wp-config, from
 * .state/config.json), the key option, and the connection/config options from the contracts.
 */
export async function connectPlugin(apiUrl: string): Promise<void> {
  writeStateConfig({ apiUrl });
  await wp('eval-file', '/e2e/wp/connect.php', apiUrl);
}

/** Points an already connected plugin somewhere else (the constant for v2, the option for v1). */
export async function setApiUrl(apiUrl: string): Promise<void> {
  writeStateConfig({ apiUrl });
  await wpEval(`update_option('agumail_api_url', ${phpString(apiUrl)});`);
}

/**
 * Runs the Action Scheduler queue for the agumail group once, as WP-Cron would: only actions that are due.
 * dueNow first pulls every pending agumail action to now (time travel past the recurring interval or a
 * backoff), for specs that only care that the work happens.
 */
export async function runQueue(options: { dueNow?: boolean } = {}): Promise<string> {
  if (options.dueNow) {
    await wpEval(
      "global $wpdb; $wpdb->query(\"UPDATE {$wpdb->prefix}actionscheduler_actions a JOIN {$wpdb->prefix}actionscheduler_groups g ON a.group_id = g.group_id SET a.scheduled_date_gmt = UTC_TIMESTAMP(), a.scheduled_date_local = UTC_TIMESTAMP() WHERE a.status = 'pending' AND g.slug = 'agumail'\");",
    );
  }
  try {
    return await wp('action-scheduler', 'run', '--group=agumail');
  } catch (err) {
    if (!/group "agumail" does not exist/.test((err as Error).message)) throw err;
    // Nothing was ever scheduled in the group (v1 has no queue): there is nothing to run.
    if (!(await agumailGroupExists())) return '';
    // The group is there: Action Scheduler is back in its hybrid (posts + tables) store, which refuses
    // group runs. It goes back there on EVERY plugin deactivation (ActionScheduler_DataController marks
    // its migration incomplete) and only its own queue runner, off here, would finish the move again.
    await completeActionSchedulerMigration();
    return wp('action-scheduler', 'run', '--group=agumail');
  }
}

/** Finishes Action Scheduler's move to its own tables (see runQueue). A no-op when it is done. */
export async function completeActionSchedulerMigration(): Promise<void> {
  const done = await wpEval("echo ActionScheduler_DataController::is_migration_complete() ? 'yes' : 'no';");
  if (done.endsWith('yes')) return;
  await wp('action-scheduler', 'migrate');
}

async function agumailGroupExists(): Promise<boolean> {
  const out = await wpEval(
    "global $wpdb; echo $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_groups WHERE slug = 'agumail'\") ? 'yes' : 'no';",
  );
  return out.endsWith('yes');
}

/**
 * Runs the queue until every agumail action that is due now has run, pulling each round's new work to
 * now, as a cron that fires every minute would. `until` stops early once it says so.
 */
export async function drainQueue(options: { rounds?: number; until?: () => Promise<boolean> } = {}): Promise<void> {
  const rounds = options.rounds ?? 10;
  for (let i = 0; i < rounds; i++) {
    await runQueue({ dueNow: true });
    if (options.until && (await options.until())) return;
    if (!options.until && !(await pendingDueWork())) return;
  }
}

/** Outbox rows or cart rows still waiting (any availability), for "drain until empty". */
export async function pendingDueWork(): Promise<boolean> {
  const out = await wpEval(
    "global $wpdb; $o = (int) $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}agumail_outbox WHERE status = 'pending'\"); $c = (int) $wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}agumail_carts WHERE dirty = 1\"); echo $o + $c;",
  );
  return Number(out.split('\n').pop()) > 0;
}

/** Makes every waiting outbox row due now (time travel past a retry backoff) and lifts a 429 pause. */
export async function outboxDueNow(): Promise<void> {
  await wpEval(
    "global $wpdb; $wpdb->query(\"UPDATE {$wpdb->prefix}agumail_outbox SET available_at = UTC_TIMESTAMP() WHERE status = 'pending'\"); delete_option('agumail_backoff_until');",
  );
}

/** Real WP-Cron and Action Scheduler's async runner (off by default: the specs run the queue). */
export function setRealCron(on: boolean): void {
  writeStateConfig({ realCron: on });
}

export async function orderStatus(orderId: number): Promise<string> {
  return wpEval(`$o = wc_get_order(${Number(orderId)}); echo $o ? $o->get_status() : 'missing';`);
}

function phpString(value: string): string {
  return "'" + value.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}
