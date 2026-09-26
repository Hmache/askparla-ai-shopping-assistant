<?php
/**
 * Removes everything Parla left behind when the plugin is deleted.
 *
 * Deleting a plugin is a merchant saying they are done with it, so nothing of theirs should survive:
 * not the settings, not the credentials that let an outside service read their shop, not the cached
 * figures, and not the marks the chat put on their orders. On a network, that means every site, not
 * only the one the delete happened to run on.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/** Everything that belongs to one site. Called once per site on a network. */
function parla_uninstall_site(): void
{
    global $wpdb;

    delete_option('parla_settings');
    delete_option('agentassist_settings'); // the plugin's former name

    // The read/write keys that let Parla reach this shop's products and orders.
    $keys = $wpdb->prefix . 'woocommerce_api_keys';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall has to delete WooCommerce's key rows; there is no API for it and nothing to cache.
    $wpdb->delete($keys, ['description' => 'Parla']);
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same table, the key made under the plugin's former name.
    $wpdb->delete($keys, ['description' => 'AgentAssist']);

    /*
     * Cached insights and the daily sales figures. Their names carry a hash or a date, so they have
     * to be matched rather than named, and each one has a hidden `_transient_timeout_` twin.
     */
    $like = $wpdb->esc_like('parla_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transients whose names carry a hash cannot be deleted by name.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $like,
        '_transient_timeout_' . $like
    ));

    // What the chat wrote on orders to know which sales it had helped with.
    foreach (['_parla_session', '_parla_added'] as $meta) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a one-off cleanup at uninstall, not a request-time query.
        $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta]);
        // WooCommerce's own order tables, when high-performance order storage is on.
        $hpos = $wpdb->prefix . 'wc_orders_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checking a table exists has no API and nothing to cache.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos)) === $hpos) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-off cleanup on WooCommerce's order tables.
            $wpdb->delete($hpos, ['meta_key' => $meta]);
        }
    }
}

/** The waiting lists are a table of this plugin's own making, so it takes them with it. */
function parla_uninstall_waiting(): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'parla_waiting';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dropping the plugin's own table; the name is built from $wpdb->prefix, never from input, and DROP takes no placeholders.
    $wpdb->query("DROP TABLE IF EXISTS $table");
    delete_option('parla_waiting_schema');
}

function parla_uninstall_network(): void
{
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $blog_id) {
        switch_to_blog((int) $blog_id);
        parla_uninstall_site();
        parla_uninstall_waiting();
        restore_current_blog();
    }
}

if (is_multisite()) {
    parla_uninstall_network();
} else {
    parla_uninstall_site();
    parla_uninstall_waiting();
}
