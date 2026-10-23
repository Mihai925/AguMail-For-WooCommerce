<?php
// The plugin's own tables, and the only code that reads or writes them.
//
// The outbox holds one row per thing AguMail must hear about (an order, a
// product, a contact...). A storefront hook records a change with one upsert
// and moves on; the drain builds the record and sends it later. A row keeps a
// version that every change bumps, so a row that changed while it was being
// sent is kept and sent again rather than deleted.
//
// The carts table holds each shopper's live cart. A cart change is one write to
// its row, which bumps its version and raises its dirty flag; the drain moves
// flagged rows into the outbox and lowers the flag only if the cart has not
// changed again since it read it.
//
// Agumail_Store talks to MySQL through $wpdb. The test harness swaps in an
// in-memory store with the same methods through agumail_set_store().

if (!defined('ABSPATH')) {
    exit;
}

// Every table name is $wpdb->prefix plus a fixed name, written into the SQL;
// every value goes through prepare(). Direct queries are the point of this
// class: it is a queue in the plugin's own tables, where a cached row would be
// a stale one.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own queue tables, read and written live.

define('AGUMAIL_KEY_MAX', 191);
define('AGUMAIL_ERROR_MAX', 1000);
define('AGUMAIL_CART_COLUMNS', 'token,session_key,user_id,email,lines,total,currency,status,order_id,ip_hash');

class Agumail_Store {

    public function outbox_table() {
        global $wpdb;
        return $wpdb->prefix . 'agumail_outbox';
    }

    public function carts_table() {
        global $wpdb;
        return $wpdb->prefix . 'agumail_carts';
    }

    // ── Outbox ──────────────────────────────────────────────────────────────

    /**
     * One statement: a new row, or the existing (type, key) row with its
     * payload replaced, its version bumped, and made due now. Attempts are
     * kept, so a row that keeps failing still parks in the end.
     */
    public function enqueue($type, $key, $payload, $now) {
        global $wpdb;
        if ($payload === null) {
            $sql = $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}agumail_outbox (`type`, `object_key`, `payload`, `version`, `attempts`, `status`, `available_at`, `created_at`, `updated_at`)
                 VALUES (%s, %s, NULL, 1, 0, 'pending', %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `payload` = NULL, `version` = `version` + 1, `status` = 'pending', `available_at` = %s, `updated_at` = %s",
                $type, $key, $now, $now, $now, $now, $now
            );
        } else {
            $sql = $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}agumail_outbox (`type`, `object_key`, `payload`, `version`, `attempts`, `status`, `available_at`, `created_at`, `updated_at`)
                 VALUES (%s, %s, %s, 1, 0, 'pending', %s, %s, %s)
                 ON DUPLICATE KEY UPDATE `payload` = %s, `version` = `version` + 1, `status` = 'pending', `available_at` = %s, `updated_at` = %s",
                $type, $key, $payload, $now, $now, $now, $payload, $now, $now
            );
        }
        return $this->write($sql, sprintf('queue %s %s', $type, $key));
    }

    /** Runs a write, once more if the first try fails (a deadlock or lock wait), and logs a second failure. */
    private function write($sql, $what) {
        global $wpdb;
        for ($try = 1; $try <= 2; $try++) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every caller passes the output of $wpdb->prepare().
            if ($wpdb->query($sql) !== false) {
                return true;
            }
        }
        agumail_log('error', sprintf('Could not %s: %s', $what, $wpdb->last_error));
        return false;
    }

    /** Whether a run has anything to do: a due row, or a cart that changed. Two indexed lookups. */
    public function has_work($now) {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'pending' AND `available_at` <= %s LIMIT 1", $now))) {
            return true;
        }
        return (bool) $wpdb->get_var("SELECT 1 FROM {$wpdb->prefix}agumail_carts WHERE `dirty` = 1 LIMIT 1");
    }

    public function find($type, $key) {
        global $wpdb;
        return $this->outbox_row($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}agumail_outbox WHERE `type` = %s AND `object_key` = %s",
            $type, $key
        ), ARRAY_A));
    }

    public function get($id) {
        global $wpdb;
        return $this->outbox_row($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d", $id), ARRAY_A));
    }

    /** Pending rows of a type that are due, oldest first, after a given id. */
    public function due($type, $limit, $now, $after_id = 0) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}agumail_outbox
             WHERE `status` = 'pending' AND `type` = %s AND `available_at` <= %s AND `id` > %d
             ORDER BY `id` ASC LIMIT %d",
            $type, $now, $after_id, $limit
        ), ARRAY_A);
        return array_map(array($this, 'outbox_row'), is_array($rows) ? $rows : array());
    }

    /** Sent: the row goes, unless it changed meanwhile; then it stays for the next run, its failures forgiven. */
    public function complete($id, $version) {
        global $wpdb;
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `version` = %d", $id, $version));
        if ($deleted) {
            return true;
        }
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}agumail_outbox SET `attempts` = 0, `last_error` = NULL WHERE `id` = %d", $id));
        return false;
    }

    /** Dropped by its builder: the row goes, unless it changed meanwhile. */
    public function drop($id, $version) {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `version` = %d", $id, $version));
    }

    /** AguMail refused the record itself: it waits in the failed list, unless it changed meanwhile. */
    public function park($id, $version, $error, $now) {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}agumail_outbox SET `status` = 'failed', `last_error` = %s, `updated_at` = %s WHERE `id` = %d AND `version` = %d",
            $error, $now, $id, $version
        ));
    }

    /** Could not be delivered: try again at $available_at, or park it for good when $failed. */
    public function defer($id, $attempts, $available_at, $error, $failed, $now) {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}agumail_outbox SET `attempts` = %d, `available_at` = %s, `last_error` = %s, `status` = %s, `updated_at` = %s WHERE `id` = %d",
            $attempts, $available_at, $error, $failed ? 'failed' : 'pending', $now, $id
        ));
    }

    public function retry($id, $now) {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}agumail_outbox SET `status` = 'pending', `attempts` = 0, `available_at` = %s, `last_error` = NULL, `updated_at` = %s WHERE `id` = %d",
            $now, $now, $id
        ));
    }

    public function retry_failed($now) {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}agumail_outbox SET `status` = 'pending', `attempts` = 0, `available_at` = %s, `last_error` = NULL, `updated_at` = %s WHERE `status` = 'failed'",
            $now, $now
        ));
    }

    /** A failed row goes; with $version, only if it has not changed since (a newer answer is never dropped). */
    public function discard($id, $version = null) {
        global $wpdb;
        if ($version !== null) {
            return (bool) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `status` = 'failed' AND `version` = %d", $id, $version));
        }
        return (bool) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `id` = %d AND `status` = 'failed'", $id));
    }

    public function discard_failed() {
        global $wpdb;
        return (int) $wpdb->query("DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'failed'");
    }

    /**
     * Waiting rows of the given types go: all of them, or with $before only
     * those queued before it. Failed rows stay, for the merchant to see.
     */
    public function discard_pending(array $types, $before = null) {
        global $wpdb;
        list($type_sql, $type_args) = $this->in_clause('type', $types);
        if ($type_sql === '') {
            return 0;
        }
        $before_sql = $before !== null ? ' AND `updated_at` < %s' : '';
        $args = $before !== null ? array_merge($type_args, array($before)) : $type_args;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $type_sql is an IN () list of placeholders and $before_sql one placeholder; their values are in the arguments.
        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- as above.
            "DELETE FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'pending'{$type_sql}{$before_sql}",
            $args
        ));
    }

    /** Every row, pending or failed: the queue belonged to another AguMail account. */
    public function clear() {
        global $wpdb;
        return (int) $wpdb->query("DELETE FROM {$wpdb->prefix}agumail_outbox");
    }

    /** Queue depth for the status panel: counts by status and type, due now, and the oldest pending row. */
    public function stats($now) {
        global $wpdb;
        $stats = array('pending' => 0, 'failed' => 0, 'due' => 0, 'oldest_pending_at' => null, 'by_type' => array());
        $rows = $wpdb->get_results(
            "SELECT `status`, `type`, COUNT(*) AS total, MIN(`created_at`) AS oldest FROM {$wpdb->prefix}agumail_outbox GROUP BY `status`, `type`",
            ARRAY_A
        );
        foreach (is_array($rows) ? $rows : array() as $row) {
            $status = $row['status'] === 'failed' ? 'failed' : 'pending';
            $stats[$status] += (int) $row['total'];
            if (!isset($stats['by_type'][$row['type']])) {
                $stats['by_type'][$row['type']] = array('pending' => 0, 'failed' => 0);
            }
            $stats['by_type'][$row['type']][$status] += (int) $row['total'];
            if ($status === 'pending' && ($stats['oldest_pending_at'] === null || $row['oldest'] < $stats['oldest_pending_at'])) {
                $stats['oldest_pending_at'] = $row['oldest'];
            }
        }
        $stats['due'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'pending' AND `available_at` <= %s",
            $now
        ));
        return $stats;
    }

    public function failed($limit, $offset) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}agumail_outbox WHERE `status` = 'failed' ORDER BY `updated_at` DESC, `id` DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A);
        return array_map(array($this, 'outbox_row'), is_array($rows) ? $rows : array());
    }

    public function outbox_row($row) {
        return $row ? agumail_outbox_row_types($row) : null;
    }

    // ── Carts ───────────────────────────────────────────────────────────────

    public function cart_get($id) {
        global $wpdb;
        return $this->cart_row($wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `id` = %s", $id), ARRAY_A));
    }

    /** The newest cart for a WooCommerce session, among the given statuses (any when empty). */
    public function cart_find_by_session($session_key, array $statuses) {
        global $wpdb;
        list($status_sql, $status_args) = $this->in_clause('status', $statuses);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $status_sql is an IN () list of placeholders; its values are in the arguments.
        $row = $wpdb->get_row($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_sql is an IN () list of placeholders; its values are in the arguments.
            "SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `session_key` = %s{$status_sql} ORDER BY `updated_at` DESC LIMIT 1",
            array_merge(array($session_key), $status_args)
        ), ARRAY_A);
        return $this->cart_row($row);
    }

    public function carts_by_email($email, array $statuses, $limit) {
        global $wpdb;
        list($status_sql, $status_args) = $this->in_clause('status', $statuses);
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the IN () list adds placeholders; their values are in the same array.
        $rows = $wpdb->get_results($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_sql is an IN () list of placeholders; its values are in the arguments.
            "SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `email` = %s{$status_sql} ORDER BY `updated_at` DESC LIMIT %d",
            array_merge(array($email), $status_args, array($limit))
        ), ARRAY_A);
        return array_map(array($this, 'cart_row'), is_array($rows) ? $rows : array());
    }

    /**
     * One statement: inserts the cart, or updates the given columns of an
     * existing one; either way the version moves on and the cart is flagged
     * as changed. $insert_only values (a new cart's token) apply to an insert.
     */
    public function cart_save($id, array $fields, $now, array $insert_only = array()) {
        global $wpdb;
        $fields = agumail_cart_known_columns($fields);
        $insert_only = agumail_cart_known_columns($insert_only);
        $defaults = array('token' => '', 'session_key' => '', 'user_id' => null, 'email' => null, 'lines' => '[]',
            'total' => '0', 'currency' => '', 'status' => 'open', 'order_id' => null);
        $insert = array_merge($defaults, $insert_only, $fields);
        $columns = array('`id`');
        $values = array('%s');
        $args = array($id);
        foreach ($insert as $column => $value) {
            $columns[] = '`' . $column . '`';
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                $values[] = '%s';
                $args[] = (string) $value;
            }
        }
        $updates = array();
        foreach ($fields as $column => $value) {
            if ($value === null) {
                $updates[] = '`' . $column . '` = NULL';
            } else {
                $updates[] = '`' . $column . '` = %s';
            }
        }
        $update_args = array_values(array_map('strval', array_filter($fields, function ($value) { return $value !== null; })));
        $updates[] = '`version` = `version` + 1';
        $updates[] = '`dirty` = 1';
        $updates[] = '`updated_at` = %s';
        $sql = "INSERT INTO {$wpdb->prefix}agumail_carts (" . implode(', ', $columns) . ', `version`, `queued_version`, `dirty`, `created_at`, `updated_at`)'
            . ' VALUES (' . implode(', ', $values) . ', 1, 0, 1, %s, %s)'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above from the table name, whitelisted column names and placeholders only.
        return $this->write($wpdb->prepare($sql, array_merge($args, array($now, $now), $update_args, array($now))), 'save cart ' . $id);
    }

    /** Updates some columns of a cart; $bump makes the change one the drain will send. */
    public function cart_update($id, array $fields, $bump, $now) {
        global $wpdb;
        $fields = agumail_cart_known_columns($fields);
        $sets = array();
        $args = array();
        foreach ($fields as $column => $value) {
            if ($value === null) {
                $sets[] = '`' . $column . '` = NULL';
            } else {
                $sets[] = '`' . $column . '` = %s';
                $args[] = (string) $value;
            }
        }
        if ($bump) {
            $sets[] = '`version` = `version` + 1';
            $sets[] = '`dirty` = 1';
        }
        $sets[] = '`updated_at` = %s';
        $args[] = $now;
        $args[] = $id;
        $sql = "UPDATE {$wpdb->prefix}agumail_carts SET " . implode(', ', $sets) . ' WHERE `id` = %s';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built above from the table name, whitelisted column names and placeholders only.
        return (bool) $wpdb->query($wpdb->prepare($sql, $args));
    }

    /** Carts that changed since they were last moved into the outbox, found by their indexed flag. */
    public function carts_dirty($limit) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}agumail_carts WHERE `dirty` = 1 ORDER BY `updated_at` ASC LIMIT %d",
            $limit
        ), ARRAY_A);
        return array_map(array($this, 'cart_row'), is_array($rows) ? $rows : array());
    }

    /** Records the version moved into the outbox; the flag stays up if the cart changed again meanwhile. */
    public function cart_mark_queued($id, $version) {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}agumail_carts SET `queued_version` = %d, `dirty` = IF(`version` > %d, 1, 0) WHERE `id` = %s AND `queued_version` < %d",
            $version, $version, $id, $version
        ));
    }

    /** No cart waits to be moved into the outbox any more (store sync was held). */
    public function carts_clear_dirty() {
        global $wpdb;
        return (int) $wpdb->query("UPDATE {$wpdb->prefix}agumail_carts SET `dirty` = 0 WHERE `dirty` = 1");
    }

    /** How many carts were created from one address hash since $since. One indexed count. */
    public function carts_count_by_ip($ip_hash, $since) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}agumail_carts WHERE `ip_hash` = %s AND `created_at` >= %s",
            $ip_hash, $since
        ));
    }

    /** Deletes carts last touched before $before, among the given statuses (any when empty). */
    public function carts_purge(array $statuses, $before) {
        global $wpdb;
        list($status_sql, $status_args) = $this->in_clause('status', $statuses);
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $status_sql is an IN () list of placeholders; its values are in the arguments.
        return (int) $wpdb->query($wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $status_sql is an IN () list of placeholders; its values are in the arguments.
            "DELETE FROM {$wpdb->prefix}agumail_carts WHERE `updated_at` < %s{$status_sql}",
            array_merge(array($before), $status_args)
        ));
    }

    public function cart_row($row) {
        return $row ? agumail_cart_row_types($row) : null;
    }

    // ── Locks ───────────────────────────────────────────────────────────────
    // An option row holding "token|expiry", taken with an insert or, once
    // expired, a compare-and-swap, as Action Scheduler takes its own lock.

    public function acquire_lock($name, $ttl, $now) {
        global $wpdb;
        $token = wp_generate_uuid4();
        $value = $token . '|' . ((int) $now + (int) $ttl);
        $existing = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        if ($existing === '') {
            $taken = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $name, $value
            ));
        } else {
            $parts = explode('|', $existing);
            if ((int) end($parts) >= (int) $now) {
                return false;
            }
            $taken = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $value, $name, $existing
            ));
        }
        wp_cache_delete($name, 'options');
        return $taken ? $token : false;
    }

    public function release_lock($name, $token) {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            $name, $wpdb->esc_like($token . '|') . '%'
        ));
        wp_cache_delete($name, 'options');
    }

    private function in_clause($column, array $values) {
        if (!$values) {
            return array('', array());
        }
        return array(' AND `' . $column . '` IN (' . implode(', ', array_fill(0, count($values), '%s')) . ')', array_values($values));
    }
}

// phpcs:enable

// ─────────────────────────────────────────────────────────────────────────────
// Which store, and what time it is. The harness replaces both.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_store() {
    if (!isset($GLOBALS['agumail_store'])) {
        $GLOBALS['agumail_store'] = new Agumail_Store();
    }
    return $GLOBALS['agumail_store'];
}

function agumail_set_store($store) {
    $GLOBALS['agumail_store'] = $store;
}

/** Seconds since the epoch, with fractions: the drain's clock. */
function agumail_clock() {
    return isset($GLOBALS['agumail_clock']) && is_callable($GLOBALS['agumail_clock'])
        ? (float) call_user_func($GLOBALS['agumail_clock'])
        : microtime(true);
}

/** Replaces the clock (null restores the real one). */
function agumail_set_clock($clock) {
    $GLOBALS['agumail_clock'] = $clock;
}

function agumail_time() {
    return (int) floor(agumail_clock());
}

/** A UTC DATETIME string for the tables. */
function agumail_mysql_time($timestamp = null) {
    return gmdate('Y-m-d H:i:s', $timestamp === null ? agumail_time() : (int) $timestamp);
}

// ─────────────────────────────────────────────────────────────────────────────
// The outbox.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Records that AguMail must hear about something, and nothing more: one upsert,
 * no call. It is the only write a storefront hook may make, besides the carts
 * row. $payload, when given, is stored as JSON and replaces the one before; a
 * builder usually builds the record at send time instead, from fresh data.
 *
 * Does nothing (and returns false) before the tables exist, while the store is
 * not connected, or inside the drain, whose own meta writes must not queue the
 * same things again.
 */
function agumail_enqueue($type, $key, $payload = null) {
    if (!agumail_ready() || !agumail_has_api_key() || agumail_enqueue_suppressed() || !agumail_may_queue($type)) {
        return false;
    }
    $type = (string) $type;
    $key = agumail_outbox_key($type, $key);
    if ($key === '') {
        return false;
    }
    if (is_array($payload) && agumail_is_contact_type($type)) {
        $existing = agumail_store()->find($type, $key);
        if ($existing && $existing['status'] === 'pending' && is_array($existing['payload'])) {
            $payload = agumail_merge_queued_contact($existing['payload'], $payload);
        }
    }
    $json = null;
    if ($payload !== null) {
        $json = wp_json_encode($payload);
        if ($json === false) {
            agumail_log('error', sprintf('Could not queue %s %s: its data is not valid JSON.', $type, $key));
            return false;
        }
    }
    return agumail_store()->enqueue($type, $key, $json, agumail_mysql_time());
}

/**
 * Store data is queued only for a site AguMail took as a store: carts while
 * store sync is on, orders and products also while the plan holds it, so they
 * go once it is back. Everything else (contacts, accounts, signups, checkout
 * subscribers) works on every plan.
 */
function agumail_may_queue($type) {
    if ($type === 'cart') {
        return agumail_store_sync_on();
    }
    if ($type === 'order' || $type === 'product') {
        return agumail_store_connected();
    }
    return true;
}

/**
 * The key a row is stored under: contacts by lowercased email, and anything
 * longer than the column holds shortened with a hash, so it stays unique.
 */
function agumail_outbox_key($type, $key) {
    $key = trim((string) $key);
    if (agumail_is_contact_type($type)) {
        $key = strtolower($key);
    }
    if (strlen($key) > AGUMAIL_KEY_MAX) {
        $key = substr($key, 0, AGUMAIL_KEY_MAX - 33) . '~' . md5($key);
    }
    return $key;
}

function agumail_is_contact_type($type) {
    return $type === 'contact' || $type === 'contact_backfill';
}

/** While the drain runs, the changes it makes itself (sync flags, meta) queue nothing. */
function agumail_enqueue_suppressed($set = null) {
    static $suppressed = false;
    if ($set !== null) {
        $suppressed = (bool) $set;
    }
    return $suppressed;
}

/** Casts a raw outbox row and decodes its payload. */
function agumail_outbox_row_types(array $row) {
    $row['id'] = (int) $row['id'];
    $row['version'] = (int) $row['version'];
    $row['attempts'] = (int) $row['attempts'];
    if (isset($row['payload']) && is_string($row['payload']) && $row['payload'] !== '') {
        $decoded = json_decode($row['payload'], true);
        $row['payload'] = is_array($decoded) ? $decoded : null;
    } else {
        $row['payload'] = null;
    }
    return $row;
}

function agumail_outbox_row($id) {
    return agumail_store()->get((int) $id);
}

/** The failed list, newest first. */
function agumail_outbox_failed($limit = 50, $offset = 0) {
    return agumail_store()->failed(max(1, (int) $limit), max(0, (int) $offset));
}

/** {pending, failed, due, oldest_pending_at (UTC DATETIME or null), by_type: {type: {pending, failed}}} */
function agumail_outbox_stats() {
    return agumail_store()->stats(agumail_mysql_time());
}

/** Puts a failed or waiting row back in line now, its attempts forgotten. */
function agumail_outbox_retry($id) {
    return agumail_store()->retry((int) $id, agumail_mysql_time());
}

function agumail_outbox_retry_failed() {
    return agumail_store()->retry_failed(agumail_mysql_time());
}

function agumail_outbox_discard($id, $version = null) {
    return agumail_store()->discard((int) $id, $version === null ? null : (int) $version);
}

function agumail_outbox_discard_failed() {
    return agumail_store()->discard_failed();
}

/** Empties the outbox. Used when the store connects to another AguMail account. */
function agumail_outbox_clear() {
    return agumail_store()->clear();
}

// ─────────────────────────────────────────────────────────────────────────────
// The carts table. A row: {id, token, session_key, user_id, email, lines (a
// list of {product_id, variation_id, variation, qty, line_total}), total,
// currency, status (open, ordered, emptied), version, queued_version, dirty,
// order_id, ip_hash (a keyed hash of the address the cart came from, for the
// capture limit), created_at, updated_at}.
// ─────────────────────────────────────────────────────────────────────────────

function agumail_cart_get($id) {
    return agumail_store()->cart_get((string) $id);
}

function agumail_cart_find_by_session($session_key, array $statuses = array('open')) {
    return agumail_store()->cart_find_by_session((string) $session_key, $statuses);
}

function agumail_carts_by_email($email, array $statuses = array('open'), $limit = 20) {
    return agumail_store()->carts_by_email(strtolower(trim((string) $email)), $statuses, max(1, (int) $limit));
}

/**
 * Inserts or updates a cart in one write, and moves its version on so the
 * drain sends it. $fields are any of token, session_key, user_id, email, lines
 * (an array), total, currency, status, order_id. A new cart without a token
 * gets a random one; an empty token is never written.
 */
function agumail_cart_save($id, array $fields) {
    if (!agumail_ready()) {
        return false;
    }
    $fields = agumail_cart_columns($fields);
    if (isset($fields['token']) && trim((string) $fields['token']) === '') {
        unset($fields['token']);
    }
    return agumail_store()->cart_save((string) $id, $fields, agumail_mysql_time(), array('token' => agumail_new_cart_token()));
}

/** 32 random hex characters: the secret in a cart's restore link. */
function agumail_new_cart_token() {
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return md5(uniqid((string) wp_rand(), true));
    }
}

/** Whether $token restores $cart. A cart with no token is never restored. */
function agumail_cart_token_valid($cart, $token) {
    if (!is_array($cart) || empty($cart['token']) || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals((string) $cart['token'], $token);
}

/** Updates a cart; with $bump false the change is bookkeeping the drain need not send. An empty token is never written. */
function agumail_cart_update($id, array $fields, $bump = true) {
    if (!agumail_ready()) {
        return false;
    }
    $fields = agumail_cart_columns($fields);
    if (isset($fields['token']) && trim((string) $fields['token']) === '') {
        unset($fields['token']);
    }
    return agumail_store()->cart_update((string) $id, $fields, (bool) $bump, agumail_mysql_time());
}

function agumail_carts_dirty($limit = 500) {
    return agumail_store()->carts_dirty(max(1, (int) $limit));
}

function agumail_cart_mark_queued($id, $version) {
    return agumail_store()->cart_mark_queued((string) $id, (int) $version);
}

/** How many carts were created from an address hash (see the carts feature) since $since_timestamp. */
function agumail_carts_count_by_ip($ip_hash, $since_timestamp) {
    return agumail_store()->carts_count_by_ip((string) $ip_hash, agumail_mysql_time($since_timestamp));
}

/** Deletes carts untouched since $before_timestamp, among the given statuses (any when empty). Returns how many. */
function agumail_carts_purge(array $statuses, $before_timestamp) {
    return agumail_store()->carts_purge($statuses, agumail_mysql_time($before_timestamp));
}

/**
 * Queues every cart that changed since it was last queued, and remembers the
 * version it queued. For the drain's agumail_drain_move_carts step.
 */
function agumail_carts_move_dirty_to_outbox($limit = 500) {
    $moved = 0;
    foreach (agumail_carts_dirty($limit) as $cart) {
        if (agumail_enqueue('cart', $cart['id'])) {
            agumail_cart_mark_queued($cart['id'], $cart['version']);
            $moved++;
        }
    }
    return $moved;
}

/** Only the carts table's own columns: no other key ever reaches the SQL as a column name. */
function agumail_cart_known_columns(array $fields) {
    return array_intersect_key($fields, array_flip(explode(',', AGUMAIL_CART_COLUMNS)));
}

/** Only known columns, with lines as JSON and money as a plain decimal. */
function agumail_cart_columns(array $fields) {
    $allowed = array_flip(explode(',', AGUMAIL_CART_COLUMNS));
    $columns = array();
    foreach ($fields as $column => $value) {
        if (!isset($allowed[$column])) {
            continue;
        }
        if ($column === 'lines') {
            $value = wp_json_encode(array_values(is_array($value) ? $value : array()));
        } elseif ($column === 'total') {
            $value = number_format((float) $value, 4, '.', '');
        } elseif ($column === 'email' && $value !== null) {
            $value = strtolower(trim((string) $value));
        } elseif (($column === 'user_id' || $column === 'order_id') && $value !== null) {
            $value = (int) $value;
        }
        $columns[$column] = $value;
    }
    return $columns;
}

/** Casts a raw carts row and decodes its lines. */
function agumail_cart_row_types(array $row) {
    $row['version'] = (int) $row['version'];
    $row['queued_version'] = (int) $row['queued_version'];
    $row['dirty'] = isset($row['dirty']) ? (int) $row['dirty'] : (int) ($row['version'] > $row['queued_version']);
    $row['user_id'] = isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null;
    $row['order_id'] = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : null;
    $row['total'] = (float) $row['total'];
    $lines = isset($row['lines']) && is_string($row['lines']) ? json_decode($row['lines'], true) : null;
    $row['lines'] = is_array($lines) ? $lines : array();
    return $row;
}
