<?php
/**
 * The Parla screen in wp-admin.
 *
 * Numbers first, settings after: a shop owner opens this page to see whether the assistant is
 * earning its keep, and only now and then to change how it behaves. It lives inside WordPress
 * rather than painting over it, keeps the shop's own brand colour as its accent, and styles every
 * field itself so the page reads as one designed thing instead of a stack of default inputs.
 */

defined('ABSPATH') || exit;

// ---------------------------------------------------------------- data the screen shows

/**
 * What the storefront is actually being told, straight from the API. The settings on this page
 * are what the merchant typed; this is what the widget receives. They differ whenever something
 * was typed but not saved, or the server runs older code.
 *
 * @return array{ok:bool,data:array,error:string}
 */
function parla_live_config(array $s): array
{
    if (!parla_is_connected()) {
        return ['ok' => false, 'data' => [], 'error' => ''];
    }
    // With the shop's key, so a paused account is told apart from a server that is down.
    $res = wp_remote_get(parla_api_base($s) . '/v1/stores/' . rawurlencode($s['store_id']) . '/config', ['timeout' => 6, 'headers' => ['x-connect-secret' => (string) ($s['connect_secret'] ?? '')]]);
    if (is_wp_error($res)) {
        return ['ok' => false, 'data' => [], 'error' => $res->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    if ($code === 403 && is_array($body) && ($body['error'] ?? '') === 'account_paused') {
        return ['ok' => false, 'data' => [], 'error' => 'account_paused'];
    }
    if ($code !== 200 || !is_array($body)) {
        /* translators: %d: the HTTP status code the server returned */
        return ['ok' => false, 'data' => [], 'error' => sprintf(__('the server answered %d', 'askparla-ai-shopping-assistant'), $code)];
    }
    return ['ok' => true, 'data' => $body, 'error' => ''];
}

/** An accent that stays visible on white: a near-white brand colour falls back to ink. */
function parla_ui_accent(string $hex): string
{
    $hex = sanitize_hex_color($hex) ?: '#111827';
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) {
        $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    }
    $lin = static function (int $c): float {
        $c /= 255;
        return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    $l = 0.2126 * $lin(hexdec(substr($h, 0, 2))) + 0.7152 * $lin(hexdec(substr($h, 2, 2))) + 0.0722 * $lin(hexdec(substr($h, 4, 2)));
    return $l > 0.6 ? '#111827' : $hex;
}

/** Money as the shop writes it, as plain text. */
function parla_ui_money(float $v): string
{
    return html_entity_decode(wp_strip_all_tags(wc_price($v)), ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------- building blocks

/** A settings group: a heading, one line on what it is for, then its rows. */
function parla_ui_section(string $title, string $description, callable $rows): void
{
    ?>
    <section class="aa-section">
        <header class="aa-section-head">
            <h2><?php echo esc_html($title); ?></h2>
            <?php if ($description !== '') : ?><p><?php echo esc_html($description); ?></p><?php endif; ?>
        </header>
        <div class="aa-rows"><?php $rows(); ?></div>
    </section>
    <?php
}

/**
 * The one switch the rest of the page depends on.
 *
 * It used to be an ordinary row, which made "show the chat at all" look exactly as important as
 * "speaking speed". A merchant scanning the page had no way to tell which control was load-bearing.
 */
function parla_ui_master(string $label, string $help, callable $control): void
{
    ?>
    <div class="aa-master">
        <div>
            <strong><?php echo esc_html($label); ?></strong>
            <?php if ($help !== '') : ?><p class="aa-help"><?php echo esc_html($help); ?></p><?php endif; ?>
        </div>
        <div class="aa-master-control"><?php $control(); ?></div>
    </div>
    <?php
}

/** One setting: its name and a short explanation on the left, the control on the right. */
function parla_ui_row(string $label, string $help, callable $control, string $for = ''): void
{
    ?>
    <div class="aa-row">
        <div class="aa-row-label">
            <?php if ($for !== '') : ?>
                <label for="<?php echo esc_attr($for); ?>"><?php echo esc_html($label); ?></label>
            <?php else : ?>
                <span class="aa-label"><?php echo esc_html($label); ?></span>
            <?php endif; ?>
            <?php if ($help !== '') : ?><p class="aa-help"><?php echo esc_html($help); ?></p><?php endif; ?>
        </div>
        <div class="aa-row-control"><?php $control(); ?></div>
    </div>
    <?php
}

/** An on/off switch that is a real checkbox underneath, so it posts and reads like one. */
function parla_ui_switch(string $name, bool $on, string $text, string $id = ''): void
{
    $id = $id ?: 'aa_' . $name;
    printf(
        '<label class="aa-switch" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s>'
        . '<span class="aa-switch-track" aria-hidden="true"><span class="aa-switch-thumb"></span></span>'
        . '<span class="aa-switch-text">%4$s</span></label>',
        esc_attr($id),
        esc_attr($name),
        checked($on, true, false),
        esc_html($text)
    );
}

/** Two to four short choices side by side. */
function parla_ui_segmented(string $name, array $options, string $current, string $label): void
{
    printf('<div class="aa-seg" role="radiogroup" aria-label="%s">', esc_attr($label));
    foreach ($options as $value => $text) {
        printf(
            '<label><input type="radio" name="%s" value="%s" %s><span>%s</span></label>',
            esc_attr($name),
            esc_attr((string) $value),
            checked($current, (string) $value, false),
            esc_html($text)
        );
    }
    echo '</div>';
}

/** Choices that need a sentence each: a card per option. */
function parla_ui_cards(string $name, array $options, string $current): void
{
    echo '<div class="aa-choices">';
    foreach ($options as $value => [$title, $text]) {
        printf(
            '<label class="aa-choice"><input type="radio" name="%s" value="%s" %s>'
            . '<span class="aa-choice-body"><span class="aa-choice-dot" aria-hidden="true"></span>'
            . '<strong>%s</strong><span>%s</span></span></label>',
            esc_attr($name),
            esc_attr((string) $value),
            checked($current, (string) $value, false),
            esc_html($title),
            esc_html($text)
        );
    }
    echo '</div>';
}

/** A plain select, styled. */
function parla_ui_select(string $name, string $id, array $options, string $current): void
{
    printf('<span class="aa-select"><select name="%s" id="%s">', esc_attr($name), esc_attr($id));
    foreach ($options as $value => $text) {
        printf('<option value="%s" %s>%s</option>', esc_attr((string) $value), selected($current, (string) $value, false), esc_html($text));
    }
    echo '</select></span>';
}

/** A read-only value with a Copy button (and Show, for a secret). */
function parla_ui_copy(string $id, string $value, bool $secret = false): void
{
    ?>
    <div class="aa-copy">
        <input type="<?php echo $secret ? 'password' : 'text'; ?>" id="<?php echo esc_attr($id); ?>" class="aa-in aa-mono" readonly value="<?php echo esc_attr($value); ?>">
        <?php if ($secret) : ?>
            <button type="button" class="aa-btn aa-btn-ghost" data-aa-reveal="<?php echo esc_attr($id); ?>"><?php esc_html_e('Show', 'askparla-ai-shopping-assistant'); ?></button>
        <?php endif; ?>
        <button type="button" class="aa-btn aa-btn-ghost" data-aa-copy="<?php echo esc_attr($id); ?>" data-done="<?php esc_attr_e('Copied', 'askparla-ai-shopping-assistant'); ?>"><?php esc_html_e('Copy', 'askparla-ai-shopping-assistant'); ?></button>
    </div>
    <?php
}

/** One figure on the overview. */
function parla_ui_kpi(string $label, string $value, string $hint = '', ?float $progress = null): void
{
    ?>
    <div class="aa-kpi">
        <span class="aa-kpi-label"><?php echo esc_html($label); ?></span>
        <span class="aa-kpi-value"><?php echo esc_html($value); ?></span>
        <?php if ($progress !== null) : ?>
            <span class="aa-meter<?php echo $progress >= 0.9 ? ' is-high' : ''; ?>" role="img" aria-label="<?php echo esc_attr(sprintf('%d%%', (int) round($progress * 100))); ?>">
                <span style="width:<?php echo esc_attr((string) min(100, round($progress * 100))); ?>%"></span>
            </span>
        <?php endif; ?>
        <?php if ($hint !== '') : ?><span class="aa-kpi-hint"><?php echo esc_html($hint); ?></span><?php endif; ?>
    </div>
    <?php
}

/** A short "what shoppers asked" list, or a friendly empty line. */
/**
 * A ranked list of what shoppers asked for.
 *
 * `$total` is the real figure from the server, not the length of `$rows`: the server sends the top
 * twenty, so a shop with five thousand misses would otherwise see eight lines and no hint that the
 * other four thousand nine hundred and something exist. The count goes in the heading, and the rows
 * past the eighth open on a click rather than being silently dropped.
 */
function parla_ui_topics(array $rows, string $empty, array $total = []): void
{
    if (!$rows) {
        printf('<p class="aa-empty">%s</p>', esc_html($empty));
        return;
    }
    $shown = 8;
    $terms = (int) ($total['terms'] ?? count($rows));
    $hits = (int) ($total['hits'] ?? 0);
    if ($terms > 0) {
        printf(
            '<p class="aa-help aa-total">%s</p>',
            esc_html($hits > $terms
                /* translators: 1: distinct things asked, 2: how many times in total */
                ? sprintf(__('%1$s different things, asked %2$s times', 'askparla-ai-shopping-assistant'), number_format_i18n($terms), number_format_i18n($hits))
                /* translators: %s: distinct things asked */
                : sprintf(_n('%s thing asked', '%s different things asked', $terms, 'askparla-ai-shopping-assistant'), number_format_i18n($terms)))
        );
    }
    echo '<ol class="aa-list">';
    foreach ($rows as $i => $row) {
        printf(
            '<li%s><span>%s</span><span class="aa-count">%s</span></li>',
            $i >= $shown ? ' class="aa-more" hidden' : '',
            esc_html((string) ($row['query'] ?? '')),
            esc_html(sprintf('%d×', (int) ($row['count'] ?? 0)))
        );
    }
    echo '</ol>';
    if (count($rows) > $shown) {
        printf(
            '<button type="button" class="aa-link" data-aa-more data-less="%s">%s</button>',
            esc_attr__('Show fewer', 'askparla-ai-shopping-assistant'),
            esc_html(sprintf(
                /* translators: %s: how many more rows are hidden */
                __('Show %s more', 'askparla-ai-shopping-assistant'),
                number_format_i18n(count($rows) - $shown)
            ))
        );
    }
    // The server sends the top twenty; beyond that the merchant needs the list itself, not a longer card.
    if ($terms > count($rows)) {
        printf(
            '<p class="aa-help">%s</p>',
            esc_html(sprintf(
                /* translators: %s: number of entries shown */
                __('Showing the busiest %s. The rest are in your Parla dashboard.', 'askparla-ai-shopping-assistant'),
                number_format_i18n(count($rows))
            ))
        );
    }
}

// ---------------------------------------------------------------- overview

/** The money: sales from shoppers who talked to the assistant, from this shop's own orders. */
function parla_ui_sales(): void
{
    if (!function_exists('wc_get_orders')) {
        return;
    }
    $r = parla_sales_report();
    ?>
    <section class="aa-hero">
        <div class="aa-hero-top">
            <span class="aa-eyebrow"><?php esc_html_e('Sales it helped with', 'askparla-ai-shopping-assistant'); ?></span>
            <span class="aa-tag"><?php esc_html_e('Last 30 days', 'askparla-ai-shopping-assistant'); ?></span>
        </div>
        <?php if ($r['assisted_orders'] === 0) : ?>
            <div class="aa-hero-value is-empty"><?php echo esc_html(parla_ui_money(0)); ?></div>
            <p class="aa-hero-sub"><?php esc_html_e('No orders yet from shoppers who talked to the assistant. An order counts when it is placed within 7 days of a chat or a call.', 'askparla-ai-shopping-assistant'); ?></p>
        <?php else :
            $share = $r['revenue'] > 0 ? (int) round(100 * $r['assisted_revenue'] / $r['revenue']) : 0;
            $others = $r['orders'] - $r['assisted_orders'];
            ?>
            <div class="aa-hero-value"><?php echo esc_html(parla_ui_money($r['assisted_revenue'])); ?></div>
            <p class="aa-hero-sub">
                <?php
                printf(
                    /* translators: 1: number of orders, 2: share of the shop's sales in percent */
                    esc_html(_n('from %1$d order, %2$d%% of your sales', 'from %1$d orders, %2$d%% of your sales', $r['assisted_orders'], 'askparla-ai-shopping-assistant')),
                    (int) $r['assisted_orders'],
                    (int) $share
                );
                ?>
            </p>
            <div class="aa-facts">
                <?php if ($r['added_items'] > 0) : ?>
                    <div class="aa-fact">
                        <strong><?php echo esc_html(parla_ui_money($r['added_revenue'])); ?></strong>
                        <span><?php
                            printf(
                                /* translators: %d: number of items */
                                esc_html(_n('%d item it put in the cart itself', '%d items it put in the cart itself', $r['added_items'], 'askparla-ai-shopping-assistant')),
                                (int) $r['added_items']
                            ); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($r['assisted_orders'] >= 5 && $others >= 5) : ?>
                    <div class="aa-fact">
                        <strong><?php echo esc_html(parla_ui_money($r['assisted_revenue'] / $r['assisted_orders'])); ?></strong>
                        <span><?php
                            printf(
                                /* translators: %s: average order without the assistant */
                                esc_html__('average order, against %s without it', 'askparla-ai-shopping-assistant'),
                                esc_html(parla_ui_money(($r['revenue'] - $r['assisted_revenue']) / $others))
                            ); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <?php parla_ui_weeks_chart((array) ($r['weeks'] ?? [])); ?>
        <?php endif; ?>
        <p class="aa-fine">
            <?php esc_html_e('Paid orders, refunds taken off. Counted here from your own WooCommerce orders; nothing about them is sent anywhere.', 'askparla-ai-shopping-assistant'); ?>
            <?php if ($r['capped']) {
                esc_html_e(' Only your latest 3,000 orders are counted.', 'askparla-ai-shopping-assistant');
            } ?>
        </p>
    </section>
    <?php
}

/**
 * A rounded top for an axis: 112 becomes 120, 1,240 becomes 1,500, so the bars never touch the top
 * and the gridline labels are numbers a person would pick.
 */
function parla_ui_nice_max(float $max): float
{
    if ($max <= 0) {
        return 1.0;
    }
    $step = 10 ** floor(log10($max));
    foreach ([1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $m) {
        if ($m * $step >= $max) {
            return $m * $step;
        }
    }
    return 10 * $step;
}

/**
 * The assisted sales, one bar per calendar week, under the 30-day total. Only the best week and the
 * current one carry their figure; every bar says its own on hover and to a screen reader.
 */
function parla_ui_weeks_chart(array $weeks): void
{
    if (count($weeks) < 2) {
        return;
    }
    $top = parla_ui_nice_max((float) max($weeks));
    $best = array_search(max($weeks), $weeks, true);
    $now = array_key_last($weeks);
    ?>
    <div class="aa-chart">
        <span class="aa-chart-title"><?php esc_html_e('By week', 'askparla-ai-shopping-assistant'); ?></span>
        <ul class="aa-bars is-weeks" data-aa-bars>
            <?php foreach ($weeks as $monday => $value) :
                $label = $monday === $now
                    ? __('This week', 'askparla-ai-shopping-assistant')
                    /* translators: %s: the Monday a week starts on, e.g. "6 Oct" */
                    : sprintf(__('Week of %s', 'askparla-ai-shopping-assistant'), date_i18n('j M', strtotime($monday . ' 12:00')));
                $money = parla_ui_money((float) $value);
                ?>
                <li data-tip="<?php echo esc_attr($label . ' · ' . $money); ?>">
                    <span class="aa-bar-val"<?php echo ($monday === $best || $monday === $now) ? '' : ' aria-hidden="true" style="visibility:hidden"'; ?>><?php echo esc_html($money); ?></span>
                    <span class="aa-bar" style="height:<?php echo esc_attr((string) round($value / $top * 100, 1)); ?>%"></span>
                    <span class="aa-sr"><?php echo esc_html($label . ': ' . $money); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="aa-weeks-x" aria-hidden="true">
            <?php foreach (array_keys($weeks) as $monday) : ?>
                <span><?php echo esc_html($monday === $now ? __('This week', 'askparla-ai-shopping-assistant') : date_i18n('j M', strtotime($monday . ' 12:00'))); ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/**
 * Messages a day for the last 30 days. Drawn only when the Parla server sends the days: an older
 * server sends only the totals, and then the tile above says enough.
 *
 * @param array<int, array{date?: string, messages?: int}> $days Oldest first.
 */
function parla_ui_days_chart(array $days, int $total): void
{
    $days = array_values(array_filter($days, 'is_array'));
    if (count($days) < 7) {
        return;
    }
    $counts = array_map(static fn(array $d): int => (int) ($d['messages'] ?? 0), $days);
    $top = parla_ui_nice_max((float) max($counts));
    $peak = array_search(max($counts), $counts, true);
    $day = static fn(int $i): string => date_i18n('D j M', strtotime(((string) ($days[$i]['date'] ?? '')) . ' 12:00'));
    ?>
    <section class="aa-card">
        <div class="aa-card-head">
            <h3><?php esc_html_e('Messages per day', 'askparla-ai-shopping-assistant'); ?></h3>
            <p class="aa-muted">
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: messages in the last 30 days, 2: the busiest day, 3: messages that day */
                    __('Last 30 days: %1$s in total. Busiest day %2$s, with %3$s.', 'askparla-ai-shopping-assistant'),
                    number_format_i18n($total),
                    $day((int) $peak),
                    number_format_i18n($counts[$peak])
                ));
                ?>
            </p>
        </div>
        <div class="aa-chart aa-chart-days">
            <div class="aa-chart-y" aria-hidden="true">
                <span><?php echo esc_html(number_format_i18n($top)); ?></span>
                <span><?php echo esc_html(number_format_i18n($top / 2)); ?></span>
                <span>0</span>
            </div>
            <div class="aa-chart-plot">
                <ul class="aa-bars is-days" data-aa-bars>
                    <?php foreach ($counts as $i => $n) :
                        /* translators: 1: a day, 2: messages that day */
                        $tip = sprintf(_n('%1$s: %2$s message', '%1$s: %2$s messages', $n, 'askparla-ai-shopping-assistant'), $day($i), number_format_i18n($n));
                        ?>
                        <li data-tip="<?php echo esc_attr($tip); ?>">
                            <span class="aa-bar" style="height:<?php echo esc_attr((string) round($n / $top * 100, 1)); ?>%"></span>
                            <span class="aa-sr"><?php echo esc_html($tip); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="aa-chart-x" aria-hidden="true">
                    <span><?php echo esc_html(date_i18n('j M', strtotime(((string) ($days[0]['date'] ?? '')) . ' 12:00'))); ?></span>
                    <span><?php echo esc_html(date_i18n('j M', strtotime(((string) ($days[(int) floor(count($days) / 2)]['date'] ?? '')) . ' 12:00'))); ?></span>
                    <span><?php esc_html_e('Today', 'askparla-ai-shopping-assistant'); ?></span>
                </div>
            </div>
        </div>
    </section>
    <?php
}

/** One line of the storefront check. $state is good (all set), warn (needs doing) or neutral (a choice). */
function parla_ui_check(string $label, string $value, string $state, string $todo = ''): void
{
    printf(
        '<li class="aa-check is-%s"><span class="aa-check-dot" aria-hidden="true"></span><span class="aa-check-label">%s</span><span class="aa-check-value">%s</span>%s</li>',
        esc_attr($state),
        esc_html($label),
        esc_html($value),
        $todo !== '' ? '<span class="aa-check-todo">' . esc_html($todo) . '</span>' : ''
    );
}

/** What the chat on the shop is running right now, against what was saved here. */
function parla_ui_health(array $s): void
{
    $live = parla_live_config($s);
    if (!$live['ok'] && $live['error'] === '') {
        return;
    }
    $d = $live['data'];
    $warnings = 0;
    ob_start();
    if (!$live['ok'] && $live['error'] === 'account_paused') {
        $warnings++;
        parla_ui_check(__('Parla account', 'askparla-ai-shopping-assistant'), __('paused', 'askparla-ai-shopping-assistant'), 'warn',
            __('The chat server is up, but your Parla account is paused, so shoppers see a connection error. Choose a plan in your Parla account, or write to us, and it answers again within the minute.', 'askparla-ai-shopping-assistant'));
    } elseif (!$live['ok']) {
        $warnings++;
        parla_ui_check(__('Chat server', 'askparla-ai-shopping-assistant'), __('unreachable', 'askparla-ai-shopping-assistant'), 'warn', sprintf(
            /* translators: 1: server address, 2: error */
            __('%1$s did not answer (%2$s). Shoppers see a connection error until it is back.', 'askparla-ai-shopping-assistant'),
            parla_api_base($s),
            $live['error']
        ));
    } else {
        parla_ui_check(__('Chat server', 'askparla-ai-shopping-assistant'), __('answering', 'askparla-ai-shopping-assistant'), 'good');
        $voice_live = !empty($d['voice_calls']);
        $voice_saved = !empty($s['voice_calls']);
        $state = $voice_live ? 'good' : ($voice_saved ? 'warn' : 'neutral');
        $warnings += $state === 'warn' ? 1 : 0;
        parla_ui_check(__('Voice calls', 'askparla-ai-shopping-assistant'), $voice_live ? __('on', 'askparla-ai-shopping-assistant') : __('off', 'askparla-ai-shopping-assistant'), $state,
            $voice_saved && !$voice_live ? __('Switched on here but not live yet: press Save settings.', 'askparla-ai-shopping-assistant') : '');

        $engine = (string) ($d['voice_engine'] ?? 'browser');
        $want = !empty($s['premium_voice']) ? 'vapi' : 'browser';
        // Premium in the plan but not live is usually this month's minutes running out: worth knowing, not a fault.
        $state = $engine === $want ? 'good' : 'neutral';
        parla_ui_check(__('Voice quality', 'askparla-ai-shopping-assistant'), $engine === 'vapi' ? __('premium', 'askparla-ai-shopping-assistant') : __('standard', 'askparla-ai-shopping-assistant'), $state,
            $want === 'vapi' && $engine !== 'vapi' ? __('Premium is in your plan but paused: this month\'s minutes are used up, so calls use the standard voice until next month.', 'askparla-ai-shopping-assistant') : '');

        $cart = !empty($d['cart']);
        $warnings += $cart ? 0 : 1;
        parla_ui_check(__('Add to cart from the chat', 'askparla-ai-shopping-assistant'), $cart ? __('available', 'askparla-ai-shopping-assistant') : __('not available', 'askparla-ai-shopping-assistant'), $cart ? 'good' : 'warn',
            $cart ? '' : __('The server did not recognise this store as WooCommerce. Reconnect to fix it.', 'askparla-ai-shopping-assistant'));

        $theme = (string) ($d['theme'] ?? 'auto');
        parla_ui_check(__('Light or dark', 'askparla-ai-shopping-assistant'),
            $theme === 'auto' ? __("follows the shopper's device", 'askparla-ai-shopping-assistant') : ($theme === 'dark' ? __('always dark', 'askparla-ai-shopping-assistant') : __('always light', 'askparla-ai-shopping-assistant')),
            $theme === ($s['theme'] ?? 'auto') ? 'good' : 'warn');

        $greeting = $d['greeting'] ?? null;
        parla_ui_check(__('Greeting bubble', 'askparla-ai-shopping-assistant'),
            /* translators: %d: the delay in seconds before the greeting appears */
            $greeting ? sprintf(__('after %ds', 'askparla-ai-shopping-assistant'), (int) ($greeting['after_seconds'] ?? 0)) : __('off', 'askparla-ai-shopping-assistant'),
            $greeting ? 'good' : 'neutral');
        parla_ui_check(__('Greeting customers by name', 'askparla-ai-shopping-assistant'), !empty($s['greet_customers']) ? __('on', 'askparla-ai-shopping-assistant') : __('off', 'askparla-ai-shopping-assistant'), !empty($s['greet_customers']) ? 'good' : 'neutral');
    }
    $rows = (string) ob_get_clean();
    ?>
    <details class="aa-card aa-health"<?php echo $warnings ? ' open' : ''; ?>>
        <summary>
            <span>
                <strong><?php esc_html_e('Storefront check', 'askparla-ai-shopping-assistant'); ?></strong>
                <span class="aa-muted"><?php esc_html_e('What the chat on your shop is running right now', 'askparla-ai-shopping-assistant'); ?></span>
            </span>
            <span class="aa-pill <?php echo $warnings ? 'is-warn' : 'is-good'; ?>">
                <?php echo $warnings
                    /* translators: %d: how many things still need fixing */
                    ? esc_html(sprintf(_n('%d thing to fix', '%d things to fix', $warnings, 'askparla-ai-shopping-assistant'), $warnings))
                    : esc_html__('All good', 'askparla-ai-shopping-assistant'); ?>
            </span>
        </summary>
        <ul class="aa-checks"><?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above ?></ul>
        <p class="aa-fine">
            <?php
            printf(
                /* translators: %s: widget script URL */
                esc_html__('Your pages load the chat from %s. After changing anything, reload your shop with a hard refresh.', 'askparla-ai-shopping-assistant'),
                '<code>' . esc_html($s['widget_url'] !== '' ? $s['widget_url'] : parla_api_base($s) . '/widget.js') . '</code>'
            );
            ?>
        </p>
    </details>
    <?php
}

/**
 * What a new shop still has to do to get the most out of Parla. Each step opens the tab where it
 * is done. It disappears once everything is ticked, so it never nags a shop that is set up.
 */
function parla_ui_setup(array $s): void
{
    $steps = [
        ['done' => true, 'label' => __('Connect your shop', 'askparla-ai-shopping-assistant'), 'tab' => 'connection', 'why' => ''],
        ['done' => (int) $s['voice_calls'] === 1, 'label' => __('Let shoppers call the assistant', 'askparla-ai-shopping-assistant'), 'tab' => 'voice', 'why' => __('Shoppers who call ask more and buy more.', 'askparla-ai-shopping-assistant')],
        ['done' => $s['greeting_pages'] !== 'off', 'label' => __('Turn on the greeting bubble', 'askparla-ai-shopping-assistant'), 'tab' => 'first', 'why' => __('Most shoppers never open a chat nobody offered.', 'askparla-ai-shopping-assistant')],
        ['done' => trim((string) $s['policy_excerpts']) !== '', 'label' => __('Add your policy notes', 'askparla-ai-shopping-assistant'), 'tab' => 'knowledge', 'why' => __('Without them it says "I don\'t know" to the most common questions.', 'askparla-ai-shopping-assistant')],
        ['done' => parla_parse_hours((string) $s['support_hours']) !== [], 'label' => __('Say when your team replies', 'askparla-ai-shopping-assistant'), 'tab' => 'knowledge', 'why' => __('So a handoff tells the shopper when to expect an answer.', 'askparla-ai-shopping-assistant')],
        ['done' => strtolower((string) $s['brand_color']) !== '#111827', 'label' => __('Put it in your colours', 'askparla-ai-shopping-assistant'), 'tab' => 'look', 'why' => __('It should look like part of your shop.', 'askparla-ai-shopping-assistant')],
    ];
    $done = count(array_filter($steps, fn ($st) => $st['done']));
    if ($done === count($steps)) {
        return;
    }
    ?>
    <section class="aa-card aa-setup">
        <div class="aa-setup-head">
            <div>
                <h3><?php esc_html_e('Finish setting up', 'askparla-ai-shopping-assistant'); ?></h3>
                <p class="aa-muted"><?php esc_html_e('A few minutes each. This card goes away once everything is done.', 'askparla-ai-shopping-assistant'); ?></p>
            </div>
            <?php /* translators: 1: steps done, 2: steps in total */ ?>
            <span class="aa-setup-count"><?php echo esc_html(sprintf(__('%1$d of %2$d done', 'askparla-ai-shopping-assistant'), $done, count($steps))); ?></span>
        </div>
        <span class="aa-meter"><span style="width:<?php echo esc_attr((string) round($done / count($steps) * 100)); ?>%"></span></span>
        <ol class="aa-setup-list">
            <?php foreach ($steps as $st) : ?>
                <li class="<?php echo $st['done'] ? 'is-done' : ''; ?>">
                    <span class="aa-tick" aria-hidden="true"><?php if ($st['done']) : ?><svg viewBox="0 0 24 24" width="12" height="12"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg><?php endif; ?></span>
                    <span class="aa-setup-text">
                        <strong><?php echo esc_html($st['label']); ?></strong>
                        <?php if (!$st['done'] && $st['why']) : ?><span class="aa-muted"><?php echo esc_html($st['why']); ?></span><?php endif; ?>
                    </span>
                    <?php if ($st['done']) : ?>
                        <span class="screen-reader-text"><?php esc_html_e('Done', 'askparla-ai-shopping-assistant'); ?></span>
                    <?php else : ?>
                        <button type="button" class="aa-btn aa-btn-ghost aa-btn-sm" data-aa-go="<?php echo esc_attr($st['tab']); ?>"><?php esc_html_e('Do it', 'askparla-ai-shopping-assistant'); ?></button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php
}

function parla_ui_overview(array $s): void
{
    if (!parla_is_connected()) {
        ?>
        <section class="aa-card aa-welcome">
            <span class="aa-eyebrow"><?php esc_html_e('Welcome', 'askparla-ai-shopping-assistant'); ?></span>
            <h2><?php esc_html_e('Connect your store to meet your sales assistant', 'askparla-ai-shopping-assistant'); ?></h2>
            <p><?php esc_html_e('Two minutes: create your Parla account (the first 14 days are free, no card), copy the shop key it shows you, paste it here. Once connected, this page shows the sales it helps with, what shoppers ask for, and what they could not find.', 'askparla-ai-shopping-assistant'); ?></p>
            <p class="aa-actions">
                <a class="aa-btn aa-btn-primary" href="<?php echo esc_url(parla_site_url('signup')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Create your free account', 'askparla-ai-shopping-assistant'); ?></a>
                <button type="button" class="aa-btn aa-btn-ghost" data-aa-go="connection"><?php esc_html_e('I have my shop key', 'askparla-ai-shopping-assistant'); ?></button>
                <a class="aa-link" href="<?php echo esc_url(parla_site_url('demo')); ?>" target="_blank" rel="noopener"><?php esc_html_e('See it on a demo shop first', 'askparla-ai-shopping-assistant'); ?></a>
            </p>
        </section>
        <?php
        return;
    }

    $refresh = isset($_GET['aa_refresh']) && check_admin_referer('parla_refresh');
    $report = parla_insights($s, $refresh);
    ?>
    <div class="aa-page-head">
        <div>
            <h2><?php esc_html_e('How it is going', 'askparla-ai-shopping-assistant'); ?></h2>
            <p class="aa-muted"><?php esc_html_e('Everything here is about your shop only.', 'askparla-ai-shopping-assistant'); ?></p>
        </div>
        <a class="aa-btn aa-btn-ghost" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=parla&aa_refresh=1#overview'), 'parla_refresh')); ?>">
            <?php esc_html_e('Refresh', 'askparla-ai-shopping-assistant'); ?>
        </a>
    </div>

    <?php // The money first and wide; the setup list beside it until there is nothing left to do. ?>
    <div class="aa-duo">
        <?php parla_ui_sales(); ?>
        <?php parla_ui_setup($s); ?>
    </div>

    <?php if (!$report['ok']) : ?>
        <div class="aa-card aa-alert">
            <?php /* translators: %s: the reason the figures could not be loaded */ ?>
            <?php printf(esc_html__('The conversation numbers are not available right now (%s). Sales above come from your own orders and are always up to date.', 'askparla-ai-shopping-assistant'), esc_html($report['error'])); ?>
        </div>
        <?php
        parla_ui_health($s);
        return;
    endif;

    $d = $report['data'];
    $m = $d['messages'] ?? [];
    $helpful = $d['helpful'] ?? [];
    $events = $d['events'] ?? [];
    $premium = $d['premium_voice'] ?? null;
    ?>
    <div class="aa-kpis">
        <?php
        parla_ui_kpi(
            __('Messages', 'askparla-ai-shopping-assistant'),
            number_format_i18n((int) ($m['last_30_days'] ?? 0)),
            /* translators: 1: conversations today, 2: conversations this week */
            sprintf(__('%1$s today, %2$s this week', 'askparla-ai-shopping-assistant'), number_format_i18n((int) ($m['today'] ?? 0)), number_format_i18n((int) ($m['last_7_days'] ?? 0)))
        );
        $rated = (int) ($helpful['up'] ?? 0) + (int) ($helpful['down'] ?? 0);
        parla_ui_kpi(
            __('Found it helpful', 'askparla-ai-shopping-assistant'),
            $rated >= 5 && isset($helpful['rate']) ? $helpful['rate'] . '%' : '–',
            /* translators: %d: how many shoppers rated an answer */
            $rated >= 5 ? sprintf(_n('from %d rating', 'from %d ratings', $rated, 'askparla-ai-shopping-assistant'), $rated) : __('shows after 5 ratings', 'askparla-ai-shopping-assistant')
        );
        parla_ui_kpi(__('Handed to your team', 'askparla-ai-shopping-assistant'), number_format_i18n((int) ($events['escalations'] ?? 0)), __('conversations that needed a person', 'askparla-ai-shopping-assistant'));
        // Counted from this site's own waiting lists rather than from Parla's tally: these are the
        // people still waiting right now, which is the number worth acting on.
        parla_ui_kpi(__('Waiting for a restock', 'askparla-ai-shopping-assistant'), number_format_i18n(parla_waiting_count('restock')), __('emailed when it is back', 'askparla-ai-shopping-assistant'));
        parla_ui_kpi(__('Asked for, not sold', 'askparla-ai-shopping-assistant'), number_format_i18n(parla_waiting_count('wanted')), __('people waiting to hear', 'askparla-ai-shopping-assistant'));
        // An older server, or a plan without premium voice, simply leaves these out.
        if (!empty($s['premium_voice']) && is_array($premium) && (int) ($premium['minutes_budget'] ?? 0) > 0) {
            $used = (float) ($premium['minutes_used'] ?? 0);
            $budget = (int) ($premium['minutes_budget'] ?? 0);
            $calls = (int) ($premium['calls'] ?? 0);
            parla_ui_kpi(
                __('Premium call minutes', 'askparla-ai-shopping-assistant'),
                sprintf('%s / %s', number_format_i18n($used, $used < 10 ? 1 : 0), number_format_i18n($budget)),
                /* translators: %d: how many premium voice calls this month */
                sprintf(_n('%d call this month', '%d calls this month', $calls, 'askparla-ai-shopping-assistant'), $calls),
                $used / $budget
            );
        }
        ?>
    </div>

    <?php parla_ui_days_chart((array) ($m['daily'] ?? []), (int) ($m['last_30_days'] ?? 0)); ?>

    <div class="aa-duo">
    <?php $waiting = $d['waiting_list'] ?? []; if ($waiting) : ?>
        <section class="aa-card aa-waiting">
            <div class="aa-card-head">
                <h3><?php esc_html_e('Asked for, and waiting to hear', 'askparla-ai-shopping-assistant'); ?></h3>
                <p class="aa-muted"><?php esc_html_e('You do not sell these yet. Add a matching product and everyone on the list gets one email from your shop, automatically.', 'askparla-ai-shopping-assistant'); ?></p>
            </div>
            <ol class="aa-list">
                <?php foreach ($waiting as $row) : $people = (int) ($row['people'] ?? 0); ?>
                    <li>
                        <span><?php echo esc_html((string) ($row['phrase'] ?? '')); ?></span>
                        <?php /* translators: %d: how many shoppers are waiting for this product */ ?>
                        <span class="aa-count"><?php echo esc_html(sprintf(_n('%d person', '%d people', $people, 'askparla-ai-shopping-assistant'), $people)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
    <?php endif; ?>

    <?php
    /*
     * Counts here, the lists on their own tab. Three ranked lists and a table made the Overview a
     * page you scrolled past rather than read; a number you can act on is what belongs on a summary.
     */
    $miss = (int) (($d['not_found_total']['terms'] ?? 0));
    $gap = (int) (($d['unanswered_total']['terms'] ?? 0));
    $bad = (int) ($d['helpful']['down'] ?? 0);
    if ($miss || $gap || $bad) :
        $asked = [
            [$miss, __('Searches that found nothing', 'askparla-ai-shopping-assistant'), __('Your restocking list', 'askparla-ai-shopping-assistant')],
            [$gap, __('Questions your pages did not answer', 'askparla-ai-shopping-assistant'), __('Each is a paragraph worth writing', 'askparla-ai-shopping-assistant')],
            [$bad, __('Answers marked unhelpful', 'askparla-ai-shopping-assistant'), __('Usually one missing line of policy', 'askparla-ai-shopping-assistant')],
        ];
        ?>
        <section class="aa-card aa-asked">
            <div class="aa-card-head">
                <h3><?php esc_html_e('What shoppers asked for', 'askparla-ai-shopping-assistant'); ?></h3>
                <p class="aa-muted"><?php esc_html_e('Last 30 days. The details are on the Reports tab.', 'askparla-ai-shopping-assistant'); ?></p>
            </div>
            <div class="aa-asked-grid">
                <?php foreach ($asked as [$n, $label, $hint]) : ?>
                    <button type="button" class="aa-asked-tile" data-aa-go="reports">
                        <strong><?php echo esc_html(number_format_i18n($n)); ?></strong>
                        <span><?php echo esc_html($label); ?></span>
                        <small><?php echo esc_html($hint); ?></small>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    </div>

    <?php parla_ui_health($s);
}

// ---------------------------------------------------------------- settings tabs

/** The detail behind the Overview's three counts: what shoppers looked for, asked and disliked. */
function parla_ui_tab_reports(array $s): void
{
    $d = parla_insights($s)['data'] ?? [];
    // The waiting lists live on this site, not on Parla: they show even when the figures cannot load.
    if (!$d) {
        printf('<p class="aa-empty">%s</p>', esc_html__('Nothing to report yet. Come back once shoppers have used the chat.', 'askparla-ai-shopping-assistant'));
    }
    if ($d) :
    ?>
    <div class="aa-grid-2">
        <section class="aa-card">
            <div class="aa-card-head">
                <h3><?php esc_html_e('Looked for, not found', 'askparla-ai-shopping-assistant'); ?></h3>
                <p class="aa-muted"><?php esc_html_e('Searches with no result in the last 30 days. Your restocking list.', 'askparla-ai-shopping-assistant'); ?></p>
            </div>
            <?php parla_ui_topics($d['not_found'] ?? [], __('Nothing yet. Every search found something.', 'askparla-ai-shopping-assistant'), (array) ($d['not_found_total'] ?? [])); ?>
        </section>
        <section class="aa-card">
            <div class="aa-card-head">
                <h3><?php esc_html_e('Asked, but your pages do not say', 'askparla-ai-shopping-assistant'); ?></h3>
                <p class="aa-muted"><?php esc_html_e('Questions your published pages had no answer for. Each is a paragraph worth writing.', 'askparla-ai-shopping-assistant'); ?></p>
            </div>
            <?php parla_ui_topics($d['unanswered'] ?? [], __('Nothing yet. Your pages covered every question.', 'askparla-ai-shopping-assistant'), (array) ($d['unanswered_total'] ?? [])); ?>
        </section>
    </div>

    <?php
    endif;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag that only decides whether a "done" notice is printed; nothing is read from it.
    if (isset($_GET['parla_forgot'])) {
        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Removed from the list.', 'askparla-ai-shopping-assistant'));
    }
    echo '<div class="aa-grid-2 aa-waitlists">';
    parla_ui_waiting(
        'restock',
        __('Waiting for a restock', 'askparla-ai-shopping-assistant'),
        __('They asked to be told when a sold-out size or colour is back. The email leaves from your shop, once, and the address is deleted with it.', 'askparla-ai-shopping-assistant'),
        __('Nobody is waiting on a restock right now.', 'askparla-ai-shopping-assistant')
    );
    parla_ui_waiting(
        'wanted',
        __('Asked for something you do not sell', 'askparla-ai-shopping-assistant'),
        __('They agreed to hear from you if it ever arrives. Nothing was promised: the assistant only ever says it will get in touch.', 'askparla-ai-shopping-assistant'),
        __('Nobody is waiting on something you do not carry.', 'askparla-ai-shopping-assistant')
    );
    echo '</div>';
    printf(
        '<p class="aa-muted aa-waiting-note">%s</p>',
        esc_html__('These addresses are on your site, not on Parla. Each one is used for one message and deleted when it goes out.', 'askparla-ai-shopping-assistant')
    );
    ?>

    <?php if (!empty($d['unhelpful'])) : ?>
        <section class="aa-card">
            <div class="aa-card-head">
                <h3><?php esc_html_e('Answers shoppers marked unhelpful', 'askparla-ai-shopping-assistant'); ?></h3>
                <p class="aa-muted"><?php esc_html_e('Most are fixed by adding a line to the policy notes or the sizing advice.', 'askparla-ai-shopping-assistant'); ?></p>
                <?php
                // The server sends the ten most recent; the vote count is the real total.
                $down = (int) ($d['helpful']['down'] ?? 0);
                $sent = count((array) $d['unhelpful']);
                $rows_shown = min($sent, 10);
                if ($down > $rows_shown) {
                    printf(
                        '<p class="aa-help aa-total">%s</p>',
                        esc_html(sprintf(
                            /* translators: 1: how many are listed, 2: how many there were in total */
                            __('The %1$s most recent, of %2$s marked unhelpful in 30 days', 'askparla-ai-shopping-assistant'),
                            number_format_i18n($rows_shown),
                            number_format_i18n($down)
                        ))
                    );
                }
                ?>
            </div>
            <div class="aa-table-wrap">
                <table class="aa-table">
                    <thead><tr>
                        <th><?php esc_html_e('They asked', 'askparla-ai-shopping-assistant'); ?></th>
                        <th><?php esc_html_e('It answered', 'askparla-ai-shopping-assistant'); ?></th>
                        <th><?php esc_html_e('When', 'askparla-ai-shopping-assistant'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach (array_slice($d['unhelpful'], 0, 50) as $i => $row) : ?>
                        <tr<?php echo $i >= 10 ? ' class="aa-more" hidden' : ''; ?>>
                            <td><?php echo esc_html(wp_trim_words((string) ($row['question'] ?? ''), 24)); ?></td>
                            <td class="aa-muted"><?php echo esc_html(wp_trim_words((string) ($row['answer'] ?? ''), 40)); ?></td>
                            <td class="aa-nowrap"><?php echo esc_html(mysql2date(get_option('date_format'), (string) ($row['at'] ?? ''))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            if ($sent > 10) {
                printf(
                    '<button type="button" class="aa-link" data-aa-more data-less="%s">%s</button>',
                    esc_attr__('Show fewer', 'askparla-ai-shopping-assistant'),
                    esc_html(sprintf(
                        /* translators: %s: how many more rows are hidden */
                        __('Show %s more', 'askparla-ai-shopping-assistant'),
                        number_format_i18n($sent - 10)
                    ))
                );
            }
            // 50 is what the server sends; beyond that a merchant needs to work through these, not scroll.
            if ($down > $sent) {
                printf(
                    '<p class="aa-help">%s</p>',
                    esc_html(sprintf(
                        /* translators: %s: how many are listed */
                        __('The %s most recent are listed. Fix a few and the rest will thin out on their own.', 'askparla-ai-shopping-assistant'),
                        number_format_i18n($sent)
                    ))
                );
            }
            ?>
        </section>
    <?php endif; ?>
    <?php
}

/** Knowledge & team: the facts it may repeat, how it reads orders, and who takes over. */
function parla_ui_tab_knowledge(array $s): void
{
    parla_ui_section(__('What it may tell shoppers', 'askparla-ai-shopping-assistant'), __('The assistant only repeats facts you give it here or on your published pages. It never guesses a policy.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_row(__('Return window', 'askparla-ai-shopping-assistant'), __('Days after delivery a shopper can send something back.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<span class="aa-suffix"><input name="return_window_days" id="aa_window" type="number" min="1" max="365" class="aa-in aa-in-sm" value="%s"><span>%s</span></span>',
                esc_attr((string) $s['return_window_days']),
                esc_html__('days', 'askparla-ai-shopping-assistant')
            );
        }, 'aa_window');
        parla_ui_row(__('Policy notes', 'askparla-ai-shopping-assistant'), __('Short facts it may share: shipping times, free returns, final-sale rules.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<textarea name="policy_excerpts" id="aa_policy" rows="4" class="aa-in" placeholder="%s">%s</textarea>',
                esc_attr__('Free returns in store. Final-sale items cannot be returned. Delivery in 2 to 4 working days.', 'askparla-ai-shopping-assistant'),
                esc_textarea($s['policy_excerpts'])
            );
        }, 'aa_policy');
        parla_ui_row(__('Fit and compatibility', 'askparla-ai-shopping-assistant'), __('What a shopper needs to know to buy the right one: sizes for clothes and shoes, dimensions for furniture, which models a case, charger or spare part works with. Without it, it never guesses a size or whether something fits.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<textarea name="size_guide" id="aa_sizes" rows="4" class="aa-in" placeholder="%s">%s</textarea>',
                esc_attr__('Runs small, size up if you are between sizes. Chest: S 96cm, M 102cm, L 108cm. Phone cases fit only the model in their name: an iPhone 15 case does not fit the 15 Pro.', 'askparla-ai-shopping-assistant'),
                esc_textarea($s['size_guide'])
            );
        }, 'aa_sizes');
    });

    parla_ui_section(__('Orders', 'askparla-ai-shopping-assistant'), __('How the assistant reads your order statuses.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_row(__('"Completed" means', 'askparla-ai-shopping-assistant'), __('WooCommerce has no delivered status. Which does yours mean?', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_segmented('completed_means', [
                'delivered' => __('Delivered', 'askparla-ai-shopping-assistant'),
                'shipped' => __('Shipped', 'askparla-ai-shopping-assistant'),
            ], (string) $s['completed_means'], __('"Completed" means', 'askparla-ai-shopping-assistant'));
        });
    });

    parla_ui_section(__('Handing over to your team', 'askparla-ai-shopping-assistant'), __('When a shopper needs a person, the conversation is emailed to you.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_row(__('Send handoffs to', 'askparla-ai-shopping-assistant'), __('Empty uses the site admin address.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf('<input name="support_email" id="aa_support_email" type="email" class="aa-in" placeholder="%s" value="%s">', esc_attr((string) get_option('admin_email')), esc_attr($s['support_email']));
        }, 'aa_support_email');
        /* translators: %s: the shop's timezone, as WordPress has it */
        parla_ui_row(__('Opening hours', 'askparla-ai-shopping-assistant'), sprintf(__('So shoppers know when a person will reply. Your site timezone is %s.', 'askparla-ai-shopping-assistant'), wp_timezone_string()), function () use ($s) {
            printf(
                '<input name="support_hours" id="aa_hours" type="text" class="aa-in" placeholder="%s" value="%s">',
                /* translators: an example of opening hours; translate the day names for your language */
                esc_attr__('Mon-Fri 09:00-18:00, Sat 10:00-14:00', 'askparla-ai-shopping-assistant'),
                esc_attr($s['support_hours'])
            );
            // Typed but unreadable means the shop has no hours at all as far as the chat knows, and
            // it would tell every shopper nobody replies. Better to say so here.
            if (trim((string) $s['support_hours']) !== '' && parla_parse_hours((string) $s['support_hours']) === []) {
                printf(
                    '<p class="aa-help aa-help-warn">%s</p>',
                    /* translators: an example of opening hours in the format the field reads; translate the day names */
                    esc_html__('These hours could not be read. Write a day or a span of days, then two times, for example: Mon-Fri 09:00-18:00, Sat 10:00-14:00.', 'askparla-ai-shopping-assistant')
                );
            }
        }, 'aa_hours');
        parla_ui_row(__('Usual reply time', 'askparla-ai-shopping-assistant'), __('Said to shoppers outside your hours.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf('<input name="reply_within" id="aa_reply" type="text" class="aa-in" placeholder="%s" value="%s">', esc_attr__('within a few hours', 'askparla-ai-shopping-assistant'), esc_attr($s['reply_within']));
        }, 'aa_reply');
    });
}

/** Look: whether the chat shows at all, and the colours, theme and language it wears. */
function parla_ui_tab_look(array $s): void
{
    parla_ui_section(__('Your shop', 'askparla-ai-shopping-assistant'), '', function () use ($s) {
        parla_ui_master(__('Chat on the storefront', 'askparla-ai-shopping-assistant'), __('Hide it without disconnecting, for example while you set things up.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_switch('enabled', (int) $s['enabled'] === 1, __('Show the chat to shoppers', 'askparla-ai-shopping-assistant'));
        });
        parla_ui_row(__('Store name', 'askparla-ai-shopping-assistant'), __('Shown in the chat header and used by the assistant.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf('<input name="store_name" id="aa_name" type="text" class="aa-in" value="%s">', esc_attr($s['store_name']));
        }, 'aa_name');
    });

    parla_ui_brand($s);
}

/** Assistant: its name, its status line and the way it talks. */
function parla_ui_tab_agent(array $s): void
{
    parla_ui_section(__('Who it is', 'askparla-ai-shopping-assistant'), __('Name and status line preview as you type. Whatever you call it, it still tells shoppers it is an AI.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_row(__('Name', 'askparla-ai-shopping-assistant'), __('Shown in the chat and spoken on calls. Empty means no name.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<input name="agent_name" id="aa_agent_name" type="text" maxlength="120" class="aa-in" placeholder="%s" value="%s">',
                esc_attr__('Your shop\'s assistant', 'askparla-ai-shopping-assistant'),
                // Never set up: Parla, so a shop that ignores this setting still has a named agent.
                esc_attr($s['agent_name'] !== '' ? $s['agent_name'] : parla_default_agent_name())
            );
        }, 'aa_agent_name');
        parla_ui_row(__('Status line', 'askparla-ai-shopping-assistant'), __('Keep ours and it is translated. Write your own and everyone reads yours.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<span class="aa-prefixed"><span class="aa-prefixed-label">%s,</span><input name="agent_status" id="aa_agent_status" type="text" maxlength="60" class="aa-in" placeholder="%s" value="%s"></span>',
                esc_html__('AI assistant', 'askparla-ai-shopping-assistant'),
                esc_attr(parla_default_status()),
                // Pre-filled rather than empty, so the merchant sees the whole line as shoppers will.
                esc_attr($s['agent_status'] !== '' ? $s['agent_status'] : parla_default_status())
            );
        }, 'aa_agent_status');
        parla_ui_row(__('Manner', 'askparla-ai-shopping-assistant'), __('Applies after you save.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            $tones = [
                'warm' => [__('Warm', 'askparla-ai-shopping-assistant'), __('Friendly and personal, uses the shopper\'s name', 'askparla-ai-shopping-assistant')],
                'neutral' => [__('Neutral', 'askparla-ai-shopping-assistant'), __('Plain and efficient. A safe default', 'askparla-ai-shopping-assistant')],
                'expert' => [__('Expert', 'askparla-ai-shopping-assistant'), __('Leads with the detail that settles the question', 'askparla-ai-shopping-assistant')],
                'playful' => [__('Playful', 'askparla-ai-shopping-assistant'), __('Light and informal, for brands that talk that way', 'askparla-ai-shopping-assistant')],
            ];
            echo '<div class="aa-tones" role="radiogroup" aria-label="' . esc_attr__('Manner', 'askparla-ai-shopping-assistant') . '">';
            foreach ($tones as $key => [$label, $hint]) {
                printf(
                    '<label class="aa-tone"><input type="radio" name="agent_tone" value="%s"%s><span><strong>%s</strong><small>%s</small></span></label>',
                    esc_attr($key),
                    checked($s['agent_tone'], $key, false),
                    esc_html($label),
                    esc_html($hint)
                );
            }
            echo '</div>';
        });
        parla_ui_row(__('Anything else', 'askparla-ai-shopping-assistant'), __('Changes how it sounds, not what it may claim.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<textarea name="agent_notes" id="aa_agent_notes" rows="3" maxlength="300" class="aa-in" placeholder="%s">%s</textarea><p class="aa-help aa-count" data-for="aa_agent_notes">%s</p>',
                esc_attr__('We say "trainers", never "sneakers". Never rush anyone into a size.', 'askparla-ai-shopping-assistant'),
                esc_textarea($s['agent_notes']),
                esc_html(sprintf(
                    /* translators: %d: number of characters left */
                    __('%d characters left', 'askparla-ai-shopping-assistant'),
                    300 - strlen($s['agent_notes'])
                ))
            );
        }, 'aa_agent_notes');
    });
}

/** The Brand section of Look. */
function parla_ui_brand(array $s): void
{
    parla_ui_section(__('Brand', 'askparla-ai-shopping-assistant'), __('The chat wears your colours, so it looks like part of your shop.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_row(__('Colours', 'askparla-ai-shopping-assistant'), __('Main is for buttons and the header. Text on main must be readable on it.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            echo '<div class="aa-colors">';
            foreach (['brand_color' => __('Main', 'askparla-ai-shopping-assistant'), 'brand_contrast' => __('Text on main', 'askparla-ai-shopping-assistant')] as $name => $label) {
                printf(
                    '<label class="aa-color"><input type="color" name="%1$s" value="%2$s" data-aa-hex="aa_hex_%1$s"><span><strong>%3$s</strong><code id="aa_hex_%1$s">%2$s</code></span></label>',
                    esc_attr($name),
                    esc_attr((string) $s[$name]),
                    esc_html($label)
                );
            }
            echo '</div>';
            echo '<div class="aa-presets" role="group" aria-label="' . esc_attr__('Quick colours', 'askparla-ai-shopping-assistant') . '">';
            $presets = [
                '#111827' => __('Ink', 'askparla-ai-shopping-assistant'), '#ff5a36' => __('Coral', 'askparla-ai-shopping-assistant'), '#2563eb' => __('Blue', 'askparla-ai-shopping-assistant'),
                '#0f766e' => __('Teal', 'askparla-ai-shopping-assistant'), '#16a34a' => __('Green', 'askparla-ai-shopping-assistant'), '#be185d' => __('Rose', 'askparla-ai-shopping-assistant'),
                '#b45309' => __('Amber', 'askparla-ai-shopping-assistant'), '#7c3aed' => __('Violet', 'askparla-ai-shopping-assistant'),
            ];
            foreach ($presets as $hex => $label) {
                printf('<button type="button" class="aa-preset" data-aa-preset="%1$s" style="--c:%1$s" title="%2$s" aria-label="%2$s" aria-pressed="false"></button>', esc_attr($hex), esc_attr($label));
            }
            echo '<span class="aa-muted aa-presets-note">' . esc_html__('Text colour follows, so it stays readable.', 'askparla-ai-shopping-assistant') . '</span>';
            echo '</div>';
        });
        parla_ui_row(__('Light or dark', 'askparla-ai-shopping-assistant'), __('Auto matches whatever the shopper\'s phone or laptop is set to.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_segmented('theme', ['auto' => __('Auto', 'askparla-ai-shopping-assistant'), 'light' => __('Light', 'askparla-ai-shopping-assistant'), 'dark' => __('Dark', 'askparla-ai-shopping-assistant')], (string) $s['theme'], __('Light or dark', 'askparla-ai-shopping-assistant'));
        });
        parla_ui_row(__('Button language', 'askparla-ai-shopping-assistant'), __('For buttons and hints. Replies always follow the shopper.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_select('locale', 'aa_locale', [
                'auto' => __("Follow the shopper's browser", 'askparla-ai-shopping-assistant'),
                'en' => 'English', 'fr' => 'Français', 'es' => 'Español', 'de' => 'Deutsch',
                'it' => 'Italiano', 'nl' => 'Nederlands', 'pt' => 'Português', 'ar' => 'العربية',
            ], (string) $s['locale']);
        }, 'aa_locale');
    });

}

/** First screen: the greeting bubble outside the chat, then what an empty chat offers. */
function parla_ui_tab_first(array $s): void
{
    parla_ui_section(__('Before they open the chat', 'askparla-ai-shopping-assistant'), __('The greeting bubble appears once per visit, next to the chat button.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_greeting_row($s);
    });

    parla_ui_section(__('When the chat opens', 'askparla-ai-shopping-assistant'), __('What a shopper sees before they ask anything.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_row(__('Greet customers by name', 'askparla-ai-shopping-assistant'), __('Signed-in customers see "Hi Sarah!". Everyone else sees "Hi!".', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_switch('greet_customers', !empty($s['greet_customers']), __('Use the customer\'s first name', 'askparla-ai-shopping-assistant'));
        });
        parla_ui_row(__('Popular products', 'askparla-ai-shopping-assistant'), __('Turn them off if you sell services, or while your products have no photos.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_segmented('suggest_products', [
                'both' => __('Everywhere', 'askparla-ai-shopping-assistant'),
                'home' => __('First screen', 'askparla-ai-shopping-assistant'),
                'off' => __('Off', 'askparla-ai-shopping-assistant'),
            ], (string) $s['suggest_products'], __('Popular products', 'askparla-ai-shopping-assistant'));
        });
        parla_ui_row(__('Track an order', 'askparla-ai-shopping-assistant'), __('A button on the first screen. Turn it off if you sell services or downloads.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_switch('show_track_order', (int) $s['show_track_order'] === 1, __('Show the track an order button', 'askparla-ai-shopping-assistant'));
        });
        parla_ui_row(__('Openers', 'askparla-ai-shopping-assistant'), __('The questions an empty chat offers. Tap-sized: a few words, not a sentence.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            /*
             * Ours are shown as placeholders, never as values.
             *
             * They used to be filled in as real values, defended by a save-time check that cleared
             * them only when all three came back exactly as issued. Change one and the other two
             * went in with it, so a shop that wanted a single question of its own ended up storing
             * two of ours as well, frozen in the language the admin happened to be in at the time.
             * An empty box posts nothing, which is what keeps each shopper reading them in their
             * own language.
             */
            $lines = $s['opener_chips'];
            $ours = parla_default_openers();
            echo '<div class="aa-openers" id="aa-openers">';
            foreach (array_pad(array_slice($lines, 0, 6), 3, '') as $i => $line) {
                printf(
                    '<div class="aa-opener"><input name="opener_chips[]" type="text" maxlength="80" class="aa-in" value="%s" placeholder="%s"><button type="button" class="aa-opener-x" aria-label="%s">&times;</button></div>',
                    esc_attr((string) $line),
                    esc_attr((string) ($ours[$i] ?? __('Do you ship to Belgium?', 'askparla-ai-shopping-assistant'))),
                    esc_attr__('Remove this opener', 'askparla-ai-shopping-assistant')
                );
            }
            echo '</div>';
            printf(
                '<button type="button" class="aa-link" id="aa-opener-add" data-max="6" data-placeholder="%s" data-remove="%s">%s</button>',
                esc_attr__('Do you ship to Belgium?', 'askparla-ai-shopping-assistant'),
                esc_attr__('Remove this opener', 'askparla-ai-shopping-assistant'),
                esc_html__('Add an opener', 'askparla-ai-shopping-assistant')
            );
        });
    });
}

/** The greeting bubble row: its words, how long it waits and on which pages. */
function parla_ui_greeting_row(array $s): void
{
        parla_ui_row(__('Greeting bubble', 'askparla-ai-shopping-assistant'), __('Shown once per visit, next to the chat button. Empty uses our hello, translated.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<input name="greeting_text" id="aa_greeting" type="text" class="aa-in" placeholder="%s" value="%s">',
                esc_attr__('Standard: Hi! Looking for something in particular?', 'askparla-ai-shopping-assistant'),
                esc_attr($s['greeting_text'])
            );
            echo '<div class="aa-inline">';
            printf(
                '<span class="aa-suffix"><input name="greeting_seconds" type="number" min="2" max="120" class="aa-in aa-in-sm" value="%s" aria-label="%s"><span>%s</span></span>',
                esc_attr((string) $s['greeting_seconds']),
                esc_attr__('Seconds before it appears', 'askparla-ai-shopping-assistant'),
                esc_html__('seconds, on', 'askparla-ai-shopping-assistant')
            );
            parla_ui_select('greeting_pages', 'aa_greeting_pages', [
                'off' => __('no page (off)', 'askparla-ai-shopping-assistant'),
                'cart_checkout' => __('cart and checkout', 'askparla-ai-shopping-assistant'),
                'all' => __('every page', 'askparla-ai-shopping-assistant'),
            ], (string) $s['greeting_pages']);
            echo '</div>';
        }, 'aa_greeting');
}

function parla_ui_tab_voice(array $s): void
{
    parla_ui_section(__('Voice calls', 'askparla-ai-shopping-assistant'), __('Shoppers can talk to the assistant hands-free, and still see products and links on screen.', 'askparla-ai-shopping-assistant'), function () use ($s) {
        parla_ui_row(__('Call button', 'askparla-ai-shopping-assistant'), __('Speech is processed off-device. Mention it in your privacy policy.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_switch('voice_calls', (int) $s['voice_calls'] === 1, __('Let shoppers call the assistant', 'askparla-ai-shopping-assistant'));
        });
        parla_ui_row(__('Premium voice', 'askparla-ai-shopping-assistant'), __('Natural voices shoppers can interrupt, in any browser and their own language.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            if (empty($s['premium_voice'])) {
                printf(
                    '<p class="aa-callout"><strong>%s</strong><br>%s</p>',
                    esc_html__('Not on your plan yet', 'askparla-ai-shopping-assistant'),
                    esc_html__('Shoppers hear their own browser\'s voice, in Chrome and Safari. Premium switches on here by itself once your plan includes it.', 'askparla-ai-shopping-assistant')
                );
                return;
            }
            parla_ui_switch('premium_voice_on', (int) $s['premium_voice_on'] === 1, __('Use the premium voice', 'askparla-ai-shopping-assistant'));

            /*
             * The minutes left, right next to the switch.
             *
             * This is the one number the decision turns on, and it used to live on another tab: a
             * merchant deciding whether to keep their minutes had to go and look, so they did not.
             * The call is already memoised for this request, so reading it here is free.
             */
            $d = parla_insights($s)['data'] ?? [];
            $budget = (int) ($d['premium_voice']['minutes_budget'] ?? 0);
            $used = (float) ($d['premium_voice']['minutes_used'] ?? 0);
            if ($budget > 0) {
                $left = max(0, $budget - (int) round($used));
                printf(
                    '<p class="aa-help">%s</p>',
                    esc_html(sprintf(
                        /* translators: 1: minutes left, 2: minutes included in the plan */
                        __('%1$s of %2$s minutes left this month. When they run out, calls carry on with the standard voice.', 'askparla-ai-shopping-assistant'),
                        number_format_i18n($left),
                        number_format_i18n($budget)
                    ))
                );
            } else {
                printf('<p class="aa-help">%s</p>', esc_html__('Off keeps your minutes and uses the shopper\'s own browser voice.', 'askparla-ai-shopping-assistant'));
            }
        });
    });

    /*
     * Which premium voice speaks. The list comes from Parla, never from the voice provider: every
     * voice on it has been listened to reading a price out loud, which is most of what a sales
     * assistant says. The clips are pre-recorded files, so trying all of them costs nothing.
     */
    if (!empty($s['premium_voice'])) {
        parla_ui_section(__('Which voice speaks', 'askparla-ai-shopping-assistant'), __('Press play to hear each one say a price.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            parla_ui_row(__('Voice', 'askparla-ai-shopping-assistant'), __('A voice speaks only its own language. Automatic picks one per shopper.', 'askparla-ai-shopping-assistant'), function () use ($s) {
                printf(
                    '<input type="hidden" name="premium_voice_id" id="aa_premium_voice_id" value="%s">',
                    esc_attr($s['premium_voice_id'])
                );
                // A group of radios, not one field: labelled here rather than with a <label for>,
                // which would point at the hidden input nobody can click.
                printf(
                    '<div id="aa-premium-voices" class="aa-voicelist" role="radiogroup" aria-label="%s" data-loading="%s" data-empty="%s" data-auto="%s" data-langs="%s"></div>',
                    esc_attr__('Premium voice', 'askparla-ai-shopping-assistant'),
                    esc_attr__('Loading voices…', 'askparla-ai-shopping-assistant'),
                    esc_attr__('No voices to choose from right now, so calls use Parla\'s own pick.', 'askparla-ai-shopping-assistant'),
                    esc_attr__('Automatic', 'askparla-ai-shopping-assistant'),
                    esc_attr__('Language', 'askparla-ai-shopping-assistant')
                );
            });
        });
    }

    // Also used when premium minutes run out for the month, so it is always worth setting.
    $standard_note = empty($s['premium_voice'])
        ? __('Voices belong to the shopper\'s device, so a name is a preference, not a guarantee.', 'askparla-ai-shopping-assistant')
        : __('Used only once this month\'s premium minutes run out. Voices belong to the shopper\'s device, so a name is a preference, not a guarantee.', 'askparla-ai-shopping-assistant');
    parla_ui_section(__('Standard voice', 'askparla-ai-shopping-assistant'), $standard_note, function () use ($s) {
        parla_ui_row(__('Preferred voice', 'askparla-ai-shopping-assistant'), __('Your device\'s voices. Each shopper gets the nearest match on theirs.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            // A name typed into a box could never be right: a voice either exists on the shopper's
            // device or it does not, and no merchant knows the catalogue of eight operating systems.
            printf('<input type="hidden" name="voice_name" id="aa_voice_name" value="%s">', esc_attr($s['voice_name']));
            printf(
                '<div id="aa-voices" class="aa-chips" data-best="%s" data-saved="%s" data-none="%s"></div>',
                esc_attr__('Best available', 'askparla-ai-shopping-assistant'),
                esc_attr($s['voice_name']),
                esc_attr__('This browser offers no voices, so the setting cannot be previewed here. Shoppers still get theirs.', 'askparla-ai-shopping-assistant')
            );
        });
        parla_ui_row(__('Speaking speed', 'askparla-ai-shopping-assistant'), '', function () use ($s) {
            printf(
                '<span class="aa-range"><input name="voice_rate" id="aa_voice_rate" type="range" step="0.05" min="0.6" max="1.6" value="%1$s"><output for="aa_voice_rate">%1$s×</output></span>',
                esc_attr((string) $s['voice_rate'])
            );
        }, 'aa_voice_rate');
    });
}

function parla_ui_tab_connection(array $s, bool $connected): void
{
    parla_ui_section(__('Connection', 'askparla-ai-shopping-assistant'), __('The key that links this shop to your Parla account.', 'askparla-ai-shopping-assistant'), function () use ($s, $connected) {
        if (!$connected) {
            parla_ui_row(__('No account yet?', 'askparla-ai-shopping-assistant'), __('The first 14 days are free, with no card. The key for this shop is shown as soon as the account exists.', 'askparla-ai-shopping-assistant'), function () {
                printf(
                    '<a class="aa-btn aa-btn-primary" href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url(parla_site_url('signup')),
                    esc_html__('Create your free Parla account', 'askparla-ai-shopping-assistant')
                );
            });
        }
        parla_ui_row(__('Shop key', 'askparla-ai-shopping-assistant'), __('Starts with parla_live_. Empty keeps the saved one.', 'askparla-ai-shopping-assistant'), function () use ($s) {
            printf(
                '<input name="connect_secret" id="aa_secret" type="password" class="aa-in aa-mono" autocomplete="off" placeholder="%s">',
                $s['connect_secret'] ? esc_attr__('Saved (leave empty to keep it)', 'askparla-ai-shopping-assistant') : ''
            );
        }, 'aa_secret');
        parla_ui_row($connected ? __('Status', 'askparla-ai-shopping-assistant') : __('Connect', 'askparla-ai-shopping-assistant'), '', function () use ($s, $connected) {
            if ($connected) {
                printf(
                    '<p class="aa-callout is-good">%s</p>',
                    wp_kses(sprintf(
                        /* translators: 1: store id, 2: date */
                        __('Connected as %1$s since %2$s.', 'askparla-ai-shopping-assistant'),
                        '<code>' . esc_html($s['store_id']) . '</code>',
                        esc_html($s['connected_at'])
                    ), ['code' => []])
                );
            }
            printf(
                '<button type="submit" name="connect" value="1" class="aa-btn %s">%s</button>',
                $connected ? 'aa-btn-ghost' : 'aa-btn-primary',
                $connected ? esc_html__('Reconnect', 'askparla-ai-shopping-assistant') : esc_html__('Connect store', 'askparla-ai-shopping-assistant')
            );
            if (!$connected) {
                printf(
                    '<ul class="aa-steps"><li>%s</li><li>%s</li><li>%s</li></ul>',
                    esc_html__('Creates a WooCommerce API key named "Parla" so the assistant can read products and orders.', 'askparla-ai-shopping-assistant'),
                    esc_html__('Sends your store address and that key to the Parla server. Shoppers always prove an order is theirs before seeing it.', 'askparla-ai-shopping-assistant'),
                    esc_html__('Adds the chat to your storefront.', 'askparla-ai-shopping-assistant')
                );
            }
            // The directory requires the outside service to be named, with its terms, before anyone connects.
            printf(
                '<p class="aa-muted">%s</p>',
                wp_kses(
                    sprintf(
                        /* translators: 1: link to the terms of service, 2: link to the privacy policy */
                        __('Parla is a paid service hosted at askparla.com. Your shoppers\' messages are processed there, not on your server. %1$s &middot; %2$s', 'askparla-ai-shopping-assistant'),
                        '<a href="' . esc_url(parla_site_url('terms')) . '" target="_blank" rel="noopener">' . esc_html__('Terms of service', 'askparla-ai-shopping-assistant') . '</a>',
                        '<a href="' . esc_url(parla_site_url('privacy')) . '" target="_blank" rel="noopener">' . esc_html__('Privacy policy', 'askparla-ai-shopping-assistant') . '</a>'
                    ),
                    ['a' => ['href' => [], 'target' => [], 'rel' => []]]
                )
            );
        });
    });

    if ($connected) {
        parla_ui_section(__('Disconnect', 'askparla-ai-shopping-assistant'), '', function () {
            parla_ui_row(__('Remove the chat', 'askparla-ai-shopping-assistant'), __('The chat disappears from your shop. Your settings here are kept.', 'askparla-ai-shopping-assistant'), function () {
                printf(
                    '<button type="submit" name="disconnect" value="1" class="aa-btn aa-btn-danger" data-aa-confirm="%s">%s</button>',
                    esc_attr__('Disconnect the store and hide the chat?', 'askparla-ai-shopping-assistant'),
                    esc_html__('Disconnect store', 'askparla-ai-shopping-assistant')
                );
            });
        });
    }
}

// ---------------------------------------------------------------- preview

/** Where the preview iframe loads its page from. Same origin as wp-admin, so admin.js can reach into it. */
function parla_preview_url(): string
{
    return wp_nonce_url(admin_url('admin-post.php?action=parla_preview'), 'parla_preview');
}

/**
 * The page inside the preview iframe: a product page sketched in grey blocks, with the real chat
 * widget on it. Its stylesheet and the widget are registered and printed through WordPress like any
 * other asset; this page just has no wp-admin chrome around them.
 */
function parla_render_preview(): void
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Sorry, you are not allowed to see this page.', 'askparla-ai-shopping-assistant'), 403);
    }
    check_admin_referer('parla_preview');
    $s = parla_settings();
    if (!parla_is_connected()) {
        wp_die(esc_html__('Connect the store first.', 'askparla-ai-shopping-assistant'));
    }
    $public = rtrim($s['public_api_url'] ?: parla_api_base($s), '/');
    $name = $s['store_name'] ?: get_bloginfo('name');

    wp_register_style('parla-preview', plugin_dir_url(dirname(__FILE__)) . 'assets/preview.css', [], PARLA_VERSION);
    wp_add_inline_style('parla-preview', sprintf(
        'parla-chat{--parla-brand:%s;--parla-brand-contrast:%s}',
        sanitize_hex_color((string) $s['brand_color']) ?: '#111827',
        sanitize_hex_color((string) $s['brand_contrast']) ?: '#ffffff'
    ));
    wp_register_script('parla-preview-widget', $public . '/widget.js', [], PARLA_VERSION, true);
    // The widget reads these from its own <script> tag. The name in the greeting is sent by the
    // settings script, so the switch shows at once.
    add_filter('script_loader_tag', function (string $tag, string $handle) use ($s, $public, $name): string {
        if ($handle !== 'parla-preview-widget') {
            return $tag;
        }
        $attrs = sprintf(
            ' data-store="%s" data-api="%s" data-store-name="%s" data-preview',
            esc_attr($s['store_id']),
            esc_attr($public),
            esc_attr($name)
        );
        return str_replace(' src=', $attrs . ' src=', $tag);
    }, 10, 2);

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e('Chat preview', 'askparla-ai-shopping-assistant'); ?></title>
<?php wp_print_styles('parla-preview'); ?>
</head>
<body>
<div class="bar"><span class="sk logo"></span><b id="shop"><?php echo esc_html($name); ?></b></div>
<div class="sk hero"></div><div class="sk l w62"></div><div class="sk l w34"></div>
<div class="sk l w80"></div><div class="sk l w70"></div><div class="sk btn" id="buy"></div>
<?php wp_print_scripts('parla-preview-widget'); ?>
</body>
</html>
<?php
    exit;
}
add_action('admin_post_parla_preview', 'parla_render_preview');

/** The first name the preview greets: the admin's own, else a sample, so the setting can always be seen. */
function parla_ui_sample_name(): string
{
    $id = get_current_user_id();
    foreach (['first_name', 'billing_first_name'] as $key) {
        $name = trim((string) get_user_meta($id, $key, true));
        if ($name !== '' && strpos($name, '@') === false) {
            return mb_substr(wp_strip_all_tags($name), 0, 40);
        }
    }
    return 'Sarah';
}

/**
 * The live preview: the real chat, on a phone, on a page dressed like a product page of this shop.
 *
 * It follows the settings form as it is typed (colours, name, greeting, light or dark, popular
 * products, calls), so the merchant sees each change at once instead of saving to find out. It is
 * in an iframe so its fixed-position bubble cannot float over wp-admin; the page script talks to
 * it through the widget's preview events. The iframe loads parla_render_preview(), below. Messages typed in it go to the real assistant.
 */
function parla_ui_preview(array $s): void
{
    if (!parla_is_connected()) {
        return;
    }
    ?>
    <aside class="aa-preview" id="aa-preview">
        <div class="aa-preview-head">
            <div>
                <strong><?php esc_html_e('Live preview', 'askparla-ai-shopping-assistant'); ?></strong>
                <span class="aa-muted"><?php esc_html_e('Updates as you type.', 'askparla-ai-shopping-assistant'); ?></span>
            </div>
            <div class="aa-seg aa-seg-sm" role="radiogroup" aria-label="<?php esc_attr_e('What the preview shows', 'askparla-ai-shopping-assistant'); ?>">
                <label><input type="radio" name="aa_preview_view" value="closed"><span><?php esc_html_e('Closed', 'askparla-ai-shopping-assistant'); ?></span></label>
                <label><input type="radio" name="aa_preview_view" value="open" checked><span><?php esc_html_e('Open', 'askparla-ai-shopping-assistant'); ?></span></label>
            </div>
        </div>
        <div class="aa-phone">
            <span class="aa-phone-notch" aria-hidden="true"></span>
            <iframe id="aa-preview-frame" title="<?php esc_attr_e('Chat preview', 'askparla-ai-shopping-assistant'); ?>" src="<?php echo esc_url(parla_preview_url()); ?>"></iframe>
        </div>
        <p class="aa-preview-foot aa-muted"><?php esc_html_e('This is your real assistant: what you ask here counts as a conversation.', 'askparla-ai-shopping-assistant'); ?></p>
    </aside>
    <?php
}

// ---------------------------------------------------------------- the page

function parla_render_admin(): void
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $s = parla_settings();
    // A posted form is what the nonce field marks; reading REQUEST_METHOD only invites a lint warning.
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nothing is read here; parla_handle_post() verifies the nonce before it touches the data.
    if (isset($_POST['parla_nonce'])) {
        $s = parla_handle_post($s);
    }
    $connected = parla_is_connected();
    // Premium voice is switched on and off from Parla's side: take the current answer from the
    // server (cached five minutes) rather than whatever was true at the last Save.
    if ($connected) {
        $live = parla_insights($s);
        if ($live['ok'] && isset($live['data']['premium_voice']['enabled'])) {
            $now = empty($live['data']['premium_voice']['enabled']) ? 0 : 1;
            if ($now !== (int) $s['premium_voice']) {
                $s['premium_voice'] = $now;
                parla_save($s);
            }
        }
    }
    // Three places at the top: the numbers, the lists behind them, and everything that can be changed.
    $tabs = [
        'overview' => __('Overview', 'askparla-ai-shopping-assistant'),
        'reports' => __('Reports', 'askparla-ai-shopping-assistant'),
        'settings' => __('Settings', 'askparla-ai-shopping-assistant'),
    ];
    /*
     * Settings, grouped by what the merchant is trying to do rather than by where the field is stored.
     * The four that change what the chat looks like come first, since those show the live preview.
     */
    $pages = [
        'look' => [__('Look', 'askparla-ai-shopping-assistant'), __('Colours, theme, language', 'askparla-ai-shopping-assistant')],
        'assistant' => [__('Assistant', 'askparla-ai-shopping-assistant'), __('Name, status, manner', 'askparla-ai-shopping-assistant')],
        'first' => [__('First screen', 'askparla-ai-shopping-assistant'), __('Greeting, openers, products', 'askparla-ai-shopping-assistant')],
        'voice' => [__('Voice', 'askparla-ai-shopping-assistant'), __('Calls and voices', 'askparla-ai-shopping-assistant')],
        'knowledge' => [__('Knowledge & team', 'askparla-ai-shopping-assistant'), __('Policies, orders, handoffs', 'askparla-ai-shopping-assistant')],
        'connection' => [__('Connection', 'askparla-ai-shopping-assistant'), __('Shop key', 'askparla-ai-shopping-assistant')],
    ];
    ?>
    <div class="wrap aa-admin">
        <h1 class="screen-reader-text"><?php esc_html_e('Parla', 'askparla-ai-shopping-assistant'); ?></h1>
        <?php settings_errors('parla'); ?>

        <div class="aa-head">
        <header class="aa-top">
            <span class="aa-mark" aria-hidden="true">
                <svg viewBox="22 7 70 92" width="17" height="22"><path d="M24 42A33 33 0 1 1 57 75H43v12.5a9.5 9.5 0 0 1-19 0ZM39.1 38.3a4.3 4.3 0 0 1 8.6 0v7.4a4.3 4.3 0 0 1-8.6 0ZM52.7 30.8a4.3 4.3 0 0 1 8.6 0v22.4a4.3 4.3 0 0 1-8.6 0ZM66.3 35.8a4.3 4.3 0 0 1 8.6 0v12.4a4.3 4.3 0 0 1-8.6 0Z" fill="currentColor" fill-rule="evenodd"/></svg>
            </span>
            <div class="aa-top-title">
                <span class="aa-title"><?php esc_html_e('Parla', 'askparla-ai-shopping-assistant'); ?></span>
                <?php /* translators: %s: the plugin's version number */ ?>
                <span class="aa-muted"><?php echo esc_html($s['store_name'] ?: get_bloginfo('name')); ?> · <?php echo esc_html(sprintf(__('version %s', 'askparla-ai-shopping-assistant'), PARLA_VERSION)); ?></span>
            </div>
            <span class="aa-grow"></span>
            <span class="aa-pill <?php echo $connected ? ($s['enabled'] ? 'is-good' : 'is-neutral') : 'is-warn'; ?>">
                <span class="aa-dot"></span>
                <?php echo $connected
                    ? ($s['enabled'] ? esc_html__('Live on your shop', 'askparla-ai-shopping-assistant') : esc_html__('Connected, hidden', 'askparla-ai-shopping-assistant'))
                    : esc_html__('Not connected', 'askparla-ai-shopping-assistant'); ?>
            </span>
            <a class="aa-btn aa-btn-ghost" href="<?php echo esc_url(home_url()); ?>" target="_blank" rel="noopener"><?php esc_html_e('View shop', 'askparla-ai-shopping-assistant'); ?></a>
        </header>

        <nav class="aa-tabs" role="tablist" aria-label="<?php esc_attr_e('Parla sections', 'askparla-ai-shopping-assistant'); ?>">
            <?php foreach ($tabs as $slug => $label) : ?>
                <button type="button" class="aa-tab" role="tab" id="aa-tab-btn-<?php echo esc_attr($slug); ?>"
                        data-tab="<?php echo esc_attr($slug); ?>" aria-controls="aa-tab-<?php echo esc_attr($slug); ?>" aria-selected="false">
                    <?php echo esc_html($label); ?>
                </button>
            <?php endforeach; ?>
        </nav>
        </div>

        <form method="post" class="aa-form" id="aa-form">
            <?php wp_nonce_field('parla_save', 'parla_nonce'); ?>
            <?php // Pressing Enter in a field submits with the FIRST submit button in the form. Make that plain Save, never Reconnect or Disconnect. ?>
            <button type="submit" class="aa-default-submit" tabindex="-1" aria-hidden="true"></button>
            <div class="aa-layout">
                <nav class="aa-subnav" id="aa-subnav" aria-label="<?php esc_attr_e('Settings pages', 'askparla-ai-shopping-assistant'); ?>" hidden>
                    <?php foreach ($pages as $slug => [$label, $hint]) : ?>
                        <button type="button" class="aa-subtab" id="aa-tab-btn-<?php echo esc_attr($slug); ?>" data-tab="<?php echo esc_attr($slug); ?>" aria-controls="aa-tab-<?php echo esc_attr($slug); ?>">
                            <?php echo esc_html($label); ?>
                            <small><?php echo esc_html($hint); ?></small>
                        </button>
                    <?php endforeach; ?>
                </nav>
                <div class="aa-main">
                    <div class="aa-panel" id="aa-tab-overview" role="tabpanel" aria-labelledby="aa-tab-btn-overview" hidden><?php parla_ui_overview($s); ?></div>
                    <div class="aa-panel" id="aa-tab-reports" role="tabpanel" aria-labelledby="aa-tab-btn-reports" hidden><?php parla_ui_tab_reports($s); ?></div>
                    <div class="aa-panel" id="aa-tab-look" role="tabpanel" aria-labelledby="aa-tab-btn-look" hidden><?php parla_ui_tab_look($s); ?></div>
                    <div class="aa-panel" id="aa-tab-assistant" role="tabpanel" aria-labelledby="aa-tab-btn-assistant" hidden><?php parla_ui_tab_agent($s); ?></div>
                    <div class="aa-panel" id="aa-tab-first" role="tabpanel" aria-labelledby="aa-tab-btn-first" hidden><?php parla_ui_tab_first($s); ?></div>
                    <div class="aa-panel" id="aa-tab-voice" role="tabpanel" aria-labelledby="aa-tab-btn-voice" hidden><?php parla_ui_tab_voice($s); ?></div>
                    <div class="aa-panel" id="aa-tab-knowledge" role="tabpanel" aria-labelledby="aa-tab-btn-knowledge" hidden><?php parla_ui_tab_knowledge($s); ?></div>
                    <div class="aa-panel" id="aa-tab-connection" role="tabpanel" aria-labelledby="aa-tab-btn-connection" hidden><?php parla_ui_tab_connection($s, $connected); ?></div>
                </div>
                <?php parla_ui_preview($s); ?>
            </div>

            <div class="aa-savebar" id="aa-savebar">
                <span class="aa-save-state" id="aa-save-state" data-dirty="<?php esc_attr_e('You have unsaved changes', 'askparla-ai-shopping-assistant'); ?>">
                    <?php if ($s['last_error']) : ?>
                        <span class="aa-error"><?php esc_html_e('Last error:', 'askparla-ai-shopping-assistant'); ?> <code><?php echo esc_html($s['last_error']); ?></code></span>
                    <?php else : ?>
                        <span class="aa-muted"><?php esc_html_e('Changes go live on your shop when you save.', 'askparla-ai-shopping-assistant'); ?></span>
                    <?php endif; ?>
                </span>
                <button type="submit" class="aa-btn aa-btn-primary"><?php esc_html_e('Save settings', 'askparla-ai-shopping-assistant'); ?></button>
            </div>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------------- behaviour

/**
 * The settings page's stylesheet and script, enqueued rather than printed.
 *
 * Only on this page: loading them everywhere in wp-admin would slow down every other screen and is
 * the thing WordPress.org asks plugins not to do. Everything the script needs from the server is
 * handed over as one PARLA object, so assets/admin.js stays a plain file with no PHP in it.
 */
function parla_admin_assets(string $hook): void
{
    if ($hook !== parla_admin_hook()) {
        return;
    }
    $s = parla_settings();
    $base = rtrim($s['public_api_url'] ?: parla_api_base($s), '/');
    $dir = plugin_dir_url(dirname(__FILE__));

    wp_enqueue_style('parla-admin', $dir . 'assets/admin.css', [], PARLA_VERSION);
    // The one value the stylesheet cannot know: the merchant's brand colour.
    wp_add_inline_style('parla-admin', sprintf(
        '.aa-admin{--aa-accent-set:%s;}',
        esc_attr(parla_ui_accent((string) $s['brand_color']))
    ));

    wp_enqueue_script('parla-admin', $dir . 'assets/admin.js', [], PARLA_VERSION, true);
    wp_localize_script('parla-admin', 'PARLA', [
        'firstTab' => parla_is_connected() ? 'overview' : 'connection',
        'storeName' => $s['store_name'] ?: get_bloginfo('name'),
        'sampleName' => parla_ui_sample_name(),
        'api' => $base,
        'lang' => substr(get_locale(), 0, 2),
        'voiceSample' => __('Hello! The merino crew comes in navy and grey, and both are in stock in medium.', 'askparla-ai-shopping-assistant'),
        // Voices named after sound effects and cartoon characters, which no shop wants answering the phone.
        'novelty' => ['albert', 'bad news', 'bahh', 'bells', 'boing', 'bubbles', 'cellos', 'good news', 'jester', 'organ', 'superstar', 'trinoids', 'whisper', 'wobble', 'zarvox', 'fred', 'junior', 'kathy', 'ralph', 'eddy', 'flo', 'grandma', 'grandpa', 'reed', 'rocko', 'sandy', 'shelley'],
        'languages' => [
            'fr' => __('French', 'askparla-ai-shopping-assistant'), 'en' => __('English', 'askparla-ai-shopping-assistant'), 'es' => __('Spanish', 'askparla-ai-shopping-assistant'),
            'de' => __('German', 'askparla-ai-shopping-assistant'), 'it' => __('Italian', 'askparla-ai-shopping-assistant'), 'nl' => __('Dutch', 'askparla-ai-shopping-assistant'),
            'pt' => __('Portuguese', 'askparla-ai-shopping-assistant'), 'ar' => __('Arabic', 'askparla-ai-shopping-assistant'),
        ],
        'i18n' => [
            'play' => __('Play sample', 'askparla-ai-shopping-assistant'),
            'more' => __('more', 'askparla-ai-shopping-assistant'),
            'less' => __('less', 'askparla-ai-shopping-assistant'),
        ],
    ]);
}
add_action('admin_enqueue_scripts', 'parla_admin_assets');

