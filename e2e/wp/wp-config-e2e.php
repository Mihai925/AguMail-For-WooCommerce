<?php
/**
 * Included from wp-config.php (WORDPRESS_CONFIG_EXTRA) in the e2e stack.
 *
 * The specs switch behaviour by writing /e2e/state/config.json (host:
 * wordpress-plugin/e2e/.state/config.json), read on every request:
 *   apiUrl    where the plugin sends API calls (default: the fake AguMail by service name)
 *   appUrl    where the browser is sent for one-click connect (default: the fake, host port)
 *   realCron  true re-enables WP-Cron and Action Scheduler's async runner
 *   saveQueries  true turns on SAVEQUERIES (diagnostics: slow.jsonl then lists the slowest queries)
 */

$agumail_e2e_state = array();
if (is_readable('/e2e/state/config.json')) {
    $agumail_e2e_decoded = json_decode((string) file_get_contents('/e2e/state/config.json'), true);
    if (is_array($agumail_e2e_decoded)) {
        $agumail_e2e_state = $agumail_e2e_decoded;
    }
}

define('AGUMAIL_API_URL', !empty($agumail_e2e_state['apiUrl']) ? $agumail_e2e_state['apiUrl'] : 'http://fake-agumail:8090');
define('AGUMAIL_APP_URL', !empty($agumail_e2e_state['appUrl']) ? $agumail_e2e_state['appUrl'] : 'http://localhost:8090');
define('AGUMAIL_E2E_REAL_CRON', !empty($agumail_e2e_state['realCron']));
// Diagnostics only: record every query's time, so slow.jsonl can name the slowest ones.
if (!empty($agumail_e2e_state['saveQueries'])) {
    define('SAVEQUERIES', true);
}

// Deterministic by default: the specs run the queue with wp-cli.
define('DISABLE_WP_CRON', !AGUMAIL_E2E_REAL_CRON);

define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG_LOG', '/e2e/state/debug.log');
define('WP_DEBUG_DISPLAY', false);
define('WP_HOME', 'http://localhost:8089');
define('WP_SITEURL', 'http://localhost:8089');
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);

unset($agumail_e2e_state, $agumail_e2e_decoded);
