<?php
/**
 * The people waiting on something, kept here rather than at Parla.
 *
 * Two lists, one table. Someone asked to be told when a sold-out variant is back ("restock"), or
 * asked for something the shop does not sell at all and agreed to be contacted if it ever arrives
 * ("wanted"). Both start as a sentence in the chat, which happens on Parla's servers, and both end
 * as an email this site sends from its own address.
 *
 * They live here because they are your customers' addresses, not Parla's. You are the controller,
 * Parla is the processor, and a processor holding a list you cannot read, export or erase is the
 * wrong way round: a customer who writes to you asking to be forgotten has to be someone you can
 * forget. So Parla forwards each sign-up the moment it happens and keeps only the fact that a
 * variant has someone waiting on it, which is what it needs to notice the stock coming back.
 *
 * @package Parla
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Bumped when the schema changes, so an update creates or alters the table without a reactivation. */
define('PARLA_WAITING_SCHEMA', 1);

function parla_waiting_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'parla_waiting';
}

/**
 * Creates the table, or brings it up to date. Safe to call on every admin page load: dbDelta
 * compares the schema and does nothing when it already matches, and the version option means we
 * do not even ask it on the usual page load.
 */
function parla_waiting_install(): void
{
    if ((int) get_option('parla_waiting_schema', 0) === PARLA_WAITING_SCHEMA) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = parla_waiting_table();
    $collate = $wpdb->get_charset_collate();
    // 191 characters is what a utf8mb4 index can hold comfortably; an email or a variant id is far shorter.
    dbDelta(
        "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kind VARCHAR(12) NOT NULL,
            ref VARCHAR(191) NOT NULL,
            email VARCHAR(191) NOT NULL,
            title VARCHAR(191) NOT NULL DEFAULT '',
            detail VARCHAR(191) NOT NULL DEFAULT '',
            url VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY one_per_person (kind, ref, email),
            KEY by_list (kind, ref)
        ) $collate;"
    );
    update_option('parla_waiting_schema', PARLA_WAITING_SCHEMA, false);
}

/**
 * Records one person waiting. Asking twice does not mean two emails later: the unique key means the
 * second sign-up updates the first rather than adding a row.
 */
function parla_waiting_add(string $kind, string $ref, string $email, string $title, string $detail, string $url): bool
{
    global $wpdb;
    $email = sanitize_email($email);
    if (!is_email($email) || $ref === '' || !in_array($kind, ['restock', 'wanted'], true)) {
        return false;
    }
    parla_waiting_install();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, and a write, so there is nothing to cache.
    $done = $wpdb->replace(
        parla_waiting_table(),
        [
            'kind' => $kind,
            'ref' => mb_substr($ref, 0, 191),
            'email' => $email,
            'title' => mb_substr(sanitize_text_field($title), 0, 191),
            'detail' => mb_substr(sanitize_text_field($detail), 0, 191),
            'url' => esc_url_raw(mb_substr($url, 0, 255)),
            'created_at' => current_time('mysql', true),
        ],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );
    return $done !== false;
}

/** Every address waiting on one list, oldest first. */
function parla_waiting_for(string $kind, string $ref): array
{
    global $wpdb;
    $table = parla_waiting_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read once when an email is about to go out.
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table WHERE kind = %s AND ref = %s ORDER BY created_at ASC", $kind, $ref), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix, never from input.
        ARRAY_A
    );
    return is_array($rows) ? $rows : [];
}

/** Everyone still waiting, grouped by what they are waiting for, for the Reports tab. */
function parla_waiting_lists(string $kind): array
{
    global $wpdb;
    $table = parla_waiting_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read on the Reports tab only.
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table WHERE kind = %s ORDER BY created_at ASC", $kind), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix, never from input.
        ARRAY_A
    );
    $out = [];
    foreach ((array) $rows as $row) {
        $ref = (string) $row['ref'];
        if (!isset($out[$ref])) {
            $out[$ref] = [
                'ref' => $ref,
                'title' => (string) $row['title'],
                'detail' => (string) $row['detail'],
                'url' => (string) $row['url'],
                'since' => (string) $row['created_at'],
                'people' => [],
            ];
        }
        $out[$ref]['people'][] = ['id' => (int) $row['id'], 'email' => (string) $row['email'], 'at' => (string) $row['created_at']];
    }
    // The longest wait first: it is the one the merchant should act on.
    uasort($out, static fn(array $a, array $b): int => count($b['people']) <=> count($a['people']));
    return array_values($out);
}

function parla_waiting_count(string $kind): int
{
    global $wpdb;
    $table = parla_waiting_table();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, one count for a KPI tile.
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE kind = %s", $kind)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is built from $wpdb->prefix, never from input.
}

/** Clears one list once its message has gone out. The address was kept for that message only. */
function parla_waiting_clear(string $kind, string $ref): void
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, and a delete.
    $wpdb->delete(parla_waiting_table(), ['kind' => $kind, 'ref' => $ref], ['%s', '%s']);
}

/** One person off a list, which is what an erasure request comes down to. */
function parla_waiting_delete(int $id): void
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, and a delete.
    $wpdb->delete(parla_waiting_table(), ['id' => $id], ['%d']);
}

// ---------------------------------------------------------------- what Parla calls

/**
 * Parla forwards a sign-up the moment a shopper agrees to it, so the address is written here and
 * never kept there. Authenticated with the same token as the two sending endpoints.
 */
add_action('rest_api_init', function (): void {
    register_rest_route('parla/v1', '/waiting', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $request) {
            $s = parla_settings();
            if (!parla_is_connected() || $s['consumer_secret'] === '') {
                return false;
            }
            return hash_equals(parla_restock_token($s), (string) $request->get_header('x-parla-token'));
        },
        'callback' => function (WP_REST_Request $request) {
            $kind = sanitize_key((string) $request->get_param('kind'));
            $ref = sanitize_text_field((string) $request->get_param('ref'));
            $email = sanitize_email((string) $request->get_param('email'));
            $ok = parla_waiting_add(
                $kind,
                $ref,
                $email,
                (string) $request->get_param('title'),
                (string) $request->get_param('detail'),
                (string) $request->get_param('url')
            );
            if (!$ok) {
                return new WP_REST_Response(['error' => 'invalid_request'], 400);
            }
            return new WP_REST_Response(['ok' => true, 'waiting' => count(parla_waiting_for($kind, $ref))], 200);
        },
    ]);
});

// ---------------------------------------------------------------- the Reports tab

/** One list of people waiting, with the addresses, because they are yours to see and to erase. */
function parla_ui_waiting(string $kind, string $title, string $blurb, string $empty): void
{
    $lists = parla_waiting_lists($kind);
    ?>
    <section class="aa-card">
        <div class="aa-card-head">
            <h3><?php echo esc_html($title); ?></h3>
            <p class="aa-muted"><?php echo esc_html($blurb); ?></p>
        </div>
        <?php if (!$lists) : ?>
            <p class="aa-empty"><?php echo esc_html($empty); ?></p>
        <?php else : ?>
            <table class="aa-table aa-waiting">
                <thead>
                    <tr>
                        <th><?php esc_html_e('What they are waiting for', 'askparla-ai-shopping-assistant'); ?></th>
                        <th><?php esc_html_e('Who', 'askparla-ai-shopping-assistant'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lists as $list) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($list['title'] !== '' ? $list['title'] : $list['ref']); ?></strong>
                            <?php if ($list['detail'] !== '') : ?>
                                <span class="aa-muted"><?php echo esc_html($list['detail']); ?></span>
                            <?php endif; ?>
                            <small class="aa-muted">
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: %s: how long ago, e.g. "3 days" */
                                        __('since %s', 'askparla-ai-shopping-assistant'),
                                        human_time_diff(strtotime($list['since'] . ' UTC'), time())
                                    )
                                );
                                ?>
                            </small>
                        </td>
                        <td>
                            <ul class="aa-waiting-people">
                                <?php foreach ($list['people'] as $person) : ?>
                                    <li>
                                        <span><?php echo esc_html($person['email']); ?></span>
                                        <a
                                            class="aa-waiting-drop"
                                            href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=parla&tab=reports&parla_forget=' . $person['id']), 'parla_forget_' . $person['id'])); ?>"
                                        ><?php esc_html_e('Forget', 'askparla-ai-shopping-assistant'); ?></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="aa-muted aa-waiting-note">
                <?php esc_html_e('These addresses are on your site, not on Parla. Each one is used for one message and deleted when it goes out.', 'askparla-ai-shopping-assistant'); ?>
            </p>
        <?php endif; ?>
    </section>
    <?php
}

/** "Forget" on one line, which is what an erasure request comes down to. */
function parla_waiting_handle_forget(): void
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nothing is read here; check_admin_referer below verifies the nonce before anything is deleted.
    if (!isset($_GET['parla_forget'])) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the very next line verifies the nonce for this exact id.
    $id = (int) sanitize_text_field(wp_unslash($_GET['parla_forget']));
    if ($id <= 0 || !current_user_can('manage_woocommerce') || !check_admin_referer('parla_forget_' . $id)) {
        return;
    }
    parla_waiting_delete($id);
    wp_safe_redirect(admin_url('admin.php?page=parla&tab=reports&parla_forgot=1'));
    exit;
}
