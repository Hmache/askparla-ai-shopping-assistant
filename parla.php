<?php
/**
 * Plugin Name:       AskParla AI Shopping Assistant for WooCommerce
 * Description:       AI shopping and order-support chat for your store: product search, order tracking, address changes and returns.
 * Version:           0.24.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 11.1
 * Plugin URI:        https://askparla.com
 * Author:            AskParla
 * Author URI:        https://askparla.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       askparla-ai-shopping-assistant
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('PARLA_VERSION', '0.24.0');
define('PARLA_OPTION', 'parla_settings');

/**
 * The one Parla server every shop talks to. Merchants never type an address: they paste their
 * shop key and press Connect. Change it here once, when the production domain is live.
 *
 * For a test site, point it elsewhere from wp-config.php, e.g. a local server seen from Docker:
 *   define('PARLA_API_URL', 'http://host.docker.internal:8787');
 */
define('PARLA_DEFAULT_API', 'https://api.askparla.com');

/**
 * The wording the status line falls back to. The field is offered pre-filled with it, and saving it
 * untouched stores nothing: an empty setting is what lets each shopper read the line in their own
 * language. Typing anything else means that exact text, in every language, which is the merchant's
 * to decide.
 */
function parla_default_status(): string
{
    return __('replies right away', 'askparla-ai-shopping-assistant');
}

/**
 * What the assistant is called when the shop has not renamed it. Parla, because that is the product
 * and a shop that never touches this setting still gets a name rather than a blank.
 */
function parla_default_agent_name(): string
{
    return 'Parla';
}

/**
 * The tappable questions an empty chat offers, as Parla ships them.
 *
 * Shown pre-filled so a merchant edits real openers instead of inventing three from nothing. Saved
 * untouched they store nothing, which keeps them translated for every shopper; changed at all, the
 * shop's own wording is used exactly as typed.
 */
function parla_default_openers(): array
{
    return [
        __("What's popular right now?", 'askparla-ai-shopping-assistant'),
        __('Where is my order?', 'askparla-ai-shopping-assistant'),
        __("I'd like to return something", 'askparla-ai-shopping-assistant'),
    ];
}

/** Where this site reaches Parla: the wp-config override, else the address it connected with before 0.16, else the default. */
function parla_api_base(array $s): string
{
    if (defined('PARLA_API_URL') && (string) PARLA_API_URL !== '') {
        return rtrim((string) PARLA_API_URL, '/');
    }
    if (!empty($s['api_url'])) {
        return rtrim((string) $s['api_url'], '/');
    }
    return PARLA_DEFAULT_API;
}

/**
 * Settings shape:
 *   api_url (only sites connected before 0.16), connect_secret, store_id, widget_url, enabled,
 *   brand_color, brand_contrast, store_name, return_window_days,
 *   completed_means, consumer_key, consumer_secret, connected_at, last_error
 */
function parla_settings(): array
{
    $defaults = [
        'api_url' => '',
        'connect_secret' => '',
        'store_id' => '',
        'widget_url' => '',
        'public_api_url' => '',
        'enabled' => 1,
        'brand_color' => '#111827',
        'brand_contrast' => '#ffffff',
        'store_name' => get_bloginfo('name'),
        'return_window_days' => 30,
        'completed_means' => 'delivered',
        'policy_excerpts' => '',
        'size_guide' => '',
        'support_email' => '',
        'support_hours' => '',   // e.g. "Mon-Fri 09:00-18:00, Sat 10:00-14:00"
        'reply_within' => '',
        'suggest_products' => 'both',
        'theme' => 'auto',
        'locale' => 'auto',
        // What the assistant calls itself. Empty means it is simply the shop's assistant.
        'agent_name' => '',
        // The half of the header line after "AI assistant". Empty uses Parla's own wording.
        'agent_status' => '',
        'opener_chips' => [],
        'show_track_order' => 1,
        // Whether the shop wants the premium voice its plan includes. Their choice, not the operator's.
        'premium_voice_on' => 1,
        'agent_tone' => 'neutral',
        'agent_notes' => '',
        'voice_calls' => 0,
        // Premium voice is part of the plan, switched on by Parla; the plugin only shows it.
        'premium_voice' => 0,
        'plan' => '',
        'voice_name' => '',
        'voice_rate' => 1.05,
        // Which premium voice speaks, chosen from the list Parla publishes. Empty is Parla's own pick.
        'premium_voice_id' => '',
        'greeting_text' => '',
        'greeting_seconds' => 8,
        'greeting_pages' => 'all',
        'greet_customers' => 1,  // say "Hi Sarah" to customers who are signed in
        'consumer_key' => '',
        'consumer_secret' => '',
        'connected_at' => '',
        'last_error' => '',
    ];
    // Before 0.15.0 the plugin was called AgentAssist: carry its settings over once.
    if (get_option(PARLA_OPTION) === false && is_array($legacy = get_option('agentassist_settings'))) {
        update_option(PARLA_OPTION, $legacy, false);
    }
    return array_merge($defaults, (array) get_option(PARLA_OPTION, []));
}

function parla_save(array $settings): void
{
    update_option(PARLA_OPTION, $settings, false);
}

function parla_is_connected(): bool
{
    $s = parla_settings();
    return $s['store_id'] !== '';
}

// ---------------------------------------------------------------- front end

/**
 * Loads the chat widget on the storefront. The widget talks to the Parla
 * API directly; no shopper data passes through WordPress.
 */
function parla_enqueue_widget(): void
{
    $s = parla_settings();
    if (!$s['enabled'] || !parla_is_connected() || is_admin()) {
        return;
    }
    // The address shoppers' browsers use, which can differ from the one WordPress calls
    // (e.g. http://host.docker.internal:8787 from a container vs http://localhost:8787 in the browser).
    $public_api = rtrim($s['public_api_url'] ?: parla_origin_of($s['widget_url']) ?: parla_api_base($s), '/');
    $widget = $s['widget_url'] ?: $public_api . '/widget.js';

    parla_enqueue_theme($s);
    wp_enqueue_script('parla-widget', $widget, [], PARLA_VERSION, true);
    wp_script_add_data('parla-widget', 'strategy', 'defer');

    // The widget reads these from its own <script> tag.
    add_filter('script_loader_tag', function (string $tag, string $handle) use ($s, $public_api): string {
        if ($handle !== 'parla-widget') {
            return $tag;
        }
        $attrs = sprintf(
            ' data-store="%s" data-api="%s" data-store-name="%s"',
            esc_attr($s['store_id']),
            esc_attr($public_api),
            esc_attr($s['store_name'])
        );
        return str_replace(' src=', $attrs . parla_page_attributes() . parla_customer_attribute($s) . ' src=', $tag);
    }, 10, 2);
}

/**
 * Tells the widget what the shopper is looking at, so "do you have this in blue?"
 * works without naming the product again.
 */
function parla_page_attributes(): string
{
    if (function_exists('is_product') && is_product()) {
        global $post;
        $product = wc_get_product($post);
        return sprintf(
            ' data-page-type="product" data-product-id="%s" data-page-title="%s"',
            esc_attr((string) ($product ? $product->get_id() : '')),
            esc_attr($product ? $product->get_name() : '')
        );
    }
    if (function_exists('is_product_category') && (is_product_category() || is_shop())) {
        $term = get_queried_object();
        return sprintf(' data-page-type="category" data-page-title="%s"', esc_attr(is_object($term) && isset($term->name) ? $term->name : __('Shop', 'askparla-ai-shopping-assistant')));
    }
    if (function_exists('is_cart') && is_cart()) {
        return ' data-page-type="cart"';
    }
    if (function_exists('is_checkout') && is_checkout()) {
        return ' data-page-type="checkout"';
    }
    if (function_exists('is_account_page') && is_account_page()) {
        return ' data-page-type="order"';
    }
    return '';
}

/**
 * A signed-in customer's first name, so the chat can say "Hi Sarah". Only the first name, only to
 * that customer's own page, and only when the shop has greeting by name switched on. Never the
 * login or the email: when there is no real first name, the chat simply says "Hi".
 */
function parla_customer_attribute(array $s): string
{
    if (empty($s['greet_customers']) || !is_user_logged_in()) {
        return '';
    }
    $user_id = get_current_user_id();
    $name = trim((string) get_user_meta($user_id, 'first_name', true));
    if ($name === '') {
        $name = trim((string) get_user_meta($user_id, 'billing_first_name', true));
    }
    if ($name === '' || strpos($name, '@') !== false) {
        return '';
    }
    return sprintf(' data-customer-name="%s"', esc_attr(mb_substr(wp_strip_all_tags($name), 0, 40)));
}

/** "http://host:8787/widget.js" -> "http://host:8787". Empty string when the URL is unusable. */
function parla_origin_of(string $url): string
{
    $parts = wp_parse_url($url);
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    return $parts['scheme'] . '://' . $parts['host'] . (empty($parts['port']) ? '' : ':' . $parts['port']);
}

/**
 * Brand colors are plain CSS variables on the custom element. There is no stylesheet file to hang
 * them on, so they go on a handle registered without a source, which WordPress prints as an inline
 * style in the head.
 */
function parla_enqueue_theme(array $s): void
{
    wp_register_style('parla-theme', false, [], PARLA_VERSION);
    wp_enqueue_style('parla-theme');
    wp_add_inline_style('parla-theme', sprintf(
        'parla-chat{--parla-brand:%s;--parla-brand-contrast:%s}',
        sanitize_hex_color($s['brand_color']) ?: '#111827',
        sanitize_hex_color($s['brand_contrast']) ?: '#ffffff'
    ));
}

add_action('wp_enqueue_scripts', 'parla_enqueue_widget');

// ---------------------------------------------------------------- sales attribution

/*
 * Orders are read and written only through WooCommerce's own order objects, so the plugin works
 * the same with the classic posts table and with High-Performance Order Storage.
 */
add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Tags an order the moment it is placed, if the shopper talked to the assistant in the week before.
 *
 * The widget leaves two cookies on this site: parla_assist (a random conversation id, set when the
 * shopper writes or speaks to it) and parla_added (ids of products the assistant or its cards put in
 * the cart). Nothing personal, and nothing leaves the shop: the report adds these orders up here.
 */
function parla_attribute_order($order): void
{
    if (!$order instanceof WC_Order || $order->get_meta('_parla_session') !== '') {
        return;
    }
    $session = isset($_COOKIE['parla_assist']) ? sanitize_text_field(wp_unslash((string) $_COOKIE['parla_assist'])) : '';
    if (!preg_match('/^[0-9a-f-]{8,64}$/i', $session)) {
        return;
    }
    $added = [];
    if (isset($_COOKIE['parla_added'])) {
        foreach (explode(',', sanitize_text_field(wp_unslash((string) $_COOKIE['parla_added']))) as $id) {
            if (ctype_digit($id)) {
                $added[(int) $id] = true;
            }
        }
    }
    $order->update_meta_data('_parla_session', $session);
    foreach ($order->get_items() as $item) {
        if ($item instanceof WC_Order_Item_Product
            && (isset($added[$item->get_product_id()]) || isset($added[$item->get_variation_id()]))) {
            $item->update_meta_data('_parla_added', '1');
            $item->save();
        }
    }
    $order->save();
    // Those items are counted now; the next order starts a fresh list. The chat itself still counts
    // for the rest of its week.
    if ($added && !headers_sent()) {
        setcookie('parla_added', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => is_ssl(), 'samesite' => 'Lax']);
    }
    delete_transient('parla_sales_' . gmdate('Ymd'));
}
// Classic checkout, and the block checkout (which goes through the Store API).
add_action('woocommerce_checkout_order_processed', function ($order_id, $data = [], $order = null): void {
    parla_attribute_order($order instanceof WC_Order ? $order : wc_get_order($order_id));
}, 10, 3);
add_action('woocommerce_store_api_checkout_order_processed', 'parla_attribute_order');

/**
 * The last 30 days of paid orders, split into the ones the assistant helped with and the rest.
 * Refunds are taken off, cancelled and failed orders never count. Cached for an hour.
 */
function parla_sales_report(): array
{
    $cache_key = 'parla_sales_' . gmdate('Ymd');
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $cached;
    }
    $report = [
        'days' => 30,
        'orders' => 0, 'revenue' => 0.0,
        'assisted_orders' => 0, 'assisted_revenue' => 0.0,
        'added_items' => 0, 'added_revenue' => 0.0,
        'capped' => false,
    ];
    $limit = 3000;
    $orders = wc_get_orders([
        'status' => ['wc-processing', 'wc-completed'],
        'date_created' => '>=' . (time() - 30 * DAY_IN_SECONDS),
        'limit' => $limit,
        'type' => 'shop_order',
    ]);
    $report['capped'] = count($orders) >= $limit;
    foreach ($orders as $order) {
        $net = (float) $order->get_total() - (float) $order->get_total_refunded();
        $report['orders']++;
        $report['revenue'] += $net;
        if ($order->get_meta('_parla_session') === '') {
            continue;
        }
        $report['assisted_orders']++;
        $report['assisted_revenue'] += $net;
        foreach ($order->get_items() as $item) {
            if ($item instanceof WC_Order_Item_Product && $item->get_meta('_parla_added') === '1') {
                $report['added_items'] += (int) $item->get_quantity();
                $report['added_revenue'] += (float) $item->get_total() + (float) $item->get_total_tax();
            }
        }
    }
    set_transient($cache_key, $report, HOUR_IN_SECONDS);
    return $report;
}

// ---------------------------------------------------------------- connecting

/**
 * Creates a read/write WooCommerce REST key owned by the current user.
 * Reuses the existing one when the store is already connected.
 */
function parla_create_api_key(): array
{
    $user_id = get_current_user_id();
    if (!$user_id || !user_can($user_id, 'manage_woocommerce')) {
        // WooCommerce checks the key owner's permissions on every request.
        throw new RuntimeException(esc_html__('Connect from the WooCommerce → Parla screen while signed in as a shop manager.', 'askparla-ai-shopping-assistant'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_api_keys';
    $consumer_key = 'ck_' . wc_rand_hash();
    $consumer_secret = 'cs_' . wc_rand_hash();

    // Create first, clean up later. Deleting the old row here would take a working integration
    // down the moment anything after this point fails - which is exactly how a store ends up
    // holding a key WooCommerce has never heard of ("Consumer key is invalid").
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce exposes no API for its own API-key table, and a key must not be cached.
    $ok = $wpdb->insert($table, [
        'user_id' => $user_id,
        'description' => 'Parla',
        'permissions' => 'read_write',
        'consumer_key' => wc_api_hash($consumer_key),
        'consumer_secret' => $consumer_secret,
        'truncated_key' => substr($consumer_key, -7),
    ]);
    if (!$ok) {
        throw new RuntimeException(esc_html__('Could not create a WooCommerce API key.', 'askparla-ai-shopping-assistant'));
    }
    return [$consumer_key, $consumer_secret];
}

/**
 * Drops the Parla keys this store no longer uses. Pass the one that works to keep it; pass an empty
 * string to revoke every one of them, which is what disconnecting has to do: a key left behind is a
 * read/write door into the shop's products, orders and customers, held by a service the merchant
 * has just told us to stop using.
 */
function parla_prune_api_keys(string $keep_consumer_key): void
{
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_api_keys';
    if ($keep_consumer_key === '') {
        // %i is the placeholder for an identifier (WordPress 6.2+), so the table name is passed to
        // prepare() rather than pasted into the SQL. Nothing here is interpolated.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce has no API for its own key table, and a delete has nothing to cache.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE description IN (%s, %s)', $table, 'Parla', 'AgentAssist'));
        return;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- same table, same reason.
    $wpdb->query($wpdb->prepare(
        'DELETE FROM %i WHERE description IN (%s, %s) AND consumer_key <> %s',
        $table,
        'Parla',
        'AgentAssist', // keys made before the rename
        wc_api_hash($keep_consumer_key)
    ));
}

/**
 * "Mon-Fri 09:00-18:00, Sat 10:00-14:00" -> { "1": {from,to}, ... } with 0 = Sunday.
 * Anything it cannot read is ignored rather than guessed.
 */
function parla_parse_hours(string $text): array
{
    $names = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    $days = [];
    foreach (preg_split('/[,;\n]+/', strtolower($text)) as $chunk) {
        if (!preg_match('/([a-z]{3})\s*(?:-\s*([a-z]{3}))?\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', trim($chunk), $m)) {
            continue;
        }
        $from = str_pad($m[3], 5, '0', STR_PAD_LEFT);
        $to = str_pad($m[4], 5, '0', STR_PAD_LEFT);
        $start = $names[$m[1]] ?? null;
        $end = $m[2] !== '' ? ($names[$m[2]] ?? $start) : $start;
        if ($start === null) {
            continue;
        }
        for ($d = $start; ; $d = ($d + 1) % 7) {
            $days[(string) $d] = ['from' => $from, 'to' => $to];
            if ($d === $end) {
                break;
            }
        }
    }
    return $days;
}

/** Registers this store with the Parla API and stores the returned ids. */
/**
 * WordPress in Docker cannot reach the host's localhost: inside the container that name is the
 * container itself, so a server running on the developer's machine answers "cURL error 7". Docker
 * Desktop publishes the host as host.docker.internal, so when a local site gives us a localhost
 * address that refuses the connection, try that name once before reporting a failure.
 *
 * Only for local environments: on a real shop, localhost really does mean this machine.
 */
function parla_container_fallback(string $url): string
{
    if (wp_get_environment_type() !== 'local') {
        return '';
    }
    $host = wp_parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return '';
    }
    return str_replace('//' . $host, '//host.docker.internal', $url);
}

function parla_connect(array $s, bool $new_keys = true): array
{
    $reused = !$new_keys && $s['consumer_key'] !== '' && $s['consumer_secret'] !== '';
    if ($reused) {
        [$ck, $cs] = [$s['consumer_key'], $s['consumer_secret']]; // settings sync: keep the existing key
    } else {
        [$ck, $cs] = parla_create_api_key();
        // Save it before the network call. A key created and then forgotten because the request
        // failed is a key WooCommerce keeps and this store can never use again.
        $s['consumer_key'] = $ck;
        $s['consumer_secret'] = $cs;
        parla_save($s);
    }

    $args = [
        'timeout' => 20,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Connect-Secret' => $s['connect_secret'],
        ],
        'body' => wp_json_encode([
            'platform' => 'woocommerce',
            'name' => $s['store_name'],
            'site_url' => home_url(),
            'return_window_days' => (int) $s['return_window_days'],
            'policy_excerpts' => $s['policy_excerpts'],
            'size_guide' => $s['size_guide'],
            'suggest_products' => $s['suggest_products'],
            'theme' => $s['theme'],
            'locale' => $s['locale'],
            'agent_name' => $s['agent_name'],
            'agent_status' => $s['agent_status'],
            'opener_chips' => array_values((array) $s['opener_chips']),
            'show_track_order' => (bool) $s['show_track_order'],
            'premium_voice_on' => (bool) $s['premium_voice_on'],
            'agent_tone' => $s['agent_tone'],
            'agent_notes' => $s['agent_notes'],
            'voice_calls' => (bool) $s['voice_calls'],
            'voice' => [
                'name' => $s['voice_name'],
                'rate' => (float) $s['voice_rate'],
            ],
            'premium_voice_id' => $s['premium_voice_id'],
            'greeting' => [
                'text' => $s['greeting_text'],
                'after_seconds' => (int) $s['greeting_seconds'],
                'pages' => $s['greeting_pages'],
            ],
            'support_hours' => [
                'timezone' => wp_timezone_string(),
                // Cast to an object: json_encode turns an empty PHP array into [] and a 0-keyed
                // one into a list, and the API expects a map of weekday -> hours either way.
                'days' => (object) parla_parse_hours($s['support_hours']),
                'reply_within' => $s['reply_within'],
            ],
            'woo' => [
                'consumer_key' => $ck,
                'consumer_secret' => $cs,
                'completed_means' => $s['completed_means'],
            ],
        ]),
    ];

    $api = parla_api_base($s);
    $response = wp_remote_post($api . '/v1/connect', $args);

    if (is_wp_error($response)) {
        // Probably this site talking to its own container. Try the host, and keep the address that worked.
        $fallback = parla_container_fallback($api);
        $retry = $fallback ? wp_remote_post($fallback . '/v1/connect', $args) : null;
        if ($retry && !is_wp_error($retry)) {
            $s['api_url'] = $fallback;
            $response = $retry;
        } else {
            throw new RuntimeException(esc_html(sprintf(
                /* translators: %1$s: API address, %2$s: the underlying connection error */
                __('Could not reach the Parla server at %1$s (%2$s). Running WordPress in Docker? This site reaches your machine as host.docker.internal, not localhost.', 'askparla-ai-shopping-assistant'),
                $api,
                $response->get_error_message()
            )));
        }
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    $body = is_array($body) ? $body : [];
    $stale_key = $reused
        && ($body['error'] ?? '') === 'store_unreachable'
        && str_contains((string) ($body['detail'] ?? ''), 'Consumer key is invalid');
    if ($stale_key) {
        // The stored key is not in WooCommerce any more (an interrupted connect can leave it
        // that way). Make a new one and try once, rather than making the merchant guess.
        return parla_connect($s, true);
    }

    if ($code !== 200 || !is_array($body) || empty($body['store_id'])) {
        $detail = is_array($body) && isset($body['error']) ? (string) $body['error'] : (string) $code;
        // invalid_request comes with the fields that failed. Saying which ones is the difference
        // between a merchant fixing their own settings and filing a ticket.
        // The API explains itself in `issues` (settings it rejected) or `detail` (what went wrong
        // underneath). Showing neither is how "refused the connection" became a guessing game.
        $extra = '';
        if (is_array($body) && !empty($body['issues']) && is_array($body['issues'])) {
            /* translators: %s: the list of settings the server refused, separated by semicolons */
            $extra = ' ' . sprintf(__('Check these settings: %s.', 'askparla-ai-shopping-assistant'), implode('; ', array_map('sanitize_text_field', $body['issues'])));
        } elseif (is_array($body) && !empty($body['detail']) && is_string($body['detail'])) {
            /* translators: %s: the error message the Parla server returned */
            $extra = ' ' . sprintf(__('The server said: %s.', 'askparla-ai-shopping-assistant'), sanitize_text_field($body['detail']));
        }
        $issues = $extra;
        throw new RuntimeException(esc_html(sprintf(
            /* translators: 1: error returned by the Parla API, 2: the settings it rejected */
            __('The Parla API refused the connection (%1$s).%2$s', 'askparla-ai-shopping-assistant'),
            match ($detail) {
                'bad_secret' => __('this shop key is not valid, or was revoked', 'askparla-ai-shopping-assistant'),
                'key_in_use' => __('this key is already used by another shop', 'askparla-ai-shopping-assistant'),
                'account_paused' => __('your Parla account is paused', 'askparla-ai-shopping-assistant'),
                'store_unreachable' => sprintf(
                    /* translators: %s: this shop's address as the Parla server sees it */
                    __('the server could not read your shop at %s with the new API key', 'askparla-ai-shopping-assistant'),
                    home_url()
                ),
                'invalid_request' => __('it did not accept one of the settings', 'askparla-ai-shopping-assistant'),
                default => $detail,
            },
            $issues
        )));
    }

    $s['store_id'] = sanitize_text_field((string) $body['store_id']);
    $s['widget_url'] = esc_url_raw((string) ($body['widget_url'] ?? ''));
    $s['public_api_url'] = esc_url_raw((string) ($body['api_url'] ?? ''));
    // Only sent while premium voice is on. The server keeps the same key across saves, so what the
    // merchant pasted into Vapi keeps working.
    // What the plan includes, as the server says: shown in the Voice tab, never chosen here.
    $s['premium_voice'] = empty($body['premium_voice']) ? 0 : 1;
    $s['plan'] = sanitize_key((string) ($body['plan'] ?? ''));
    $s['consumer_key'] = $ck;
    $s['consumer_secret'] = $cs;
    $s['connected_at'] = current_time('mysql');
    $s['last_error'] = '';
    parla_prune_api_keys($ck); // this one works; the rest are leftovers
    return $s;
}

// ---------------------------------------------------------------- back in stock

/**
 * The Parla server watches sold-out variants shoppers asked about and calls this
 * when one is back, so the email leaves the shop's own address with the shop's mail setup.
 * Authenticated with a token both sides derive from the store's API credentials.
 */
function parla_restock_token(array $s): string
{
    return hash('sha256', 'parla:' . $s['store_id'] . ':' . $s['consumer_secret']);
}

add_action('rest_api_init', function (): void {
    register_rest_route('parla/v1', '/back-in-stock', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $request) {
            $s = parla_settings();
            if (!parla_is_connected() || $s['consumer_secret'] === '') {
                return false;
            }
            return hash_equals(parla_restock_token($s), (string) $request->get_header('x-parla-token'));
        },
        'callback' => function (WP_REST_Request $request) {
            // Parla used to send the addresses with the message; since 0.24.0 it sends only the
            // reference, because the addresses have been here all along. Both are accepted: a shop
            // running an older Parla, or an older plugin, keeps working either way.
            $emails = array_filter(array_map('sanitize_email', (array) $request->get_param('emails')));
            $ref = sanitize_text_field((string) $request->get_param('ref'));
            $ours = !$emails && $ref !== '';
            if ($ours) {
                $emails = array_column(parla_waiting_for('restock', $ref), 'email');
            }
            $title = sanitize_text_field((string) $request->get_param('product_title'));
            $variant = sanitize_text_field((string) $request->get_param('variant_label'));
            $url = esc_url_raw((string) $request->get_param('url'));
            if (!$emails || $title === '') {
                return new WP_REST_Response(['error' => 'invalid_request'], 400);
            }

            $shop = get_bloginfo('name');
            $item = $variant !== '' ? "$title ($variant)" : $title;
            /* translators: %s: product name */
            $subject = sprintf(__('%s is back in stock', 'askparla-ai-shopping-assistant'), $item);
            $body = sprintf(
                /* translators: 1: product name, 2: product link, 3: shop name */
                __("Good news — %1\$s is available again.\n\n%2\$s\n\nYou asked us to let you know in the chat on %3\$s. We only send this once.", 'askparla-ai-shopping-assistant'),
                $item,
                $url,
                $shop
            );

            $sent = 0;
            foreach ($emails as $email) {
                if (wp_mail($email, $subject, $body)) {
                    $sent++;
                }
            }
            // Kept for this one message, gone the moment it left.
            if ($ours) {
                parla_waiting_clear('restock', $ref);
            }
            return new WP_REST_Response(['sent' => $sent, 'requested' => count($emails)], 200);
        },
    ]);
});

/**
 * A shopper asked for something this shop did not sell, and now it does.
 *
 * Separate from back-in-stock because it is a different message: they were never told it existed,
 * only that we would get in touch if it ever turned up. The address was kept for this one mail and
 * is deleted the moment it goes out, so the wording says so plainly.
 */
add_action('rest_api_init', function (): void {
    register_rest_route('parla/v1', '/now-available', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $request) {
            $s = parla_settings();
            if (!parla_is_connected() || $s['consumer_secret'] === '') {
                return false;
            }
            return hash_equals(parla_restock_token($s), (string) $request->get_header('x-parla-token'));
        },
        'callback' => function (WP_REST_Request $request) {
            $emails = array_filter(array_map('sanitize_email', (array) $request->get_param('emails')));
            $ref = sanitize_text_field((string) $request->get_param('ref'));
            $ours = !$emails && $ref !== '';
            if ($ours) {
                $emails = array_column(parla_waiting_for('wanted', $ref), 'email');
            }
            $title = sanitize_text_field((string) $request->get_param('product_title'));
            $asked = sanitize_text_field((string) $request->get_param('asked_for'));
            $url = esc_url_raw((string) $request->get_param('url'));
            if (!$emails || $title === '' || $asked === '') {
                return new WP_REST_Response(['error' => 'invalid_request'], 400);
            }

            $shop = get_bloginfo('name');
            /* translators: %s: what the shopper asked for */
            $subject = sprintf(__('We now have %s', 'askparla-ai-shopping-assistant'), $asked);
            $body = sprintf(
                /* translators: 1: what they asked for, 2: product name, 3: product link, 4: shop name */
                __("You asked us about %1\$s in the chat, and we did not have it at the time.\n\nWe do now: %2\$s\n\n%3\$s\n\nThis is the one message we said we would send, and we have deleted your address from that list. %4\$s", 'askparla-ai-shopping-assistant'),
                $asked,
                $title,
                $url,
                $shop
            );

            $sent = 0;
            foreach ($emails as $email) {
                if (wp_mail($email, $subject, $body)) {
                    $sent++;
                }
            }
            if ($ours) {
                parla_waiting_clear('wanted', $ref);
            }
            return new WP_REST_Response(['sent' => $sent, 'requested' => count($emails)], 200);
        },
    ]);
});

/** The conversation the assistant handed over, emailed to the shop's team. */
add_action('rest_api_init', function (): void {
    register_rest_route('parla/v1', '/handoff', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $request) {
            $s = parla_settings();
            if (!parla_is_connected() || $s['consumer_secret'] === '') {
                return false;
            }
            return hash_equals(parla_restock_token($s), (string) $request->get_header('x-parla-token'));
        },
        'callback' => function (WP_REST_Request $request) {
            $s = parla_settings();
            $to = $s['support_email'] !== '' ? $s['support_email'] : get_option('admin_email');
            $reason = sanitize_textarea_field((string) $request->get_param('reason'));
            $from = sanitize_email((string) $request->get_param('customer_email'));
            $order = sanitize_text_field((string) $request->get_param('order_id'));
            $lines = array_map('sanitize_textarea_field', (array) $request->get_param('transcript'));

            $body = __('A shopper asked for a human in the chat.', 'askparla-ai-shopping-assistant') . "\n\n"
                . __('Reason:', 'askparla-ai-shopping-assistant') . ' ' . $reason . "\n"
                . __('Shopper:', 'askparla-ai-shopping-assistant') . ' ' . ($from !== '' ? $from : __('not given', 'askparla-ai-shopping-assistant')) . "\n"
                . ($order !== '' ? __('Order:', 'askparla-ai-shopping-assistant') . ' #' . $order . "\n" : '')
                . "\n" . __('Conversation:', 'askparla-ai-shopping-assistant') . "\n" . implode("\n", $lines);

            $headers = $from !== '' ? ['Reply-To: ' . $from] : [];
            /* translators: %s: the first words of the shopper's question */
            $sent = wp_mail($to, sprintf(__('[Chat] %s', 'askparla-ai-shopping-assistant'), wp_trim_words($reason, 8)), $body, $headers);
            return new WP_REST_Response(['sent' => (bool) $sent], $sent ? 200 : 500);
        },
    ]);
});

// ---------------------------------------------------------------- admin page

/** The settings page's screen id, so the assets load on that page and nowhere else. */
function parla_admin_hook(?string $set = null): string
{
    static $hook = '';
    if ($set !== null) {
        $hook = $set;
    }
    return $hook;
}

function parla_admin_menu(): void
{
    // Kept from add_submenu_page rather than written out by hand: the screen id is derived from the
    // parent slug, so guessing it means the stylesheet silently stops loading if the parent changes.
    parla_admin_hook((string) add_submenu_page(
        'woocommerce',
        __('Parla', 'askparla-ai-shopping-assistant'),
        __('Parla', 'askparla-ai-shopping-assistant'),
        'manage_woocommerce',
        'parla',
        'parla_render_admin'
    ));
}
add_action('admin_menu', 'parla_admin_menu');

function parla_handle_post(array $s): array
{
    /** The settings as they were, so a failed connect can fall back to the key that was working. */
    $before = $s;
    if (!isset($_POST['parla_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['parla_nonce'])), 'parla_save')) {
        wp_die(esc_html__('Security check failed. Please try again.', 'askparla-ai-shopping-assistant'));
    }
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You are not allowed to change these settings.', 'askparla-ai-shopping-assistant'));
    }

    $posted = wp_unslash($_POST);
    $s['store_name'] = sanitize_text_field((string) ($posted['store_name'] ?? ''));
    $s['brand_color'] = sanitize_hex_color((string) ($posted['brand_color'] ?? '')) ?: '#111827';
    $s['brand_contrast'] = sanitize_hex_color((string) ($posted['brand_contrast'] ?? '')) ?: '#ffffff';
    $s['enabled'] = empty($posted['enabled']) ? 0 : 1;
    $s['completed_means'] = ($posted['completed_means'] ?? 'delivered') === 'shipped' ? 'shipped' : 'delivered';
    $s['return_window_days'] = max(1, min(365, (int) ($posted['return_window_days'] ?? 30)));
    $s['policy_excerpts'] = sanitize_textarea_field((string) ($posted['policy_excerpts'] ?? ''));
    $s['size_guide'] = sanitize_textarea_field((string) ($posted['size_guide'] ?? ''));
    $s['support_email'] = sanitize_email((string) ($posted['support_email'] ?? ''));
    $s['support_hours'] = sanitize_text_field((string) ($posted['support_hours'] ?? ''));
    $s['reply_within'] = sanitize_text_field((string) ($posted['reply_within'] ?? ''));
    $s['suggest_products'] = in_array($posted['suggest_products'] ?? 'both', ['both', 'home', 'off'], true) ? $posted['suggest_products'] : 'both';
    $s['theme'] = in_array($posted['theme'] ?? 'auto', ['auto', 'light', 'dark'], true) ? $posted['theme'] : 'auto';
    $s['locale'] = sanitize_text_field((string) ($posted['locale'] ?? 'auto'));
    // Stored as typed, unlike the status line: a name has no translations to preserve, and blanking
    // "Parla" back out would quietly undo the default the merchant was shown and accepted.
    $s['agent_name'] = substr(trim(sanitize_text_field((string) ($posted['agent_name'] ?? ''))), 0, 120);
    $status = trim(sanitize_text_field((string) ($posted['agent_status'] ?? '')));
    $s['agent_status'] = $status === parla_default_status() ? '' : substr($status, 0, 60);

    $openers = array_values(array_filter(array_map(
        static fn ($line) => substr(trim(sanitize_text_field((string) $line)), 0, 80),
        (array) ($posted['opener_chips'] ?? [])
    ), static fn ($line) => $line !== ''));
    // Unchanged from ours means unset, so each shopper keeps reading them in their own language.
    $s['opener_chips'] = $openers === parla_default_openers() ? [] : array_slice($openers, 0, 6);
    $s['show_track_order'] = empty($posted['show_track_order']) ? 0 : 1;
    $s['premium_voice_on'] = empty($posted['premium_voice_on']) ? 0 : 1;
    $s['agent_tone'] = in_array($posted['agent_tone'] ?? 'neutral', ['warm', 'neutral', 'expert', 'playful'], true) ? $posted['agent_tone'] : 'neutral';
    // Free text, but bounded: the server puts it above the rules it is not allowed to break.
    $s['agent_notes'] = substr(sanitize_textarea_field((string) ($posted['agent_notes'] ?? '')), 0, 300);
    $s['voice_calls'] = empty($posted['voice_calls']) ? 0 : 1;
    // Settings from before premium voice moved to Parla's side: nothing reads them any more.
    unset($s['voice_engine'], $s['vapi_public_key'], $s['vapi_assistant_id'], $s['vapi_llm_url'], $s['vapi_llm_secret']);
    $s['voice_name'] = sanitize_text_field((string) ($posted['voice_name'] ?? ''));
    $s['voice_rate'] = max(0.6, min(1.6, (float) ($posted['voice_rate'] ?? 1.05)));
    /*
     * The picker only exists on the page while premium voice is on, so an absent field means it was
     * never shown, not that the merchant cleared it: keep what they chose. Checked again by the
     * server against its own catalogue, so a junk id here costs nothing.
     */
    if (isset($posted['premium_voice_id'])) {
        $s['premium_voice_id'] = substr(sanitize_text_field((string) $posted['premium_voice_id']), 0, 64);
    }
    $s['greeting_text'] = sanitize_text_field((string) ($posted['greeting_text'] ?? ''));
    $s['greeting_seconds'] = max(2, min(120, (int) ($posted['greeting_seconds'] ?? 8)));
    $s['greeting_pages'] = in_array($posted['greeting_pages'] ?? 'off', ['off', 'cart_checkout', 'all'], true) ? $posted['greeting_pages'] : 'off';
    $s['greet_customers'] = empty($posted['greet_customers']) ? 0 : 1;
    $secret = trim((string) ($posted['connect_secret'] ?? ''));
    if ($secret !== '') {
        $s['connect_secret'] = $secret;
    }
    if (!empty($posted['disconnect'])) {
        // Revoke first: once the settings are wiped we no longer know which row was ours.
        parla_prune_api_keys('');
        $s['store_id'] = '';
        $s['consumer_key'] = '';
        $s['consumer_secret'] = '';
        $s['connected_at'] = '';
        $s['premium_voice'] = 0;
        $s['plan'] = '';
        parla_save($s);
        add_settings_error('parla', 'disconnected', __('Store disconnected. The chat no longer appears on your site.', 'askparla-ai-shopping-assistant'), 'updated');
        return $s;
    }

    parla_save($s);

    if (empty($posted['connect']) && parla_is_connected()) {
        // Settings must reach the assistant right away, not only when reconnecting.
        try {
            $s = parla_connect($s, false);
            parla_save($s);
        } catch (Throwable $e) {
            $s['last_error'] = $e->getMessage();
            parla_save($s);
            add_settings_error('parla', 'sync', sprintf(
                /* translators: %s: error message */
                __('Settings saved here, but the assistant could not be updated: %s', 'askparla-ai-shopping-assistant'),
                esc_html($e->getMessage())
            ));
            return $s;
        }
    }

    if (!empty($posted['connect'])) {
        if ($s['connect_secret'] === '') {
            add_settings_error('parla', 'missing', __('Paste your shop key first.', 'askparla-ai-shopping-assistant'));
            return $s;
        }
        try {
            $s = parla_connect($s);
            parla_save($s);
            add_settings_error('parla', 'connected', __('Store connected. The chat is now live on your storefront.', 'askparla-ai-shopping-assistant'), 'updated');
        } catch (Throwable $e) {
            // A failed attempt has already created a key. Drop it, keeping whichever one was working
            // before, so repeated attempts cannot leave a trail of live credentials behind them.
            parla_prune_api_keys((string) ($before['consumer_key'] ?? ''));
            $s['last_error'] = $e->getMessage();
            parla_save($s);
            add_settings_error('parla', 'failed', $e->getMessage());
        }
        return $s;
    }

    add_settings_error('parla', 'saved', __('Settings saved.', 'askparla-ai-shopping-assistant'), 'updated');
    return $s;
}

/**
 * The merchant's numbers, from the Parla API. Cached for five minutes so opening the
 * settings page does not mean a round trip every time, and never fatal: the page still works
 * when the API is down.
 *
 * @return array{ok:bool,data:array,error:string}
 */
function parla_insights(array $s, bool $refresh = false): array
{
    if (!parla_is_connected() || $s['connect_secret'] === '') {
        return ['ok' => false, 'data' => [], 'error' => ''];
    }
    $cache_key = 'parla_insights_' . md5($s['store_id']);
    /*
     * Held for the length of the request as well as in the transient. The settings page asks twice
     * now (the Overview's counts and the Reports tab), and on a cold cache both would miss and make
     * their own blocking call, so opening the page would wait on the network twice.
     */
    static $memo = null;
    if (!$refresh && $memo !== null) {
        return $memo;
    }
    if (!$refresh) {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            $memo = ['ok' => true, 'data' => $cached, 'error' => ''];
            return $memo;
        }
    }

    $res = wp_remote_get(
        parla_api_base($s) . '/v1/stores/' . rawurlencode($s['store_id']) . '/insights',
        ['timeout' => 8, 'headers' => ['x-connect-secret' => $s['connect_secret']]]
    );
    if (is_wp_error($res)) {
        $memo = ['ok' => false, 'data' => [], 'error' => $res->get_error_message()];
        return $memo;
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    if ($code !== 200 || !is_array($body)) {
        $detail = $code === 401
            ? __('the shop key on this page does not match the server', 'askparla-ai-shopping-assistant')
            /* translators: %d: the HTTP status code the server returned */
            : sprintf(__('the server answered %d', 'askparla-ai-shopping-assistant'), $code);
        $memo = ['ok' => false, 'data' => [], 'error' => $detail];
        return $memo;
    }
    set_transient($cache_key, $body, 5 * MINUTE_IN_SECONDS);
    $memo = ['ok' => true, 'data' => $body, 'error' => ''];
    return $memo;
}

// The settings screen itself: statistics first, then settings, all in includes/admin.php.
require_once __DIR__ . '/includes/waiting.php';
require_once __DIR__ . '/includes/admin.php';

/*
 * The waiting lists need a table. Created on activation, and checked once per admin load in case
 * the plugin was updated rather than reactivated, which is how most shops get a new version.
 */
register_activation_hook(__FILE__, 'parla_waiting_install');
add_action('admin_init', 'parla_waiting_install');
add_action('admin_init', 'parla_waiting_handle_forget');

/** Settings link on the Plugins screen. */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function (array $links): array {
    $url = admin_url('admin.php?page=parla');
    array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'askparla-ai-shopping-assistant') . '</a>');
    return $links;
});

/** Warn instead of fataling if WooCommerce is missing. */
add_action('admin_notices', function (): void {
    if (!class_exists('WooCommerce')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Parla needs WooCommerce to be installed and active.', 'askparla-ai-shopping-assistant') . '</p></div>';
    }
});
