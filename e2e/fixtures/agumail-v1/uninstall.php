<?php
// Clean up everything the plugin stored when it is deleted from WP admin.

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('agumail_api_url');
delete_option('agumail_api_key');
delete_option('agumail_consent_label');
delete_option('agumail_site_key');
delete_option('agumail_site_src');
delete_transient('agumail_pending_carts');
wp_clear_scheduled_hook('agumail_flush_carts');
