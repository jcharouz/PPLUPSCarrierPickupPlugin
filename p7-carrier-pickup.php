<?php
/*
Plugin Name: P7 Carrier Pickup
Description: UPS + PPL pickup scheduler for Position7
Version: 3.12
Author: Jan Charouz
*/

if (!defined('ABSPATH')) {
    exit;
}

/*
|--------------------------------------------------------------------------
| CONFIG / SETTINGS
|--------------------------------------------------------------------------
|
| Credentials now live in Carrier Pickup > Settings (option 'p7cp_settings').
| For extra safety you may instead define any of these as constants in
| wp-config.php; a constant always overrides the stored value.
*/

define('P7CP_OPTION', 'p7cp_settings');
define('P7CP_VERSION', '3.12');

/**
 * Merged settings with defaults. wp-config.php constants override stored values.
 */
function p7cp_settings()
{
    $defaults = [
        'ups_environment'   => 'production',
        'ups_client_id'     => '',
        'ups_client_secret' => '',
        'ups_account'       => '',
        'packaging_kg'      => 0.3,
        'enable_logging'    => 0,
        'github_repo'       => '',
        'github_token'      => '',
        'default_location'  => 'prague',
        'ppl_environment'       => 'production',
        'ppl_cpl_client_id'     => '',
        'ppl_cpl_client_secret' => '',
        'ppl_cpl_scope'         => 'myapi2',
    ];

    $s = wp_parse_args((array) get_option(P7CP_OPTION, []), $defaults);

    if (defined('UPS_CLIENT_ID')) {
        $s['ups_client_id'] = UPS_CLIENT_ID;
    }
    if (defined('UPS_CLIENT_SECRET')) {
        $s['ups_client_secret'] = UPS_CLIENT_SECRET;
    }
    if (defined('UPS_ACCOUNT_NUMBER')) {
        $s['ups_account'] = UPS_ACCOUNT_NUMBER;
    }
    if (defined('UPS_ENVIRONMENT')) {
        $s['ups_environment'] = UPS_ENVIRONMENT;
    }

    return $s;
}

/**
 * Single setting accessor.
 */
function p7cp_get($key, $default = '')
{
    $s = p7cp_settings();

    return isset($s[$key]) && '' !== $s[$key] ? $s[$key] : $default;
}

/**
 * Write a line to the log when logging is enabled in Settings.
 * Uses the WooCommerce logger (WooCommerce > Status > Logs,
 * source "p7-carrier-pickup") and falls back to error_log().
 */
function p7cp_log($message, $context = [])
{
    if (!p7cp_get('enable_logging', 0)) {
        return;
    }

    $line = $message;

    if (!empty($context)) {
        $line .= ' ' . wp_json_encode($context);
    }

    if (function_exists('wc_get_logger')) {
        wc_get_logger()->info($line, ['source' => 'p7-carrier-pickup']);
    } else {
        error_log('[p7-carrier-pickup] ' . $line);
    }
}

/**
 * Print the admin stylesheet once per request (scoped to .p7cp-wrap).
 */
function p7cp_print_style()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    echo '<style>
    .p7cp-wrap{max-width:1040px}
    .p7cp-wrap .p7cp-sub{color:#646970;font-size:13px;margin:-4px 0 20px}
    .p7cp-wrap h2{font-size:15px;margin:26px 0 10px;padding-bottom:8px;border-bottom:1px solid #e0e0e4;color:#1d2327}
    .p7cp-wrap h3{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#787c82;margin:18px 0 2px}
    .p7cp-wrap .form-table{background:#fff;border:1px solid #e2e4e7;border-radius:10px;padding:4px 18px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin-top:10px}
    .p7cp-wrap .form-table th{padding-left:6px;font-weight:600}
    .p7cp-wrap table.widefat{border:1px solid #e2e4e7;border-radius:10px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
    .p7cp-wrap table.widefat thead th{background:#f6f7f7;font-weight:600}
    .p7cp-wrap table.widefat td,.p7cp-wrap table.widefat th{padding:11px 14px}
    .p7cp-wrap .p7cp-preview td:nth-child(2),.p7cp-wrap .p7cp-preview td:nth-child(4){font-weight:700;font-size:15px;color:#1d2327}
    .p7cp-wrap .notice{border-radius:8px}
    .p7cp-wrap hr{border:0;border-top:1px solid #ededf0;margin:28px 0}
    .p7cp-wrap .description{color:#787c82}
    .p7cp-wrap .button-primary.button-large{padding:4px 22px;height:auto}
    </style>';
}

/*
|--------------------------------------------------------------------------
| UPDATES — one-click updates from a (private) GitHub repo
|--------------------------------------------------------------------------
|
| Dormant until a repo URL is configured (Settings, or the P7CP_GITHUB_REPO
| constant in wp-config.php). For a private repo also supply a token
| (Settings, or the P7CP_GITHUB_TOKEN constant). To publish an update:
| bump the Version header, commit, and create a GitHub Release (tag).
| WordPress then offers the update and installs it in place — settings,
| which live in the options table, are preserved.
*/

function p7cp_github_repo()
{
    return defined('P7CP_GITHUB_REPO') ? P7CP_GITHUB_REPO : (string) p7cp_get('github_repo', '');
}

function p7cp_github_token()
{
    return defined('P7CP_GITHUB_TOKEN') ? P7CP_GITHUB_TOKEN : (string) p7cp_get('github_token', '');
}

$p7cp_repo = p7cp_github_repo();

if ($p7cp_repo && file_exists(__DIR__ . '/plugin-update-checker/plugin-update-checker.php')) {

    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

    if (class_exists('\YahnisElsts\PluginUpdateChecker\v5\PucFactory')) {

        $p7cp_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            $p7cp_repo,
            __FILE__,
            'p7-carrier-pickup'
        );

        $p7cp_token = p7cp_github_token();

        if ($p7cp_token && method_exists($p7cp_updater, 'setAuthentication')) {
            $p7cp_updater->setAuthentication($p7cp_token);
        }
    }
}

/**
 * UPS API base URL for the configured environment.
 */
function p7cp_ups_base()
{
    return 'test' === p7cp_get('ups_environment', 'production')
        ? 'https://wwwcie.ups.com'
        : 'https://onlinetools.ups.com';
}

/**
 * Pickup locations. Shared by the main page and the settings mapping UI.
 */
function p7cp_locations()
{
    // Neutral defaults only — real business details live in the DB (Settings),
    // so this file (and a public repo) contains none of your addresses/contacts.
    $defaults = [

        'prague' => [
            'label'   => 'Location 1',
            'company' => '',
            'contact' => '',
            'street'  => '',
            'city'    => '',
            'zip'     => '',
            'country' => 'CZ',
            'phone'   => '',
            'email'   => '',
        ],

        'jihlava' => [
            'label'   => 'Location 2',
            'company' => '',
            'contact' => '',
            'street'  => '',
            'city'    => '',
            'zip'     => '',
            'country' => 'CZ',
            'phone'   => '',
            'email'   => '',
        ],
    ];

    $saved = (array) get_option('p7cp_locations', []);

    $out = [];

    foreach ($defaults as $id => $loc) {
        $out[$id] = $loc;
        if (isset($saved[$id]) && is_array($saved[$id])) {
            foreach ($loc as $k => $v) {
                if (isset($saved[$id][$k]) && '' !== $saved[$id][$k]) {
                    $out[$id][$k] = $saved[$id][$k];
                }
            }
        }
    }

    return $out;
}

/**
 * Location that unattributed shipments are counted toward (single, fixed).
 */
function p7cp_default_location()
{
    $loc = p7cp_get('default_location', 'prague');
    $all = p7cp_locations();

    return isset($all[$loc]) ? $loc : 'prague';
}

/*
|--------------------------------------------------------------------------
| ADMIN MENU
|--------------------------------------------------------------------------
*/

add_action('admin_menu', function () {

    add_menu_page(
        'Carrier Pickup',
        'Carrier Pickup',
        'manage_options',
        'p7-carrier-pickup',
        'p7_carrier_pickup_page',
        'dashicons-location',
        56
    );
});

/*
|--------------------------------------------------------------------------
| SETTINGS PAGE
|--------------------------------------------------------------------------
*/

add_action('admin_menu', function () {

    add_submenu_page(
        'p7-carrier-pickup',
        'Carrier Pickup Settings',
        'Settings',
        'manage_options',
        'p7-carrier-pickup-settings',
        'p7cp_render_settings_page'
    );
});

add_action('admin_init', function () {

    register_setting('p7cp_settings_group', P7CP_OPTION, [
        'sanitize_callback' => 'p7cp_sanitize_settings',
    ]);

    register_setting('p7cp_maps_group', 'p7cp_user_locations', [
        'sanitize_callback' => 'p7cp_sanitize_user_locations',
    ]);
});

function p7cp_sanitize_settings($input)
{
    $out = p7cp_settings();

    $env = isset($input['ups_environment']) ? sanitize_key($input['ups_environment']) : 'production';

    $out['ups_environment']   = in_array($env, ['production', 'test'], true) ? $env : 'production';
    $out['ups_client_id']     = isset($input['ups_client_id']) ? sanitize_text_field($input['ups_client_id']) : '';
    $out['ups_client_secret'] = isset($input['ups_client_secret']) ? sanitize_text_field($input['ups_client_secret']) : '';
    $out['ups_account']       = isset($input['ups_account']) ? sanitize_text_field($input['ups_account']) : '';
    $out['packaging_kg']      = isset($input['packaging_kg']) ? max(0, (float) $input['packaging_kg']) : (isset($out['packaging_kg']) ? $out['packaging_kg'] : 0.3);
    $out['enable_logging']    = empty($input['enable_logging']) ? 0 : 1;
    $out['github_repo']       = isset($input['github_repo']) ? esc_url_raw(trim($input['github_repo'])) : '';
    $out['github_token']      = isset($input['github_token']) ? sanitize_text_field(trim($input['github_token'])) : '';

    $dl = isset($input['default_location']) ? sanitize_key($input['default_location']) : 'prague';
    $out['default_location'] = array_key_exists($dl, p7cp_locations()) ? $dl : 'prague';

    $ppl_env = isset($input['ppl_environment']) ? sanitize_key($input['ppl_environment']) : 'production';
    $out['ppl_environment']       = in_array($ppl_env, ['production', 'test'], true) ? $ppl_env : 'production';
    $out['ppl_cpl_client_id']     = isset($input['ppl_cpl_client_id']) ? sanitize_text_field(trim($input['ppl_cpl_client_id'])) : '';
    $out['ppl_cpl_client_secret'] = isset($input['ppl_cpl_client_secret']) ? sanitize_text_field(trim($input['ppl_cpl_client_secret'])) : '';
    $out['ppl_cpl_scope']         = isset($input['ppl_cpl_scope']) && '' !== trim($input['ppl_cpl_scope']) ? sanitize_text_field(trim($input['ppl_cpl_scope'])) : 'myapi2';

    return $out;
}

function p7cp_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to view this page.');
    }

    $s = p7cp_settings();

    if (isset($_POST['p7cp_save_locations']) && check_admin_referer('p7cp_locations_nonce')) {
        $in = (isset($_POST['loc']) && is_array($_POST['loc'])) ? $_POST['loc'] : [];
        $fields = ['label', 'company', 'contact', 'street', 'city', 'zip', 'country', 'phone', 'email'];
        $saved = [];
        foreach (['prague', 'jihlava'] as $loc_id) {
            foreach ($fields as $f) {
                $saved[$loc_id][$f] = isset($in[$loc_id][$f]) ? sanitize_text_field($in[$loc_id][$f]) : '';
            }
        }
        update_option('p7cp_locations', $saved);
        echo '<div class="notice notice-success"><p>Location details saved.</p></div>';
    }

    if (isset($_POST['p7cp_refresh_ppl_addresses']) && check_admin_referer('p7cp_ppl_addresses')) {
        $fetched = p7cp_ppl_fetch_addresses();
        if (is_wp_error($fetched)) {
            echo '<div class="notice notice-error"><p>' . esc_html($fetched->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Loaded ' . count($fetched) . ' PPL address(es).</p></div>';
        }
    }

    if (isset($_POST['p7cp_save_ppl_addresses']) && check_admin_referer('p7cp_ppl_addresses')) {
        $stored = (array) get_option('p7cp_ppl_addresses', []);
        $by_key = [];
        foreach ($stored as $a) {
            $by_key[p7cp_ppl_address_key($a)] = $a;
        }
        $sel = (isset($_POST['ppl_addr']) && is_array($_POST['ppl_addr'])) ? $_POST['ppl_addr'] : [];
        $map = [];
        foreach (p7cp_locations() as $loc_id => $loc) {
            $k = isset($sel[$loc_id]) ? sanitize_text_field($sel[$loc_id]) : '';
            if ($k && isset($by_key[$k])) {
                $map[$loc_id] = $by_key[$k];
            }
        }
        update_option('p7cp_ppl_location_address', $map);

        // Populate each location's address fields from its assigned PPL address,
        // so the Location details above fill in and UPS uses the same address.
        $locs = (array) get_option('p7cp_locations', []);
        foreach ($map as $loc_id => $addr) {
            if (!isset($locs[$loc_id]) || !is_array($locs[$loc_id])) {
                $locs[$loc_id] = [];
            }
            $locs[$loc_id]['company'] = $addr['name'];
            $locs[$loc_id]['street']  = $addr['street'];
            $locs[$loc_id]['city']    = $addr['city'];
            $locs[$loc_id]['zip']     = $addr['zipCode'];
            if (!empty($addr['country'])) {
                $locs[$loc_id]['country'] = $addr['country'];
            }
        }
        update_option('p7cp_locations', $locs);

        echo '<div class="notice notice-success"><p>PPL pickup addresses saved, and Location details filled in from them.</p></div>';
    }

    $locked = [
        'ups_client_id'     => defined('UPS_CLIENT_ID'),
        'ups_client_secret' => defined('UPS_CLIENT_SECRET'),
        'ups_account'       => defined('UPS_ACCOUNT_NUMBER'),
        'github_repo'       => defined('P7CP_GITHUB_REPO'),
        'github_token'      => defined('P7CP_GITHUB_TOKEN'),
        'ppl_cpl_client_id'     => defined('P7CP_PPL_CLIENT_ID'),
        'ppl_cpl_client_secret' => defined('P7CP_PPL_CLIENT_SECRET'),
    ];

    ?>

    <div class="wrap p7cp-wrap">

        <?php p7cp_print_style(); ?>

        <h1>Carrier Pickup Settings</h1>

        <p class="p7cp-sub">Carrier credentials, pickup locations, and who ships from where.</p>

        <p>UPS API credentials. Leave a field blank if you set it as a constant in
        wp-config.php instead.</p>

        <form method="post" action="options.php">

            <?php settings_fields('p7cp_settings_group'); ?>

            <table class="form-table" role="presentation">

                <tr>
                    <th scope="row">UPS environment</th>
                    <td>
                        <select name="<?php echo esc_attr(P7CP_OPTION); ?>[ups_environment]">
                            <option value="production" <?php selected($s['ups_environment'], 'production'); ?>>Production</option>
                            <option value="test" <?php selected($s['ups_environment'], 'test'); ?>>Test (CIE)</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_ups_client_id">UPS Client ID</label></th>
                    <td>
                        <input
                            type="password"
                            id="p7cp_ups_client_id"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[ups_client_id]"
                            value="<?php echo esc_attr($locked['ups_client_id'] ? '' : $s['ups_client_id']); ?>"
                            class="regular-text"
                            autocomplete="off"
                            <?php disabled($locked['ups_client_id']); ?>
                        >
                        <?php if ($locked['ups_client_id']) : ?>
                            <p class="description">Set via the UPS_CLIENT_ID constant in wp-config.php.</p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_ups_client_secret">UPS Client Secret</label></th>
                    <td>
                        <input
                            type="password"
                            id="p7cp_ups_client_secret"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[ups_client_secret]"
                            value="<?php echo esc_attr($locked['ups_client_secret'] ? '' : $s['ups_client_secret']); ?>"
                            class="regular-text"
                            autocomplete="off"
                            <?php disabled($locked['ups_client_secret']); ?>
                        >
                        <?php if ($locked['ups_client_secret']) : ?>
                            <p class="description">Set via the UPS_CLIENT_SECRET constant in wp-config.php.</p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_ups_account">UPS Account Number</label></th>
                    <td>
                        <input
                            type="text"
                            id="p7cp_ups_account"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[ups_account]"
                            value="<?php echo esc_attr($locked['ups_account'] ? '' : $s['ups_account']); ?>"
                            class="regular-text"
                            autocomplete="off"
                            <?php disabled($locked['ups_account']); ?>
                        >
                        <?php if ($locked['ups_account']) : ?>
                            <p class="description">Set via the UPS_ACCOUNT_NUMBER constant in wp-config.php.</p>
                        <?php endif; ?>
                    </td>
                </tr>

                <tr><th colspan="2"><h3 style="margin:1em 0 0">PPL API (CPL / myapi2)</h3></th></tr>

                <tr>
                    <th scope="row">PPL environment</th>
                    <td>
                        <select name="<?php echo esc_attr(P7CP_OPTION); ?>[ppl_environment]">
                            <option value="production" <?php selected($s['ppl_environment'], 'production'); ?>>Production</option>
                            <option value="test" <?php selected($s['ppl_environment'], 'test'); ?>>Test (dev)</option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_ppl_client_id">PPL Client ID</label></th>
                    <td>
                        <input
                            type="password"
                            id="p7cp_ppl_client_id"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[ppl_cpl_client_id]"
                            value="<?php echo esc_attr($locked['ppl_cpl_client_id'] ? '' : $s['ppl_cpl_client_id']); ?>"
                            class="regular-text"
                            autocomplete="off"
                            <?php disabled($locked['ppl_cpl_client_id']); ?>
                        >
                        <p class="description"><?php echo $locked['ppl_cpl_client_id'] ? 'Set via P7CP_PPL_CLIENT_ID in wp-config.php.' : 'Same CPL credentials as the Shipment Monitor.'; ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_ppl_client_secret">PPL Client Secret</label></th>
                    <td>
                        <input
                            type="password"
                            id="p7cp_ppl_client_secret"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[ppl_cpl_client_secret]"
                            value="<?php echo esc_attr($locked['ppl_cpl_client_secret'] ? '' : $s['ppl_cpl_client_secret']); ?>"
                            class="regular-text"
                            autocomplete="off"
                            <?php disabled($locked['ppl_cpl_client_secret']); ?>
                        >
                        <p class="description"><?php echo $locked['ppl_cpl_client_secret'] ? 'Set via P7CP_PPL_CLIENT_SECRET in wp-config.php.' : ''; ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_ppl_scope">PPL Scope</label></th>
                    <td>
                        <input
                            type="text"
                            id="p7cp_ppl_scope"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[ppl_cpl_scope]"
                            value="<?php echo esc_attr($s['ppl_cpl_scope']); ?>"
                            class="regular-text"
                        >
                        <p class="description">Usually "myapi2".</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_packaging_kg">Packaging weight per package (kg)</label></th>
                    <td>
                        <input
                            type="number"
                            id="p7cp_packaging_kg"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[packaging_kg]"
                            value="<?php echo esc_attr($s['packaging_kg']); ?>"
                            min="0"
                            step="0.05"
                            class="small-text"
                        >
                        <p class="description">Added to each package on top of the product weights. Default 0.3 kg.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_default_location">Default location for unattributed packages</label></th>
                    <td>
                        <select id="p7cp_default_location" name="<?php echo esc_attr(P7CP_OPTION); ?>[default_location]">
                            <?php foreach (p7cp_locations() as $dl_id => $dl_loc) : ?>
                                <option value="<?php echo esc_attr($dl_id); ?>" <?php selected($s['default_location'], $dl_id); ?>><?php echo esc_html($dl_loc['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">When we can't tell who created an order's invoice, its packages count toward this location only.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(P7CP_OPTION); ?>[enable_logging]" value="1" <?php checked(1, (int) $s['enable_logging']); ?>>
                            Enable logging
                        </label>
                        <p class="description">Logs pickup counts, weights and API responses to WooCommerce &gt; Status &gt; Logs (source: p7-carrier-pickup).</p>
                    </td>
                </tr>

                <tr><th colspan="2"><h3 style="margin:1em 0 0">Updates (private GitHub repo)</h3></th></tr>

                <tr>
                    <th scope="row"><label for="p7cp_github_repo">GitHub repository URL</label></th>
                    <td>
                        <input
                            type="text"
                            id="p7cp_github_repo"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[github_repo]"
                            value="<?php echo esc_attr($locked['github_repo'] ? '' : $s['github_repo']); ?>"
                            class="regular-text"
                            placeholder="https://github.com/owner/p7-carrier-pickup"
                            <?php disabled($locked['github_repo']); ?>
                        >
                        <p class="description"><?php echo $locked['github_repo'] ? 'Set via P7CP_GITHUB_REPO in wp-config.php.' : 'Enables one-click updates from this repo\'s releases.'; ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="p7cp_github_token">GitHub access token</label></th>
                    <td>
                        <input
                            type="password"
                            id="p7cp_github_token"
                            name="<?php echo esc_attr(P7CP_OPTION); ?>[github_token]"
                            value="<?php echo esc_attr($locked['github_token'] ? '' : $s['github_token']); ?>"
                            class="regular-text"
                            autocomplete="off"
                            <?php disabled($locked['github_token']); ?>
                        >
                        <p class="description"><?php echo $locked['github_token'] ? 'Set via P7CP_GITHUB_TOKEN in wp-config.php.' : 'Fine-grained token with read-only Contents access to that repo (required for private repos). Prefer the wp-config.php constant.'; ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Installed version</th>
                    <td><code><?php echo esc_html(P7CP_VERSION); ?></code></td>
                </tr>

            </table>

            <?php submit_button('Save Settings'); ?>

        </form>

        <hr>

        <h2>Location details</h2>

        <p class="description">Your two pickup locations. Kept out of the plugin code, so it holds none of your business details. UPS always uses the address here; for PPL you can instead assign a registered address below.</p>

        <form method="post">
            <?php wp_nonce_field('p7cp_locations_nonce'); ?>
            <?php $loc_all = p7cp_locations(); ?>
            <?php
            $lc_fields = [
                'label'   => 'Nickname',
                'company' => 'Company',
                'contact' => 'Contact name',
                'street'  => 'Street',
                'city'    => 'City',
                'zip'     => 'ZIP',
                'country' => 'Country',
                'phone'   => 'Phone',
                'email'   => 'Email',
            ];
            ?>
            <table class="form-table" role="presentation">
                <?php foreach ($loc_all as $lc_id => $lc) : ?>
                    <tr><th colspan="2"><h3 style="margin:1em 0 0"><?php echo esc_html($lc['label']); ?></h3></th></tr>
                    <?php foreach ($lc_fields as $fk => $flabel) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($flabel); ?></th>
                            <td>
                                <input
                                    type="text"
                                    name="loc[<?php echo esc_attr($lc_id); ?>][<?php echo esc_attr($fk); ?>]"
                                    value="<?php echo esc_attr($lc[$fk]); ?>"
                                    class="regular-text"
                                >
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </table>
            <?php submit_button('Save location details', 'primary', 'p7cp_save_locations'); ?>
        </form>

        <hr>

        <h2>Who ships from where</h2>

        <p>Assign each administrator to a pickup location. When someone creates an
        order's invoice, that order's packages are counted toward their location.</p>

        <form method="post" action="options.php">

            <?php settings_fields('p7cp_maps_group'); ?>

            <?php
            $user_locations = (array) get_option('p7cp_user_locations', []);
            $map_locations = p7cp_locations();
            $admins = get_users(['role' => 'administrator', 'orderby' => 'display_name']);
            ?>

            <table class="form-table" role="presentation">
                <?php foreach ($admins as $admin_user) : ?>
                    <tr>
                        <th scope="row">
                            <?php echo esc_html($admin_user->display_name); ?>
                            <br><span class="description"><?php echo esc_html($admin_user->user_email); ?></span>
                        </th>
                        <td>
                            <select name="p7cp_user_locations[<?php echo (int) $admin_user->ID; ?>]">
                                <?php
                                $default_loc = 'prague';
                                $current = isset($user_locations[$admin_user->ID]) ? $user_locations[$admin_user->ID] : $default_loc;
                                foreach ($map_locations as $loc_id => $loc) :
                                    ?>
                                    <option value="<?php echo esc_attr($loc_id); ?>" <?php selected($current, $loc_id); ?>>
                                        <?php echo esc_html($loc['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <?php submit_button('Save Location Mapping'); ?>

        </form>

        <hr>

        <h2>PPL pickup addresses</h2>

        <p class="description">PPL requires the collection address to exactly match a registered one. Fetch your registered addresses, then choose which each location uses.</p>

        <?php
        $ppl_addresses = (array) get_option('p7cp_ppl_addresses', []);
        $ppl_loc_map = (array) get_option('p7cp_ppl_location_address', []);
        ?>

        <form method="post" style="margin-bottom:1em">
            <?php wp_nonce_field('p7cp_ppl_addresses'); ?>
            <button type="submit" name="p7cp_refresh_ppl_addresses" class="button">Refresh addresses from PPL</button>
            <?php if ($ppl_addresses) : ?>
                <span class="description" style="margin-left:8px"><?php echo count($ppl_addresses); ?> registered address(es) loaded.</span>
            <?php endif; ?>
        </form>

        <?php if ($ppl_addresses) : ?>

            <form method="post">
                <?php wp_nonce_field('p7cp_ppl_addresses'); ?>
                <table class="form-table" role="presentation">
                    <?php foreach (p7cp_locations() as $pa_loc_id => $pa_loc) : ?>
                        <?php $pa_current = isset($ppl_loc_map[$pa_loc_id]) ? p7cp_ppl_address_key($ppl_loc_map[$pa_loc_id]) : ''; ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($pa_loc['label']); ?></th>
                            <td>
                                <select name="ppl_addr[<?php echo esc_attr($pa_loc_id); ?>]">
                                    <option value="">&mdash; built-in address &mdash;</option>
                                    <?php foreach ($ppl_addresses as $pa_addr) : ?>
                                        <?php $pa_key = p7cp_ppl_address_key($pa_addr); ?>
                                        <option value="<?php echo esc_attr($pa_key); ?>" <?php selected($pa_current, $pa_key); ?>>
                                            <?php echo esc_html(trim($pa_addr['name'] . ' — ' . $pa_addr['street'] . ', ' . $pa_addr['city']) . ($pa_addr['default'] ? ' (default)' : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button('Save PPL pickup addresses', 'primary', 'p7cp_save_ppl_addresses'); ?>
            </form>

        <?php else : ?>

            <p class="description">Enter your PPL API credentials above and save, then click &ldquo;Refresh addresses from PPL&rdquo;.</p>

        <?php endif; ?>

    </div>

    <?php
}

/*
|--------------------------------------------------------------------------
| PICKUP STORAGE
|--------------------------------------------------------------------------
*/

function p7_get_pickups()
{
    $pickups = get_option('p7_pickups', []);

    $today = date('Y-m-d');

    $pickups = array_filter($pickups, function ($pickup) use ($today) {

        if (!empty($pickup['cancelled'])) {
            return false;
        }

        return $pickup['date'] >= $today;
    });

    update_option('p7_pickups', array_values($pickups));

    return array_values($pickups);
}

function p7_add_pickup($pickup)
{
    $pickups = get_option('p7_pickups', []);

    $pickups[] = $pickup;

    update_option('p7_pickups', $pickups);
}

function p7_cancel_local_pickup($reference)
{
    $pickups = get_option('p7_pickups', []);

    foreach ($pickups as &$pickup) {

        if ($pickup['reference'] == $reference) {

            $pickup['cancelled'] = true;
        }
    }

    update_option('p7_pickups', $pickups);
}

/*
|--------------------------------------------------------------------------
| MAIN PAGE
|--------------------------------------------------------------------------
*/

function p7_carrier_pickup_page()
{
    $tomorrow = date('Y-m-d', strtotime('+1 day'));

    /*
    |--------------------------------------------------------------------------
    | LOCATIONS
    |--------------------------------------------------------------------------
    */

    $locations = p7cp_locations();

    /*
    |--------------------------------------------------------------------------
    | CANCEL ACTION
    |--------------------------------------------------------------------------
    */

    if (
        isset($_POST['p7_cancel_pickup'])
        &&
        !empty($_POST['reference'])
        &&
        !empty($_POST['carrier'])
    ) {

        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'p7cp_cancel_pickup')) {

            echo '<div class="notice notice-error"><p><strong>Security check failed — reload the page and try the cancel again.</strong></p></div>';

        } else {

            $reference = sanitize_text_field($_POST['reference']);

            $carrier = sanitize_text_field($_POST['carrier']);

            $success = false;

            if ($carrier === 'UPS') {
                $success = p7_cancel_ups_pickup($reference);
            }

            if ($carrier === 'PPL') {
                $success = p7_cancel_ppl_pickup($reference);
            }

            p7cp_log('Cancel pickup', [
                'carrier' => $carrier,
                'reference' => $reference,
                'success' => $success,
            ]);

            if ($success) {

                p7_cancel_local_pickup($reference);

                echo '<div class="notice notice-success"><p>';
                echo '<strong>' . esc_html($carrier) . ' pickup cancelled.</strong>';
                echo '</p></div>';

            } else {

                echo '<div class="notice notice-error"><p>';
                echo '<strong>Failed to cancel pickup.</strong> See WooCommerce &gt; Status &gt; Logs (source p7-carrier-pickup) for the UPS response.';
                echo '</p></div>';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RESET COUNTER (mark a location caught up as of now)
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['p7cp_reset']) && !empty($_POST['reset_location'])) {

        if (!empty($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'p7cp_reset_counter')) {

            $reset_loc = sanitize_text_field($_POST['reset_location']);

            if (array_key_exists($reset_loc, $locations)) {

                $reset_now = current_time('mysql');

                p7cp_set_last_pickup('UPS', $reset_loc, $reset_now);
                p7cp_set_last_pickup('PPL', $reset_loc, $reset_now);

                p7cp_log('Counter reset', ['location' => $reset_loc, 'at' => $reset_now]);

                echo '<div class="notice notice-success"><p><strong>Counter reset for ' . esc_html($locations[$reset_loc]['label']) . '.</strong> Packages are now counted from ' . esc_html($reset_now) . '.</p></div>';
            }
        } else {

            echo '<div class="notice notice-error"><p><strong>Security check failed — reload and try the reset again.</strong></p></div>';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE PICKUP
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['p7_submit']) && check_admin_referer('p7cp_create_pickup')) {

        $pickup_date = sanitize_text_field($_POST['pickup_date']);

        $location_id = sanitize_text_field($_POST['pickup_location']);

        $use_manual = isset($_POST['manual_override']);

        $ups_enabled = isset($_POST['carrier_ups']);

        $ppl_enabled = isset($_POST['carrier_ppl']);

        $location = $locations[$location_id];

        /*
        |--------------------------------------------------------------------------
        | COUNT WINDOW + PER-CARRIER SUMMARY
        |--------------------------------------------------------------------------
        */

        $since_override = isset($_POST['count_since']) ? sanitize_text_field($_POST['count_since']) : '';

        if ($since_override) {
            // <input type="datetime-local"> yields 'Y-m-dTH:i'.
            $since_override = str_replace('T', ' ', $since_override);
            if (strlen($since_override) === 16) {
                $since_override .= ':00';
            }
        }

        $ups_since = p7cp_window_since('UPS', $location_id, $since_override);
        $ppl_since = p7cp_window_since('PPL', $location_id, $since_override);

        $ups_sum = p7cp_summary('UPS', $location_id, $ups_since);
        $ppl_sum = p7cp_summary('PPL', $location_id, $ppl_since);

        // Manual override replaces the computed values for every selected carrier.
        if ($use_manual) {

            $manual_count = max(1, intval($_POST['manual_packages']));
            $manual_weight = max(0.1, (float) $_POST['manual_weight']);

            $ups_sum = ['count' => $manual_count, 'weight' => $manual_weight, 'orders' => 0];
            $ppl_sum = ['count' => $manual_count, 'weight' => $manual_weight, 'orders' => 0];
        }

        $now = current_time('mysql');

        p7cp_log('Pickup requested', [
            'location' => $location_id,
            'manual' => $use_manual,
            'ups_since' => $ups_since,
            'ppl_since' => $ppl_since,
            'ups' => $ups_sum,
            'ppl' => $ppl_sum,
        ]);

        /*
        |--------------------------------------------------------------------------
        | UPS
        |--------------------------------------------------------------------------
        */

        if ($ups_enabled && p7cp_guard_count('UPS', $ups_sum, $location, $ups_since)) {

            $ups_result = p7_create_ups_pickup(
                $pickup_date,
                $ups_sum['count'],
                max(0.1, $ups_sum['weight']),
                $location
            );

            if ($ups_result['success']) {

                p7cp_set_last_pickup('UPS', $location_id, $now);

                p7_add_pickup([

                    'carrier' => 'UPS',

                    'reference' => $ups_result['prn'],

                    'date' => $pickup_date,

                    'location' => $location['label'],

                    'packages' => $ups_sum['count'],

                    'weight' => $ups_sum['weight'],

                    'cancelled' => false,
                ]);

                echo '<div class="notice notice-success"><p>';

                echo '<strong>UPS pickup scheduled.</strong><br><br>';

                echo 'Packages: ' . esc_html($ups_sum['count']) . ' &middot; Weight: ' . esc_html($ups_sum['weight']) . ' kg<br>';

                echo 'PRN: ' . esc_html($ups_result['prn']);

                echo '</p></div>';

            } else {

                echo '<div class="notice notice-error"><p>';

                echo esc_html($ups_result['message']);

                echo '</p></div>';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | PPL
        |--------------------------------------------------------------------------
        */

        if ($ppl_enabled && p7cp_guard_count('PPL', $ppl_sum, $location, $ppl_since)) {

            // Use the exact registered PPL address chosen for this location, if any.
            $ppl_location = $location;
            $ppl_addr = p7cp_ppl_location_address($location_id);

            if ($ppl_addr) {
                $ppl_location['company'] = !empty($ppl_addr['name']) ? $ppl_addr['name'] : $ppl_location['company'];
                $ppl_location['street']  = $ppl_addr['street'];
                $ppl_location['city']    = $ppl_addr['city'];
                $ppl_location['zip']     = $ppl_addr['zipCode'];
                $ppl_location['country'] = !empty($ppl_addr['country']) ? $ppl_addr['country'] : $ppl_location['country'];
            }

            $ppl_result = p7_create_ppl_pickup(
                $pickup_date,
                $ppl_sum['count'],
                $ppl_location
            );

            if ($ppl_result['success']) {

                p7cp_set_last_pickup('PPL', $location_id, $now);

                p7_add_pickup([

                    'carrier' => 'PPL',

                    'reference' => $ppl_result['collection_id'],

                    'date' => $pickup_date,

                    'location' => $location['label'],

                    'packages' => $ppl_sum['count'],

                    'weight' => $ppl_sum['weight'],

                    'cancelled' => false,
                ]);

                echo '<div class="notice notice-success"><p>';

                echo '<strong>PPL pickup scheduled.</strong><br><br>';

                echo 'Packages: ' . esc_html($ppl_sum['count']) . '<br>';

                echo 'Collection ID: ' . esc_html($ppl_result['collection_id']);

                echo '</p></div>';

            } else {

                echo '<div class="notice notice-error"><p>';

                echo esc_html($ppl_result['message']);

                echo '</p></div>';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACTIVE PICKUPS
    |--------------------------------------------------------------------------
    */

    $pickups = p7_get_pickups();

    ?>

    <div class="wrap p7cp-wrap">

        <?php p7cp_print_style(); ?>

        <h1>Carrier Pickup</h1>

        <p class="p7cp-sub">Order UPS &amp; PPL pickups and see what&rsquo;s waiting since the last one.</p>

        <h2>Active Pickups</h2>

        <?php if (empty($pickups)) : ?>

            <p>No active pickups.</p>

        <?php else : ?>

            <table class="widefat striped">

                <thead>

                    <tr>

                        <th>Carrier</th>

                        <th>Date</th>

                        <th>Location</th>

                        <th>Packages</th>

                        <th>Weight</th>

                        <th>Reference</th>

                        <th>Action</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($pickups as $pickup) : ?>

                        <tr>

                            <td>
                                <?php echo esc_html($pickup['carrier']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($pickup['date']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($pickup['location']); ?>
                            </td>

                            <td>
                                <?php echo esc_html($pickup['packages']); ?>
                            </td>

                            <td>
                                <?php echo isset($pickup['weight']) ? esc_html($pickup['weight']) . ' kg' : '—'; ?>
                            </td>

                            <td>
                                <?php echo esc_html($pickup['reference']); ?>
                            </td>

                            <td>

                                <form method="post">

                                    <?php wp_nonce_field('p7cp_cancel_pickup'); ?>

                                    <input
                                        type="hidden"
                                        name="reference"
                                        value="<?php echo esc_attr($pickup['reference']); ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="carrier"
                                        value="<?php echo esc_attr($pickup['carrier']); ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="p7_cancel_pickup"
                                        class="button button-secondary"
                                    >
                                        Cancel
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        <?php endif; ?>

        <hr>

        <h2>Pending since last pickup</h2>

        <p class="description">What each location has waiting right now — this is what a pickup would schedule.</p>

        <table class="widefat striped p7cp-preview" style="max-width:900px">

            <thead>
                <tr>
                    <th>Location</th>
                    <th>UPS packages</th>
                    <th>UPS weight</th>
                    <th>PPL packages</th>
                    <th>PPL weight</th>
                    <th>Counting since</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach (p7cp_locations() as $pv_loc_id => $pv_loc) : ?>
                    <?php
                    $pv_u_since = p7cp_window_since('UPS', $pv_loc_id);
                    $pv_p_since = p7cp_window_since('PPL', $pv_loc_id);
                    $pv_u = p7cp_summary('UPS', $pv_loc_id, $pv_u_since);
                    $pv_p = p7cp_summary('PPL', $pv_loc_id, $pv_p_since);
                    ?>
                    <tr>
                        <td><?php echo esc_html($pv_loc['label']); ?></td>
                        <td><?php echo esc_html($pv_u['count']); ?></td>
                        <td><?php echo esc_html($pv_u['weight']); ?> kg</td>
                        <td><?php echo esc_html($pv_p['count']); ?></td>
                        <td><?php echo esc_html($pv_p['weight']); ?> kg</td>
                        <td>
                            <span class="description">
                                UPS: <?php echo esc_html($pv_u_since); ?><br>
                                PPL: <?php echo esc_html($pv_p_since); ?>
                            </span>
                        </td>

                        <td>
                            <form method="post" onsubmit="return confirm('Reset the counter for this location? Packages created before now will no longer be counted.');">
                                <?php wp_nonce_field('p7cp_reset_counter'); ?>
                                <input type="hidden" name="reset_location" value="<?php echo esc_attr($pv_loc_id); ?>">
                                <button type="submit" name="p7cp_reset" class="button button-small">Reset to now</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>

        </table>

        <hr>

        <h2>Create Pickup</h2>

        <form method="post">

            <?php wp_nonce_field('p7cp_create_pickup'); ?>

            <table class="form-table">

                <tr>

                    <th>Date</th>

                    <td>

                        <input
                            type="date"
                            name="pickup_date"
                            value="<?php echo esc_attr($tomorrow); ?>"
                            required
                        >

                    </td>

                </tr>

                <tr>

                    <th>Pickup Location</th>

                    <td>

                        <select name="pickup_location">

                            <?php foreach ($locations as $loc_id => $loc) : ?>
                                <option value="<?php echo esc_attr($loc_id); ?>">
                                    <?php echo esc_html($loc['label']); ?>
                                </option>
                            <?php endforeach; ?>

                        </select>

                    </td>

                </tr>

                <tr>

                    <th>Carriers</th>

                    <td>

                        <label>
                            <input type="checkbox" name="carrier_ups" checked>
                            UPS
                        </label>

                        <br><br>

                        <label>
                            <input type="checkbox" name="carrier_ppl">
                            PPL
                        </label>

                    </td>

                </tr>

                <tr>

                    <th>Count packages since</th>

                    <td>

                        <input
                            type="datetime-local"
                            name="count_since"
                            value=""
                        >

                        <p class="description">
                            Leave blank to count everything created since the last pickup for this location.
                        </p>

                    </td>

                </tr>

                <tr>

                    <th>Manual Override</th>

                    <td>

                        <label>
                            <input type="checkbox" name="manual_override">
                            Use manual package count
                        </label>

                    </td>

                </tr>

                <tr>

                    <th>Packages</th>

                    <td>

                        <input
                            type="number"
                            name="manual_packages"
                            value="1"
                            min="1"
                        >

                    </td>

                </tr>

                <tr>

                    <th>Weight (kg)</th>

                    <td>

                        <input
                            type="number"
                            name="manual_weight"
                            value="1"
                            min="0.1"
                            step="0.1"
                        >

                    </td>

                </tr>

            </table>

            <p>

                <button
                    type="submit"
                    name="p7_submit"
                    class="button button-primary button-large"
                >
                    Schedule Pickup
                </button>

            </p>

        </form>

    </div>

    <?php
}

/*
|--------------------------------------------------------------------------
| TRACKING NUMBERS
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| WEIGHT
|--------------------------------------------------------------------------
*/

/**
 * Packaging weight added per package, in kg (on top of product weights).
 */
function p7cp_packaging_kg()
{
    $kg = (float) p7cp_get('packaging_kg', 0.3);

    return (float) apply_filters('p7cp_packaging_kg', $kg > 0 ? $kg : 0.3);
}

/**
 * Convert a weight in the store's unit to kilograms.
 */
function p7cp_to_kg($weight, $unit)
{
    switch ($unit) {
        case 'g':
            return $weight / 1000;
        case 'lbs':
            return $weight * 0.45359237;
        case 'oz':
            return $weight * 0.0283495231;
        default: // kg
            return $weight;
    }
}

/**
 * Total product weight of a WooCommerce order in kg (packaging excluded).
 */
function p7cp_order_product_weight_kg($order_id)
{
    if (!function_exists('wc_get_order')) {
        return 0.0;
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        return 0.0;
    }

    $unit = get_option('woocommerce_weight_unit', 'kg');

    $sum = 0.0;

    foreach ($order->get_items() as $item) {

        $product = $item->get_product();

        if (!$product) {
            continue;
        }

        $w = (float) $product->get_weight();

        if ($w > 0) {
            $sum += $w * (int) $item->get_quantity();
        }
    }

    return p7cp_to_kg($sum, $unit);
}

/*
|--------------------------------------------------------------------------
| SHIPMENT DETECTION (per carrier, since a datetime)
|--------------------------------------------------------------------------
|
| $since is a site-local 'Y-m-d H:i:s' string, or '' for no lower bound.
| Each shipment: ['tracking' => ..., 'order_id' => ..., 'created' => ...].
*/

function p7cp_detect_ups($since = '')
{
    global $wpdb;

    $sql = "
        SELECT comment_post_ID AS order_id, comment_content, comment_date
        FROM {$wpdb->comments}
        WHERE comment_type = 'order_note'
          AND comment_content LIKE '%1Z%'
    ";

    if ($since) {
        $sql .= $wpdb->prepare(' AND comment_date > %s', $since);
    }

    $rows = $wpdb->get_results($sql);

    $ships = [];
    $seen = [];

    foreach ((array) $rows as $row) {

        if (!preg_match_all('/\b(1Z[0-9A-Z]{16})\b/', (string) $row->comment_content, $matches)) {
            continue;
        }

        foreach ($matches[1] as $raw) {

            $tracking = strtoupper($raw);

            if (isset($seen[$tracking])) {
                continue;
            }

            $seen[$tracking] = true;

            $ships[] = [
                'tracking' => $tracking,
                'order_id' => (int) $row->order_id,
                'created' => $row->comment_date,
            ];
        }
    }

    return $ships;
}

function p7cp_detect_ppl($since = '')
{
    global $wpdb;

    $table = apply_filters('p7cp_ppl_table', $wpdb->prefix . 'pplcz_package');

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return [];
    }

    $cols = apply_filters('p7cp_ppl_columns', [
        'order' => 'wc_order_id',
        'number' => 'shipment_number',
        'created' => 'draft',
    ]);

    $order_col = sanitize_key($cols['order']);
    $num_col = sanitize_key($cols['number']);
    $created_col = sanitize_key($cols['created']);

    $sql = "SELECT {$order_col} AS order_id, {$num_col} AS tracking, {$created_col} AS created
            FROM {$table}
            WHERE {$num_col} <> ''";

    if ($since) {
        $sql .= $wpdb->prepare(" AND {$created_col} > %s", $since);
    }

    $rows = $wpdb->get_results($sql);

    $ships = [];
    $seen = [];

    foreach ((array) $rows as $row) {

        $tracking = trim((string) $row->tracking);

        if ('' === $tracking || isset($seen[$tracking])) {
            continue;
        }

        $seen[$tracking] = true;

        $ships[] = [
            'tracking' => $tracking,
            'order_id' => (int) $row->order_id,
            'created' => $row->created,
        ];
    }

    return $ships;
}

function p7cp_detect($carrier, $since = '')
{
    return 'PPL' === $carrier ? p7cp_detect_ppl($since) : p7cp_detect_ups($since);
}

/*
|--------------------------------------------------------------------------
| ATTRIBUTION (who created the order's invoice -> which location)
|--------------------------------------------------------------------------
*/

/**
 * Record that $user_id handled (created the invoice for) $order_id.
 * Prunes entries older than 180 days to keep the option small.
 */
function p7cp_record_invoice_author($order_id, $user_id)
{
    $authors = (array) get_option('p7cp_invoice_authors', []);

    $authors[(int) $order_id] = [
        'u' => (int) $user_id,
        't' => time(),
    ];

    $cutoff = time() - (180 * DAY_IN_SECONDS);

    foreach ($authors as $oid => $rec) {
        if (is_array($rec) && isset($rec['t']) && $rec['t'] < $cutoff) {
            unset($authors[$oid]);
        }
    }

    update_option('p7cp_invoice_authors', $authors, false);

    p7cp_log('Invoice author recorded', [
        'order_id' => (int) $order_id,
        'user_id' => (int) $user_id,
    ]);
}

/**
 * Capture the acting admin when they trigger a Fakturoid invoice-creation
 * action from a WooCommerce order screen. Runs in the admin's own session,
 * so get_current_user_id() is the person who is handling the shipment.
 */
add_action('admin_init', function () {

    $creating = apply_filters('p7cp_invoice_actions', [
        'send_invoice',
        'send_proforma',
        'send_final_invoice',
        'tax_document',
    ]);

    // Single-order action (?fakturoid_action=send_invoice&fakturoid_id_objednavky=123).
    if (!empty($_GET['fakturoid_action']) && !empty($_GET['fakturoid_id_objednavky'])) {

        $action = sanitize_key(wp_unslash($_GET['fakturoid_action']));

        if (in_array($action, $creating, true)) {
            p7cp_record_invoice_author((int) $_GET['fakturoid_id_objednavky'], get_current_user_id());
        }
    }

    // Bulk action (?fakturoidbulksend=12;34;56).
    if (!empty($_GET['fakturoidbulksend'])) {

        $ids = explode(';', sanitize_text_field(wp_unslash($_GET['fakturoidbulksend'])));

        foreach ($ids as $oid) {
            if ((int) $oid) {
                p7cp_record_invoice_author((int) $oid, get_current_user_id());
            }
        }
    }
});

/**
 * The pickup location a shipment belongs to, based on its invoice author.
 * Falls back to $default_location when the creator is unknown/unmapped.
 */
function p7cp_shipment_location($order_id, $default_location)
{
    $authors = (array) get_option('p7cp_invoice_authors', []);

    $rec = isset($authors[$order_id]) ? $authors[$order_id] : null;
    $user_id = is_array($rec) ? (int) $rec['u'] : (int) $rec;

    $map = (array) get_option('p7cp_user_locations', []);

    if ($user_id && !empty($map[$user_id])) {
        return $map[$user_id];
    }

    return p7cp_default_location();
}

/**
 * Sanitize the user -> location mapping saved from the settings page.
 */
function p7cp_sanitize_user_locations($input)
{
    $valid = array_keys(p7cp_locations());
    $out = [];

    if (is_array($input)) {
        foreach ($input as $user_id => $loc) {
            $user_id = (int) $user_id;
            $loc = sanitize_key($loc);
            if ($user_id && in_array($loc, $valid, true)) {
                $out[$user_id] = $loc;
            }
        }
    }

    return $out;
}

/*
|--------------------------------------------------------------------------
| SUMMARY (packages + weight for a carrier at a location since a datetime)
|--------------------------------------------------------------------------
*/

function p7cp_summary($carrier, $location_id, $since = '')
{
    $ships = p7cp_detect($carrier, $since);

    $count = 0;
    $orders = [];

    foreach ($ships as $ship) {

        if (p7cp_shipment_location($ship['order_id'], $location_id) !== $location_id) {
            continue;
        }

        $count++;
        $orders[$ship['order_id']] = true;
    }

    // Each order's product weight counted once, plus packaging per package.
    $weight = 0.0;

    foreach (array_keys($orders) as $order_id) {
        $weight += p7cp_order_product_weight_kg($order_id);
    }

    $weight += p7cp_packaging_kg() * $count;

    return [
        'count' => $count,
        'weight' => round($weight, 2),
        'orders' => count($orders),
    ];
}

/*
|--------------------------------------------------------------------------
| LAST PICKUP TIME (per carrier + location)
|--------------------------------------------------------------------------
*/

function p7cp_last_pickup($carrier, $location_id)
{
    $map = (array) get_option('p7cp_last_pickup', []);

    $key = $carrier . '|' . $location_id;

    return isset($map[$key]) ? $map[$key] : '';
}

function p7cp_set_last_pickup($carrier, $location_id, $datetime)
{
    $map = (array) get_option('p7cp_last_pickup', []);

    $map[$carrier . '|' . $location_id] = $datetime;

    update_option('p7cp_last_pickup', $map);
}

/**
 * The datetime a carrier+location should count from: the manual override if
 * given, else the last pickup, else 3 days ago (first run).
 */
function p7cp_window_since($carrier, $location_id, $override = '')
{
    if ($override) {
        return $override;
    }

    $since = p7cp_last_pickup($carrier, $location_id);

    if (!$since) {
        $since = date('Y-m-d H:i:s', strtotime('-3 days'));
    }

    return $since;
}

/**
 * Skip a carrier (with a notice) when nothing is due since $since.
 */
function p7cp_guard_count($carrier, $sum, $location, $since)
{
    if ($sum['count'] >= 1) {
        return true;
    }

    echo '<div class="notice notice-warning"><p>';
    echo 'No ' . esc_html($carrier) . ' packages for ' . esc_html($location['label']);
    echo ' since ' . esc_html($since) . '. ' . esc_html($carrier) . ' pickup skipped.';
    echo '</p></div>';

    return false;
}

/*
|--------------------------------------------------------------------------
| UPS CREATE
|--------------------------------------------------------------------------
*/

function p7_create_ups_pickup(
    $pickup_date,
    $package_count,
    $weight,
    $location
)
{
    $auth = wp_remote_post(
        p7cp_ups_base() . '/security/v1/oauth/token',
        [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(
                    p7cp_get('ups_client_id') . ':' . p7cp_get('ups_client_secret')
                ),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],

            'body' => [
                'grant_type' => 'client_credentials',
            ],

            'timeout' => 30,
        ]
    );

    if (is_wp_error($auth)) {

        return [
            'success' => false,
            'message' => $auth->get_error_message(),
        ];
    }

    $auth_body = json_decode(
        wp_remote_retrieve_body($auth),
        true
    );

    if (empty($auth_body['access_token'])) {

        return [
            'success' => false,
            'message' => 'UPS OAuth failed.',
        ];
    }

    $token = $auth_body['access_token'];

    $payload = [

        'PickupCreationRequest' => [

            'RatePickupIndicator' => 'N',

            'Shipper' => [

                'Account' => [

                    'AccountNumber' => p7cp_get('ups_account'),

                    'AccountCountryCode' => 'CZ',
                ]
            ],

            'PickupDateInfo' => [

                'CloseTime' => '1800',

                'ReadyTime' => '0900',

                'PickupDate' => date(
                    'Ymd',
                    strtotime($pickup_date)
                ),
            ],

            'PickupAddress' => [

                'CompanyName' => $location['company'],

                'ContactName' => $location['contact'],

                'AddressLine' => [
                    $location['street']
                ],

                'City' => $location['city'],

                'PostalCode' => $location['zip'],

                'CountryCode' => $location['country'],

                'ResidentialIndicator' => 'N',

                'Phone' => [
                    'Number' => $location['phone']
                ],

                'EMailAddress' => $location['email'],
            ],

            'AlternateAddressIndicator' => 'N',

            'PaymentMethod' => '01',

            'PickupPiece' => [[

                'ServiceCode' => '065',

                'Quantity' => (string)$package_count,

                'DestinationCountryCode' => 'CZ',

                'ContainerCode' => '01',

            ]],

            'TotalWeight' => [

                'Weight' => (string)$weight,

                'UnitOfMeasurement' => [
                    'Code' => 'KGS'
                ]
            ]
        ]
    ];

    $response = wp_remote_post(
        p7cp_ups_base() . '/api/pickupcreation/v2409/pickup',
        [
            'headers' => [

                'Authorization' => 'Bearer ' . $token,

                'Content-Type' => 'application/json',
            ],

            'body' => json_encode($payload),

            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {

        return [
            'success' => false,
            'message' => $response->get_error_message(),
        ];
    }

    $body = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    p7cp_log('UPS pickup response', [
        'status' => wp_remote_retrieve_response_code($response),
        'body' => wp_remote_retrieve_body($response),
    ]);

    if (
        isset(
            $body['PickupCreationResponse']['Response']['ResponseStatus']['Code']
        )
        &&
        $body['PickupCreationResponse']['Response']['ResponseStatus']['Code'] == '1'
    ) {

        return [
            'success' => true,
            'prn' => $body['PickupCreationResponse']['PRN'],
        ];
    }

    return [
        'success' => false,
        'message' => wp_remote_retrieve_body($response),
    ];
}

/*
|--------------------------------------------------------------------------
| UPS CANCEL
|--------------------------------------------------------------------------
*/

function p7_cancel_ups_pickup($prn)
{
    $auth = wp_remote_post(
        p7cp_ups_base() . '/security/v1/oauth/token',
        [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode(
                    p7cp_get('ups_client_id') . ':' . p7cp_get('ups_client_secret')
                ),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],

            'body' => [
                'grant_type' => 'client_credentials',
            ],

            'timeout' => 30,
        ]
    );

    if (is_wp_error($auth)) {
        return false;
    }

    $auth_body = json_decode(
        wp_remote_retrieve_body($auth),
        true
    );

    if (empty($auth_body['access_token'])) {
        p7cp_log('UPS cancel: OAuth failed', ['body' => wp_remote_retrieve_body($auth)]);
        return false;
    }

    $token = $auth_body['access_token'];

    // UPS Pickup API cancel: DELETE /shipments/{version}/pickup/{CancelBy}
    // CancelBy = 02 (cancel by PRN); the PRN itself goes in the Prn header.
    $response = wp_remote_request(
        p7cp_ups_base() . '/api/shipments/v2409/pickup/02',
        [
            'method' => 'DELETE',

            'headers' => [

                'Authorization' => 'Bearer ' . $token,

                'Content-Type' => 'application/json',

                'Prn' => $prn,

                'transactionSrc' => 'p7-carrier-pickup',
            ],

            'timeout' => 30,
        ]
    );

    if (is_wp_error($response)) {
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);

    p7cp_log('UPS cancel response', [
        'prn' => $prn,
        'status' => $status,
        'body' => wp_remote_retrieve_body($response),
    ]);

    return in_array($status, [200, 202, 204], true);
}

/*
|--------------------------------------------------------------------------
| PPL CREATE
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| PPL CPL API (myapi2) — direct calls, no dependency on the PPL plugin
|--------------------------------------------------------------------------
|
| Docs: https://ppl-cpl-api-en.apidog.io   Base host is DHL's gateway.
| Collections (svoz) are created via POST /order/batch with an explicit
| sender address, so we control exactly which registered location is used.
*/

function p7cp_ppl_base()
{
    $env = p7cp_get('ppl_environment', 'production');

    $host = ('test' === $env) ? 'https://api-dev.dhl.com' : 'https://api.dhl.com';

    return apply_filters('p7cp_ppl_base', $host . '/ecs/ppl/myapi2', $env);
}

function p7cp_ppl_credentials()
{
    $id     = defined('P7CP_PPL_CLIENT_ID') ? P7CP_PPL_CLIENT_ID : p7cp_get('ppl_cpl_client_id');
    $secret = defined('P7CP_PPL_CLIENT_SECRET') ? P7CP_PPL_CLIENT_SECRET : p7cp_get('ppl_cpl_client_secret');
    $scope  = p7cp_get('ppl_cpl_scope', 'myapi2');

    return [$id, $secret, $scope ? $scope : 'myapi2'];
}

/**
 * OAuth2 client-credentials token for the CPL API, cached in a transient.
 */
function p7cp_ppl_token()
{
    list($id, $secret, $scope) = p7cp_ppl_credentials();

    if (!$id || !$secret) {
        return new WP_Error('p7cp_ppl', 'PPL API credentials not configured (Settings > PPL API).');
    }

    $cached = get_transient('p7cp_ppl_token');
    if ($cached) {
        return $cached;
    }

    $resp = wp_remote_post(p7cp_ppl_base() . '/login/getAccessToken', [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body' => [
            'grant_type'    => 'client_credentials',
            'client_id'     => $id,
            'client_secret' => $secret,
            'scope'         => $scope,
        ],
        'timeout' => 30,
    ]);

    if (is_wp_error($resp)) {
        return $resp;
    }

    $body = json_decode(wp_remote_retrieve_body($resp), true);

    if (empty($body['access_token'])) {
        p7cp_log('PPL OAuth failed', [
            'status' => wp_remote_retrieve_response_code($resp),
            'body' => wp_remote_retrieve_body($resp),
        ]);
        return new WP_Error('p7cp_ppl', 'PPL OAuth failed.');
    }

    $ttl = !empty($body['expires_in']) ? max(60, (int) $body['expires_in'] - 120) : 1500;
    set_transient('p7cp_ppl_token', $body['access_token'], $ttl);

    return $body['access_token'];
}

/**
 * Pull a human-readable error out of a CPL batch / problem+json response.
 */
function p7cp_ppl_error_detail($decoded)
{
    if (!is_array($decoded)) {
        return '';
    }

    $items = $decoded;
    if (isset($decoded['orders'])) {
        $items = $decoded['orders'];
    } elseif (isset($decoded['items'])) {
        $items = $decoded['items'];
    }

    foreach ((array) $items as $item) {
        if (is_array($item)) {
            if (!empty($item['errorMessage'])) {
                return $item['errorMessage'];
            }
            if (!empty($item['errorCode'])) {
                return 'error code ' . $item['errorCode'];
            }
        }
    }

    if (!empty($decoded['detail'])) {
        return $decoded['detail'];
    }
    if (!empty($decoded['title'])) {
        return $decoded['title'];
    }

    return '';
}

function p7_create_ppl_pickup(
    $pickup_date,
    $package_count,
    $location
)
{
    $token = p7cp_ppl_token();

    if (is_wp_error($token)) {
        return ['success' => false, 'message' => $token->get_error_message()];
    }

    // A reference we own, so we can cancel later without polling for an order id.
    $reference = 'P7CP-' . date('YmdHis') . '-' . wp_rand(100, 999);

    $payload = [
        'orders' => [[
            'orderType'     => 'CollectionOrder',
            'referenceId'   => $reference,
            'shipmentCount' => (int) $package_count,
            'productType'   => 'BUSS',
            'note'          => 'Created by P7 Carrier Pickup',
            'email'         => $location['email'],
            'sendDate'      => date('c', strtotime($pickup_date)),
            'sender'        => [
                'name'    => $location['company'],
                'street'  => $location['street'],
                'city'    => $location['city'],
                'zipCode' => $location['zip'],
                'country' => $location['country'],
                'contact' => $location['contact'],
                'phone'   => $location['phone'],
                'email'   => $location['email'],
            ],
        ]],
    ];

    $resp = wp_remote_post(p7cp_ppl_base() . '/order/batch', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body' => wp_json_encode($payload),
        'timeout' => 30,
    ]);

    if (is_wp_error($resp)) {
        return ['success' => false, 'message' => $resp->get_error_message()];
    }

    $status       = wp_remote_retrieve_response_code($resp);
    $raw          = wp_remote_retrieve_body($resp);
    $location_hdr = wp_remote_retrieve_header($resp, 'location');

    p7cp_log('PPL order/batch response', [
        'status' => $status,
        'reference' => $reference,
        'sender_street' => $location['street'],
        'location' => $location_hdr,
        'body' => $raw,
    ]);

    if (!in_array($status, [200, 201, 202], true)) {
        $detail = p7cp_ppl_error_detail(json_decode($raw, true));
        return ['success' => false, 'message' => 'PPL HTTP ' . $status . ($detail ? ' — ' . $detail : '')];
    }

    // Creation is asynchronous: the batch id comes back in the Location header.
    // Poll it briefly so a rejected order (e.g. a bad address) reports as failed
    // instead of a false success.
    $batch_id = '';

    if ($location_hdr) {
        $parts = explode('/', rtrim((string) $location_hdr, '/'));
        $batch_id = end($parts);
    }

    if ($batch_id) {

        for ($i = 0; $i < 5; $i++) {

            $check = p7cp_ppl_check_batch($token, $batch_id);

            if ('error' === $check['state']) {
                return ['success' => false, 'message' => 'PPL: ' . ($check['error'] ? $check['error'] : 'order rejected')];
            }

            if ('complete' === $check['state']) {
                break;
            }

            sleep(1);
        }
    }

    return ['success' => true, 'collection_id' => $reference];
}

/**
 * Poll one CPL order batch. Returns ['state' => complete|error|pending, 'error' => msg].
 */
function p7cp_ppl_check_batch($token, $batch_id)
{
    $resp = wp_remote_get(p7cp_ppl_base() . '/order/batch/' . rawurlencode($batch_id), [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 20,
    ]);

    if (is_wp_error($resp)) {
        return ['state' => 'pending', 'error' => ''];
    }

    $raw  = wp_remote_retrieve_body($resp);
    $body = json_decode($raw, true);

    $items = isset($body['items']) ? $body['items'] : (is_array($body) ? $body : []);

    $state = 'pending';

    foreach ((array) $items as $item) {

        if (!is_array($item)) {
            continue;
        }

        $import = isset($item['importState']) ? strtolower((string) $item['importState']) : '';

        if (!empty($item['errorMessage']) || 'error' === $import) {
            $msg = !empty($item['errorMessage']) ? $item['errorMessage'] : ('code ' . (isset($item['errorCode']) ? $item['errorCode'] : ''));
            return ['state' => 'error', 'error' => $msg];
        }

        if ('complete' === $import) {
            $state = 'complete';
        }
    }

    p7cp_log('PPL batch status', ['batch_id' => $batch_id, 'state' => $state, 'body' => $raw]);

    return ['state' => $state, 'error' => ''];
}

/*
|--------------------------------------------------------------------------
| PPL registered pickup addresses (GET /customer/address)
|--------------------------------------------------------------------------
*/

/**
 * Fetch the account's registered addresses from PPL and cache them.
 */
function p7cp_ppl_fetch_addresses()
{
    $token = p7cp_ppl_token();

    if (is_wp_error($token)) {
        return $token;
    }

    $resp = wp_remote_get(p7cp_ppl_base() . '/customer/address', [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 30,
    ]);

    if (is_wp_error($resp)) {
        return $resp;
    }

    $code = wp_remote_retrieve_response_code($resp);
    $raw  = wp_remote_retrieve_body($resp);
    $body = json_decode($raw, true);

    p7cp_log('PPL customer/address', ['status' => $code, 'body' => $raw]);

    if (200 !== (int) $code || !is_array($body)) {
        return new WP_Error('p7cp_ppl', 'Could not fetch PPL addresses (HTTP ' . $code . ').');
    }

    $list = isset($body['items']) ? $body['items'] : $body;

    $out = [];

    foreach ((array) $list as $a) {
        if (!is_array($a)) {
            continue;
        }
        $out[] = [
            'code'    => isset($a['code']) ? (string) $a['code'] : '',
            'name'    => isset($a['name']) ? (string) $a['name'] : '',
            'name2'   => isset($a['name2']) ? (string) $a['name2'] : '',
            'street'  => isset($a['street']) ? (string) $a['street'] : '',
            'city'    => isset($a['city']) ? (string) $a['city'] : '',
            'zipCode' => isset($a['zipCode']) ? (string) $a['zipCode'] : '',
            'country' => isset($a['country']) ? (string) $a['country'] : '',
            'default' => !empty($a['default']),
        ];
    }

    // De-duplicate: the API returns the same address once per registered code.
    $deduped = [];
    foreach ($out as $addr) {
        $k = p7cp_ppl_address_key($addr);
        if (!isset($deduped[$k])) {
            $deduped[$k] = $addr;
        } elseif (!empty($addr['default'])) {
            $deduped[$k]['default'] = true;
        }
    }
    $out = array_values($deduped);

    update_option('p7cp_ppl_addresses', $out, false);

    return $out;
}

/**
 * Stable key for a registered address (used in the settings dropdown).
 */
function p7cp_ppl_address_key($a)
{
    $basis = strtolower(trim($a['street'] . '|' . $a['zipCode'] . '|' . $a['name']));

    return substr(md5($basis), 0, 12);
}

/**
 * The registered PPL address chosen for a location, or null (use built-in).
 */
function p7cp_ppl_location_address($location_id)
{
    $map = (array) get_option('p7cp_ppl_location_address', []);

    return (isset($map[$location_id]) && is_array($map[$location_id])) ? $map[$location_id] : null;
}

/*
|--------------------------------------------------------------------------
| PPL CANCEL
|--------------------------------------------------------------------------
*/

function p7_cancel_ppl_pickup($reference)
{
    $token = p7cp_ppl_token();

    if (is_wp_error($token)) {
        p7cp_log('PPL cancel: token error', ['message' => $token->get_error_message()]);
        return false;
    }

    $url = add_query_arg(['orderReference' => $reference], p7cp_ppl_base() . '/order/cancel');

    $resp = wp_remote_post($url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'body' => wp_json_encode(['note' => 'Cancelled via P7 Carrier Pickup']),
        'timeout' => 30,
    ]);

    if (is_wp_error($resp)) {
        return false;
    }

    $status = wp_remote_retrieve_response_code($resp);

    p7cp_log('PPL cancel response', [
        'reference' => $reference,
        'status' => $status,
        'body' => wp_remote_retrieve_body($resp),
    ]);

    return in_array($status, [200, 202, 204], true);
}