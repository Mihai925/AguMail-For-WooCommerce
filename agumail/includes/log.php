<?php
// Logging, into WooCommerce's own log under the source "agumail" (WooCommerce >
// Status > Logs). A site without WooCommerce has no such log: there, lines
// other than debug go to PHP's error log, and only while WP_DEBUG_LOG is on
// (wp-content/debug.log by default). Shoppers' email addresses never reach
// either: every message and context value is masked on the way in.

if (!defined('ABSPATH')) {
    exit;
}

define('AGUMAIL_EMAIL_PATTERN', '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i');

/** $level is one of debug, info, notice, warning, error, critical. */
function agumail_log($level, $message, array $context = array()) {
    if (!function_exists('wc_get_logger')) {
        if ($level !== 'debug' && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the debug log is the only log a site without WooCommerce has, and it is written only while WP_DEBUG_LOG is on.
            error_log(sprintf('AguMail %s: %s', $level, agumail_mask_emails((string) $message)));
        }
        return;
    }
    $context = agumail_mask_emails($context);
    $context['source'] = 'agumail';
    wc_get_logger()->log($level, agumail_mask_emails((string) $message), $context);
}

/** ada@example.com becomes a***@example.com. */
function agumail_mask_email($email) {
    $email = trim((string) $email);
    $at = strrpos($email, '@');
    if ($at === false || $at === 0) {
        return '***';
    }
    return substr($email, 0, 1) . '***' . substr($email, $at);
}

/** Masks every address inside a string, or inside every string of an array. */
function agumail_mask_emails($value) {
    if (is_array($value)) {
        return array_map('agumail_mask_emails', $value);
    }
    if (!is_string($value)) {
        return $value;
    }
    return preg_replace_callback(AGUMAIL_EMAIL_PATTERN, function ($match) {
        return agumail_mask_email($match[0]);
    }, $value);
}
