<?php
/**
 * Plugin Name: Plugin Compatibility Checker (Portal-integrated)
 * Description: Check which WordPress and PHP versions your plugins are compatible with before updating WordPress.adds Portal integration: license settings, request scan, fetch latest result, cron poller, admin email on new results.
 * Version: 7.0.4
 * Author: CompatShield
 * Author URI: https://www.compatshield.com/
 */


if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('PCC') ) :

class PCC {

    const MENU_SLUG = 'PCC_Check';
    const SUBMENU_SYSINFO_SLUG = 'websiteinfo';
    const SUBMENU_SETTINGS_SLUG = 'pcc_settings';
    const CAP_SINGLE = 'manage_options';
    const CAP_NETWORK = 'manage_network';
    const TRANSIENT_KEY = 'pcc_scan_results';
    const TRANSIENT_TTL_DEFAULT = 6 * HOUR_IN_SECONDS;
    const NONCE_ACTION = 'pcc_rescan';
    const AJAX_ACTION = 'pcc_rescan';

    const OPTION_LICENSE_KEY = 'pcc_license_key';
    const OPTION_LICENSE_VALID = 'pcc_license_valid';
    const OPTION_REMOTE_MAP = 'pcc_remote_php_compat';
    const CRON_HOOK = 'pcc_cron_fetch_remote';
    const PORTAL_API = 'https://www.compatshield.com/portal-api';
	const SIGNUP_URL  = 'https://www.compatshield.com/product';      // free plan signup
    const PRODUCT_URL = 'https://www.compatshield.com/product/';    // pricing / pro

    public function __construct() {
        if ( is_multisite() ) {
            add_action('network_admin_menu', [ $this, 'register_network_menus' ]);
        } else {
            add_action('admin_menu', [ $this, 'register_menus' ]);
        }

        add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
        add_action('wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_rescan' ]);
        add_action('wp_ajax_pcc_request_scan', [ $this, 'ajax_request_scan' ]);
        add_action('wp_ajax_pcc_fetch_remote', [ $this, 'ajax_fetch_remote' ]);
        add_action('wp_ajax_pcc_validate_license', [ $this, 'ajax_validate_license' ]);

        add_action('init', [ $this, 'maybe_schedule_cron' ]);
        add_action(self::CRON_HOOK, [ $this, 'cron_fetch_remote' ]);

        register_deactivation_hook(__FILE__, [ $this, 'on_deactivate' ]);
    }

    public function on_deactivate() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    /* Menus */
    public function register_menus() {
        add_menu_page(
            __('Plugin Compatibility Checker', 'pcc'),
            __('Plugin Compatibility Checker', 'pcc'),
            self::CAP_SINGLE,
            self::MENU_SLUG,
            [ $this, 'render_single_site' ],
            'dashicons-screenoptions',
            90
        );

        add_submenu_page(self::MENU_SLUG, __('System Info', 'pcc'), __('System Info', 'pcc'), self::CAP_SINGLE, self::SUBMENU_SYSINFO_SLUG, [ $this, 'render_sysinfo' ]);
        add_submenu_page(self::MENU_SLUG, __('PCC Settings', 'pcc'), __('Settings', 'pcc'), self::CAP_SINGLE, self::SUBMENU_SETTINGS_SLUG, [ $this, 'render_settings' ]);
    }

    public function register_network_menus() {
        add_menu_page(__('Plugin Compatibility Checker', 'pcc'), __('Plugin Compatibility Checker', 'pcc'), self::CAP_NETWORK, self::MENU_SLUG, [ $this, 'render_network' ], 'dashicons-screenoptions', 90);
        add_submenu_page(self::MENU_SLUG, __('System Info', 'pcc'), __('System Info', 'pcc'), self::CAP_NETWORK, self::SUBMENU_SYSINFO_SLUG, [ $this, 'render_sysinfo' ]);
        add_submenu_page(self::MENU_SLUG, __('PCC Settings', 'pcc'), __('Settings', 'pcc'), self::CAP_NETWORK, self::SUBMENU_SETTINGS_SLUG, [ $this, 'render_settings' ]);
    }

    /* Assets */
    public function enqueue_assets($hook) {
        $screen = get_current_screen();
        if ( empty($screen->id) ) return;
        if ( false === strpos($screen->id, self::MENU_SLUG) && false === strpos($screen->id, self::SUBMENU_SYSINFO_SLUG) && false === strpos($screen->id, self::SUBMENU_SETTINGS_SLUG) ) return;

        wp_enqueue_style('pcc-custom', plugin_dir_url(__FILE__) . 'customcss/pcccustom.css', [], '1.0.0');
        wp_enqueue_style('pcc-bootstrap', plugin_dir_url(__FILE__) . 'customcss/bootstrap.min.css', [], '1.0.1');

        wp_enqueue_script('pcc-filter', plugin_dir_url(__FILE__) . 'customjs/filtertable.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('pcc-export', plugin_dir_url(__FILE__) . 'customjs/export.js', ['jquery'], '1.0.0', true);

        wp_register_script('pcc-rescan', plugin_dir_url(__FILE__) . 'customjs/pcc-rescan.js', ['jquery'], '1.0.0', true);
        wp_localize_script('pcc-rescan', 'PCCVars', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'action'  => self::AJAX_ACTION,
        ]);
        wp_enqueue_script('pcc-rescan');

        $settings_rel = 'customjs/pcc-settings.js';
        $settings_path = plugin_dir_path(__FILE__) . $settings_rel;
        $settings_url = plugin_dir_url(__FILE__) . $settings_rel;

        $license = trim(get_option(self::OPTION_LICENSE_KEY, ''));
        $license_valid = (bool) get_option(self::OPTION_LICENSE_VALID, false);

        if ( file_exists($settings_path) ) {
            wp_register_script('pcc-settings-js', $settings_url, ['jquery'], '1.0.2', true);
            wp_localize_script('pcc-settings-js', 'PCCSettings', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'validateAction' => 'pcc_validate_license',
                'requestScanAction' => 'pcc_request_scan',
                'fetchRemoteAction' => 'pcc_fetch_remote',
                'nonce' => wp_create_nonce('pcc_settings_nonce'),
                'siteUrl' => get_site_url(),
                'licenseActive' => ($license !== '' && $license_valid),
            ]);
            wp_enqueue_script('pcc-settings-js');
        } else {
            wp_register_script('pcc-settings-fallback', false);
            wp_enqueue_script('pcc-settings-fallback');
            $localized = [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'validateAction' => 'pcc_validate_license',
                'requestScanAction' => 'pcc_request_scan',
                'fetchRemoteAction' => 'pcc_fetch_remote',
                'nonce' => wp_create_nonce('pcc_settings_nonce'),
                'siteUrl' => get_site_url(),
                'licenseActive' => ($license !== '' && $license_valid),
            ];
            wp_localize_script('pcc-settings-fallback', 'PCCSettings', $localized);
            $inline_handlers = <<<JS
(function($){
    $(function(){});
})(jQuery);
JS;
            wp_add_inline_script('pcc-settings-fallback', $inline_handlers);
        }
    }

    
   /* Server-side helper: validate license against portal validate endpoint */
/* Server-side helper: validate license against portal validate endpoint */
private function server_validate_license($license, $site_url = '') {
    if ( empty($license) ) return new WP_Error('missing_license', 'Missing license');

    // normalize license
    $license = trim($license);

    // normalize site_url to host-only (example.com). Fallback to get_site_url()
    $site_raw = trim($site_url ?: get_site_url());
    $host = parse_url($site_raw, PHP_URL_HOST);
    if ( ! $host ) {
        // maybe get_site_url returned a host-less value; try adding scheme then parse
        $tmp = @parse_url('https://' . ltrim($site_raw, '/'));
        $host = $tmp ? ($tmp['host'] ?? '') : '';
    }
    $site_to_send = $host ? trim(strtolower($host)) : trim($site_raw);

    $validate_url = rtrim(self::PORTAL_API, '/') . '/validate_license.php';

    $payload = [
        'site_url' => $site_to_send,
        'license'  => $license,
    ];

    $args = [
        'headers' => [
            'X-Portal-License' => $license,
            'Content-Type'     => 'application/json',
        ],
        'timeout' => 10,
        'body'    => wp_json_encode($payload),
    ];

    // Optional debug logging (only when WP_DEBUG enabled)
    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('[PCC] validate_license -> POST ' . $validate_url . ' payload=' . wp_json_encode($payload));
    }

    $res = wp_remote_post($validate_url, $args);
    if ( is_wp_error($res) ) return $res;

    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);
    $json = json_decode($body, true);

    if ( defined('WP_DEBUG') && WP_DEBUG ) {
        error_log('[PCC] validate_license response_code=' . $code . ' body=' . $body);
    }

    // Only treat as success if HTTP 2xx AND JSON contains valid === true
    if ( $code >= 200 && $code < 300 && is_array($json) && isset($json['valid']) && $json['valid'] === true ) {
        return $json;
    }

    // If portal explicitly returned valid=false return a WP_Error with portal details
    if ( is_array($json) && isset($json['valid']) && $json['valid'] === false ) {
        return new WP_Error('license_invalid', 'License not valid', ['portal' => $json, 'http_code' => $code, 'body' => $body]);
    }

    // else unknown / malformed response
    return new WP_Error('invalid_response', 'License validation failed', ['http_code' => $code, 'body' => $body, 'json' => $json]);
}

/**
 * Normalize a site URL to host-only (example.com or sub.example.com).
 * Returns host (lowercased) or the fallback input if parse fails.
 */
private function normalize_site_host($site_raw = '') {
    $site_raw = trim($site_raw ?: get_site_url());
    // Try WordPress helper first (if present)
    if ( function_exists('wp_parse_url') ) {
        $host = wp_parse_url($site_raw, PHP_URL_HOST);
    } else {
        $parts = @parse_url($site_raw);
        $host = $parts['host'] ?? null;
    }
    if ( ! $host ) {
        // try adding https:// and parse again
        $tmp = @parse_url('https://' . ltrim($site_raw, '/'));
        $host = $tmp ? ($tmp['host'] ?? null) : null;
    }
    return $host ? strtolower(trim($host)) : trim($site_raw);
}


    /* AJAX: Rescan (now requests a scan when portal license validated) */
    public function ajax_rescan() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if ( ! current_user_can(self::CAP_SINGLE) ) {
            return wp_send_json_error(['message'=>'forbidden'], 403);
        }

        // Always clear cached local scan results (we'll rebuild after actions)
        if ( is_multisite() ) delete_site_transient(self::TRANSIENT_KEY);
        else delete_transient(self::TRANSIENT_KEY);

        $license = trim(get_option(self::OPTION_LICENSE_KEY, ''));
        $license_valid = (bool) get_option(self::OPTION_LICENSE_VALID, false);

        // If no license configured -> clear remote mapping and perform local rebuild (fallback)
        if ( empty($license) ) {
            // clear remote data to prevent stale portal results showing
            delete_option(self::OPTION_REMOTE_MAP);
            $this->get_scan_results(true);
            return wp_send_json_success(['from_remote'=>false,'updated'=>0,'scan_pending'=>false,'message'=>'no_license_fallback']);
        }

        // If license present but not marked validated, attempt server-side validation now
        if ( ! $license_valid ) {
            $v = $this->server_validate_license($license, get_site_url());
            if ( is_wp_error($v) ) {
                // invalid or network issue -> mark invalid, clear remote map and fall back
                update_option(self::OPTION_LICENSE_VALID, false);
                delete_option(self::OPTION_REMOTE_MAP);
                $this->get_scan_results(true);
                return wp_send_json_error(['message'=>'license_invalid_or_network','detail'=>$v->get_error_message()], 401);
            } else {
                // valid -> persist and continue
                update_option(self::OPTION_LICENSE_KEY, $license);
                update_option(self::OPTION_LICENSE_VALID, true);
                $license_valid = true;
            }
        }

        // From here, license is present and validated: submit request to portal
        $endpoint = rtrim(self::PORTAL_API, '/') . '/receive_client.php';
       $site_host = $this->normalize_site_host(get_site_url());
$payload = [
    'site_url'   => $site_host,
    'wp_version' => get_bloginfo('version'),
    'php_version'=> phpversion(),
    'server_info'=> [
        'os' => PHP_OS,
        'sapi' => php_sapi_name(),
    ],
    'components' => $this->build_components_payload()
];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Portal-License' => $license,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ];

        $res = wp_remote_post($endpoint, $args);
        if ( is_wp_error($res) ) {
            // network failed — still return success but mark not-from-remote
            $this->get_scan_results(true); // rebuild local
            return wp_send_json_success(['from_remote'=>false,'updated'=>0,'scan_pending'=>false,'remote_error'=>$res->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        // Portal accepted request (200/2xx)
        if ( $code >= 200 && $code < 300 ) {
            // If portal included immediate results (status ok + scan_results), apply them now
            if ( is_array($json) && isset($json['status']) && $json['status'] === 'ok' && ! empty($json['scan_results']) ) {
                $remote_map = get_option(self::OPTION_REMOTE_MAP, []);
                if ( ! is_array($remote_map) ) $remote_map = [];
                $acc = [];

                foreach ( $json['scan_results'] as $sr ) {
                    $standards = $sr['standards'] ?? null;
                    if ( empty($standards) || stripos($standards,'PHPCompatibilityWP') === false ) continue;
                    if ( empty($sr['slug']) ) continue;

                    // normalise is_compatible
                    $is_compat = false;
                    if ( isset($sr['is_compatible']) ) {
                        $val = $sr['is_compatible'];
                        if ( is_bool($val) ) $is_compat = $val;
                        elseif ( is_numeric($val) ) $is_compat = ((int)$val === 1);
                        elseif ( is_string($val) ) $is_compat = in_array(strtolower($val), ['1','true','yes'], true);
                    }
                    if ( ! $is_compat ) continue;

                    $pv = $sr['php_version'] ?? '';
                    if ( ! $pv ) continue;
                    $parts = preg_split('/\s*,\s*/', trim($pv));
                    if ( ! isset($acc[$sr['slug']]) ) $acc[$sr['slug']] = [];
                    foreach ($parts as $p) { if ($p !== '') $acc[$sr['slug']][] = $p; }
                }

                $updated = 0;
                foreach ($acc as $slug => $list) {
                    $list = array_values(array_unique($list));
                    usort($list, 'version_compare');
                    $norm = implode(', ', $list);
                    if ( ! isset($remote_map[$slug]) || $remote_map[$slug] !== $norm ) {
                        $remote_map[$slug] = $norm;
                        $updated++;
                    }
                }

                if ( $updated > 0 ) {
                    update_option(self::OPTION_REMOTE_MAP, $remote_map, false);
                    if ( is_multisite() ) delete_site_transient(self::TRANSIENT_KEY);
                    else delete_transient(self::TRANSIENT_KEY);

                    // rebuild local scan results so UI reflects new mapping immediately
                    $this->get_scan_results(true);
                }

                return wp_send_json_success(['from_remote'=>true,'updated'=>$updated,'scan_pending'=>false,'portal_response'=>$json]);
            }

            // default: portal accepted request and queued scan
            $this->get_scan_results(true);
            return wp_send_json_success(['from_remote'=>false,'updated'=>0,'scan_pending'=>true,'portal_response'=>$json]);
        }

        // non-2xx => treat as error but still show local results
        $this->get_scan_results(true);
        return wp_send_json_error(['code'=>$code,'response'=>$json,'body'=>$body], 502);
    }

    /* AJAX: Request Scan (explicit) */
    public function ajax_request_scan() {
        if ( ! current_user_can(self::CAP_SINGLE) ) wp_send_json_error('forbidden', 403);
        check_ajax_referer('pcc_settings_nonce', 'nonce');

        $license = trim(get_option(self::OPTION_LICENSE_KEY, ''));
        $license_valid = (bool) get_option(self::OPTION_LICENSE_VALID, false);
        if ( empty($license) ) return wp_send_json_error(['error'=>'no_license_configured','message'=>'License not configured']);

        // validate license server-side if not already valid
        if ( ! $license_valid ) {
            $v = $this->server_validate_license($license, get_site_url());
            if ( is_wp_error($v) ) {
                update_option(self::OPTION_LICENSE_VALID, false);
                return wp_send_json_error(['message'=>'license_invalid_or_network','detail'=>$v->get_error_message()], 401);
            } else {
                update_option(self::OPTION_LICENSE_VALID, true);
                $license_valid = true;
            }
        }

        $endpoint = rtrim(self::PORTAL_API, '/') . '/receive_client.php';
       $site_host = $this->normalize_site_host(get_site_url());
$payload = [
    'site_url'   => $site_host,
    'wp_version' => get_bloginfo('version'),
    'php_version'=> phpversion(),
    'server_info'=> [
        'os' => PHP_OS,
        'sapi' => php_sapi_name(),
    ],
    'components' => $this->build_components_payload()
];


        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Portal-License' => $license,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ];

        $res = wp_remote_post($endpoint, $args);
        if ( is_wp_error($res) ) {
            return wp_send_json_error(['error'=>'request_failed','message'=>$res->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        if ( $code >= 200 && $code < 300 ) {
            // If portal returned immediate scan_results apply them (same logic as above)
            if ( is_array($json) && isset($json['status']) && $json['status'] === 'ok' && ! empty($json['scan_results']) ) {
                $remote_map = get_option(self::OPTION_REMOTE_MAP, []);
                if ( ! is_array($remote_map) ) $remote_map = [];

                $acc = [];
                foreach ( $json['scan_results'] as $sr ) {
                    $standards = $sr['standards'] ?? null;
                    if ( empty($standards) || stripos($standards,'PHPCompatibilityWP') === false ) continue;
                    if ( empty($sr['slug']) ) continue;

                    $is_compat = false;
                    if ( isset($sr['is_compatible']) ) {
                        $val = $sr['is_compatible'];
                        if ( is_bool($val) ) $is_compat = $val;
                        elseif ( is_numeric($val) ) $is_compat = ((int)$val === 1);
                        elseif ( is_string($val) ) $is_compat = in_array(strtolower($val), ['1','true','yes'], true);
                    }
                    if ( ! $is_compat ) continue;

                    $pv = $sr['php_version'] ?? '';
                    if ( ! $pv ) continue;
                    $parts = preg_split('/\s*,\s*/', trim($pv));
                    if ( ! isset($acc[$sr['slug']]) ) $acc[$sr['slug']] = [];
                    foreach ($parts as $p) { if ($p !== '') $acc[$sr['slug']][] = $p; }
                }

                $updated = 0;
                foreach ($acc as $slug => $list) {
                    $list = array_values(array_unique($list));
                    usort($list, 'version_compare');
                    $norm = implode(', ', $list);
                    if ( ! isset($remote_map[$slug]) || $remote_map[$slug] !== $norm ) {
                        $remote_map[$slug] = $norm;
                        $updated++;
                    }
                }

                if ( $updated > 0 ) {
                    update_option(self::OPTION_REMOTE_MAP, $remote_map, false);
                    if ( is_multisite() ) delete_site_transient(self::TRANSIENT_KEY);
                    else delete_transient(self::TRANSIENT_KEY);

                    // rebuild cached scan results so UI uses new mapping
                    $this->get_scan_results(true);
                }

                return wp_send_json_success(['response'=>$json,'message'=>'accepted_and_applied','applied_updated'=>$updated]);
            }

            return wp_send_json_success(['response'=>$json,'message'=>'accepted']);
        } else {
            return wp_send_json_error(['code'=>$code,'response'=>$json,'body'=>$body]);
        }
    }

    /* AJAX: Fetch Remote (ensure local cache rebuild when new results applied) */
    public function ajax_fetch_remote() {
        if ( ! current_user_can(self::CAP_SINGLE) ) wp_send_json_error('forbidden', 403);
        check_ajax_referer('pcc_settings_nonce', 'nonce');

        $result = $this->fetch_and_apply_remote_results();
        if ( is_wp_error($result) ) {
            $msg = $result->get_error_message();
            return wp_send_json_error(['error'=>'fetch_failed','message'=>$msg], 502);
        }

        $updated = isset($result['updated']) ? (int)$result['updated'] : 0;
        $scan_pending = ! empty($result['scan_pending']);

        if ( $updated > 0 ) {
            // force rebuild so dashboard sees changes immediately
            $this->get_scan_results(true);
        }

        return wp_send_json_success(['ok'=>true, 'updated'=>$updated, 'scan_pending'=>$scan_pending]);
    }

    /* AJAX: Validate license (unchanged aside from persistence) */
    public function ajax_validate_license() {
    if ( ! current_user_can(self::CAP_SINGLE) ) wp_send_json_error('forbidden', 403);
    check_ajax_referer('pcc_settings_nonce', 'nonce');

    $license = isset($_POST['license']) ? trim(sanitize_text_field($_POST['license'])) : get_option(self::OPTION_LICENSE_KEY, '');
    $site_url = isset($_POST['site_url']) ? trim(sanitize_text_field($_POST['site_url'])) : get_site_url();

    if ( empty($license) ) {
        update_option(self::OPTION_LICENSE_VALID, false);
        return wp_send_json_error(['message'=>'missing_license'], 400);
    }

    $v = $this->server_validate_license($license, $site_url);
    if ( is_wp_error($v) ) {
        update_option(self::OPTION_LICENSE_VALID, false);
        // include portal data when present for debugging/UI
        $err_data = $v->get_error_data();
        return wp_send_json_error(['message'=>'validate_failed','detail'=>$v->get_error_message(),'detail_data'=>$err_data], 502);
    }

    // $v should be the parsed portal JSON and (by server_validate_license) must have valid === true
    update_option(self::OPTION_LICENSE_KEY, $license);
    update_option(self::OPTION_LICENSE_VALID, true);
    return wp_send_json_success(['message'=>'license_valid','response'=>$v]);
}


    /* Cron scheduling */
    public function maybe_schedule_cron() {
        add_filter('cron_schedules', function($s){
            if (!isset($s['ten_minutes'])) $s['ten_minutes'] = ['interval'=>600, 'display'=>'Every Ten Minutes'];
            return $s;
        });

        if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
            wp_schedule_event(time()+60, 'ten_minutes', self::CRON_HOOK);
        }
    }

    public function cron_fetch_remote() {
        $this->fetch_and_apply_remote_results(true);
    }

    /* Fetch & apply remote mapping (returns array with scan_pending flag) */
    protected function fetch_and_apply_remote_results($is_cron=false) {
        $license = trim(get_option(self::OPTION_LICENSE_KEY, ''));
        $license_valid = (bool) get_option(self::OPTION_LICENSE_VALID, false);

        if ( empty(self::PORTAL_API) || empty($license) || ! $license_valid ) {
            return new WP_Error('missing_config', 'Portal or license not configured or not validated');
        }

        $fetch_url = rtrim(self::PORTAL_API, '/') . '/fetch_data.php';
        $payload = [ 'site_url' => $this->normalize_site_host(get_site_url()) ];

        $args = [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Portal-License' => $license,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20,
        ];

        $res = wp_remote_post($fetch_url, $args);
        if ( is_wp_error($res) ) return $res;

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        // If portal didn't return OK (or empty scan_results), treat as pending
        if ( ! is_array($json) || ! isset($json['status']) || $json['status'] !== 'ok' ) {
            return [ 'updated' => 0, 'scan_pending' => true ];
        }

        $remote_map = get_option(self::OPTION_REMOTE_MAP, []);
        if ( ! is_array($remote_map) ) $remote_map = [];

        $updated = 0;
        $new_map = $remote_map;

        if ( isset($json['scan_results']) && is_array($json['scan_results']) && count($json['scan_results']) > 0 ) {
            // aggregate multiple rows per slug into a single comma-separated list
            $acc = []; // slug => array of versions

            foreach ( $json['scan_results'] as $sr ) {
                $standards = $sr['standards'] ?? null;
                if ( empty($standards) || stripos($standards,'PHPCompatibilityWP') === false ) {
                    continue;
                }
                if ( empty($sr['slug']) ) continue;
                $slug = $sr['slug'];

                // Normalise is_compatible (support ints, strings, booleans)
                $is_compat = false;
                if ( isset($sr['is_compatible']) ) {
                    $val = $sr['is_compatible'];
                    if ( is_bool($val) ) $is_compat = $val;
                    elseif ( is_numeric($val) ) $is_compat = ((int)$val === 1);
                    elseif ( is_string($val) ) $is_compat = in_array(strtolower($val), ['1','true','yes'], true);
                }
                if ( ! $is_compat ) continue;

                $pv = $sr['php_version'] ?? '';
                if ( ! $pv ) continue;

                $parts = preg_split('/\s*,\s*/', trim($pv));
                if ( ! isset($acc[$slug]) ) $acc[$slug] = [];
                foreach ($parts as $p) {
                    if ($p === '') continue;
                    $acc[$slug][] = $p;
                }
            }

            // merge accumulations into new_map, dedupe and sort
            foreach ($acc as $slug => $ver_list) {
                $ver_list = array_values(array_unique($ver_list));
                usort($ver_list, 'version_compare');
                $norm = implode(', ', $ver_list);

                if ( ! isset($new_map[$slug]) || $new_map[$slug] !== $norm ) {
                    $new_map[$slug] = $norm;
                    $updated++;
                }
            }
        } else {
            return [ 'updated' => 0, 'scan_pending' => true ];
        }

        if ( $updated > 0 ) {
            update_option(self::OPTION_REMOTE_MAP, $new_map, false);
            if ( is_multisite() ) delete_site_transient(self::TRANSIENT_KEY);
            else delete_transient(self::TRANSIENT_KEY);

            if ( $is_cron ) {
                $admin_email = get_option('admin_email');
                $subject = 'CompatShield: New remote scan results fetched';
                $message = sprintf("Fetched %d new PHP compatibility entries from the portal for site %s\n\nVisit %s to view results.", $updated, get_site_url(), admin_url('admin.php?page=' . self::MENU_SLUG));
                @wp_mail($admin_email, $subject, $message);
            }
        }

        return [ 'updated' => $updated, 'scan_pending' => false ];
    }

    /* UI renderers (table colors reverted) */
    private function stat_card($icon_url, $title, $value_html) {
        echo '<div class="stat-card" style="display:inline-block;margin:8px;padding:18px;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,0.06);width:180px;text-align:center;vertical-align:top;background:#fff;">';
        if ($icon_url) {
            echo '<div class="stat-icon" style="height:64px;margin-bottom:8px;"><img src="'.esc_url($icon_url).'" alt="'.esc_attr($title).' Icon" style="max-height:64px;max-width:64px;"></div>';
        }
        echo '<div class="stat-content">';
        echo '<h3 style="margin:6px 0 4px;font-size:16px;">'.esc_html($title).'</h3>';
        echo '<p style="margin:0;font-size:18px;font-weight:600;">'.esc_html($value_html).'</p>';
        echo '</div>';
        echo '</div>';
    }

    /* Build payload for portal: include currently installed plugins + active theme */
    protected function build_components_payload() {
        $plugins = get_plugins(); // installed plugins
        $out = [];

        foreach ( $plugins as $file => $info ) {
            // skip if main plugin file does not exist in plugins directory (handles removed files)
            $candidate = WP_PLUGIN_DIR . '/' . $file;
            if ( ! file_exists($candidate) ) continue;

            $slug = $this->resolve_plugin_slug($file, $info['TextDomain'] ?? '', $info['PluginURI'] ?? '');
            $out[] = [
                'type' => 'plugin',
                'slug' => $slug ?: basename(dirname($file)),
                'name' => $info['Name'] ?? $file,
                'version' => $info['Version'] ?? '',
                'source' => 'wporg',
            ];
        }

        $theme = wp_get_theme();
        $out[] = [
            'type' => 'theme',
            'slug' => $theme->get_stylesheet(),
            'name' => $theme->get('Name'),
            'version' => $theme->get('Version'),
            'source' => 'wporg',
        ];

        return $out;
    }

    public function render_network() {
        if ( ! current_user_can(self::CAP_NETWORK) ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pcc'));
        }
        $this->render_dashboard(true);
    }

    public function render_single_site() {
        if ( ! current_user_can(self::CAP_SINGLE) ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pcc'));
        }
        $this->render_dashboard(false);
    }

    private function render_dashboard($is_network = false) {
		 $license_valid = (bool) get_option(self::OPTION_LICENSE_VALID, false);
		 $buy_link   = esc_url(self::PRODUCT_URL);
		$signup_link = esc_url(self::SIGNUP_URL);
        $stats     = $this->get_environment_stats($is_network);
        $scan      = $this->get_scan_results(false, $is_network);
        $icons     = [
            'wp'       => plugins_url('icons/wordpress.png', __FILE__),
            'php'      => plugins_url('icons/php.png', __FILE__),
            'plug'     => plugins_url('icons/plug.png', __FILE__),
            'active'   => plugins_url('icons/check-mark.png', __FILE__),
            'inactive' => plugins_url('icons/multiplication.png', __FILE__),
        ];

        echo '<h1 class="pluginheading">'.esc_html__('Check Your Plugin Compatibility', 'pcc').'</h1>';

if ( ! $license_valid ) {
    echo '<div class="notice" style="margin:12px 0;padding:14px;border-left:4px solid #135e96;background:#f7fbff;">
            <strong>Unlock PHP 8.1–8.5 checks — only $1/month (subscription).</strong>
            Subscribe for $1/month to get a CompatShield license key and view newer PHP compatibility in this dashboard.
            &nbsp; <a class="button button-primary" href="'.$signup_link.'" target="_blank" rel="noopener">Subscribe — $1/month </a>
            &nbsp; <a class="button" href="'.$buy_link.'" target="_blank" rel="noopener">See Pro features</a>
          </div>';
}



        echo '<div class="stats-grid">';
        $this->stat_card($icons['wp'], 'WordPress', $stats['wp_version']);
        $this->stat_card($icons['php'], 'PHP', $stats['php_version']);
        $this->stat_card($icons['plug'], 'Plugins Installed', (string) $stats['plugins_total']);
        $this->stat_card($icons['active'], $is_network ? 'Plugins Active (Network)' : 'Plugins Active', (string) $stats['plugins_active']);
        $this->stat_card($icons['inactive'], $is_network ? 'Plugins Inactive (Network)' : 'Plugins Inactive', (string) $stats['plugins_inactive']);
        echo '</div>';

        if ( version_compare($stats['wp_version'], $stats['wp_latest'], '>=') ) {
            echo '<br><b>'.esc_html__('You are already on the latest WordPress version.', 'pcc').'</b><br><br>';
        } else {
            echo '<br><b>'.sprintf(esc_html__('The latest stable WordPress version available is: %s', 'pcc'), esc_html($stats['wp_latest'])).'</b><br><br>';
        }

        $settings_url = admin_url('admin.php?page=' . self::SUBMENU_SETTINGS_SLUG);
        $license = trim(get_option(self::OPTION_LICENSE_KEY, ''));
        $license_valid = (bool) get_option(self::OPTION_LICENSE_VALID, false); 
        $remote_map = get_option(self::OPTION_REMOTE_MAP, []);
        if ( ! is_array($remote_map) ) $remote_map = [];

        echo '
        <div class="tnip" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <div style="flex:1;min-width:260px;">
                <b class="filter">'.esc_html__('Filter By Plugin Status', 'pcc').'</b>
                <select class="form-control fltr" data-role="select-dropdown" id="plgstatus" style="display:inline-block;max-width:220px;margin-left:8px;">
                    <option value="all">'.esc_html__('Plugin Status', 'pcc').'</option>
                    <option value="Activated">'.esc_html__('Activated', 'pcc').'</option>
                    <option value="Deactivated">'.esc_html__('Deactivated', 'pcc').'</option>
                </select>
            </div>
            <div style="flex:1;text-align:right;min-width:320px;">
                <a href="' . esc_url($settings_url) . '" class="button button-secondary" style="margin-right:8px;">' . esc_html__('Settings', 'pcc') . '</a>
                <button id="exportButton" class="button button-secondary" style="margin-right:8px;">'.esc_html__('Export to CSV', 'pcc').'</button>
                <button id="pcc-fetch-latest" class="button button-secondary"  style="margin-right:8px;">Fetch latest result</button>
                <button id="pcc-rescan" class="button button-primary" style="margin-right:8px;">'.esc_html__('Rescan', 'pcc').'</button>
                <div id="pcc-mode-notice" style="display:inline-block;vertical-align:middle;margin-left:12px;font-weight:600;"></div>
            </div>
        </div>';

        echo '<hr />';
        echo '<h2>'.esc_html__('Local Plugin Compatibility Summary', 'pcc').'</h2>';
     
	 if ( $license_valid ) {
    echo '<p style="color:#155724;font-weight:500;">
        The results you are viewing are fetched directly from the CompatShield Portal because your license is active.
        These include advanced PHP compatibility checks (PHP 8.1–8.5).
    </p>';
} else {
    echo '<p>'.esc_html__(
        'This table uses WPTide data until you validate a CompatShield license. Subscribe to the $1/month entry plan (recurring) to unlock Portal results (PHP 8.1–8.5). Use "Rescan" to request a fresh Portal scan (when licensed) or refresh WPTide (when not). Use "Fetch latest result" to pull Portal output when available.',
        'pcc'
    ).'</p>';
}

	 
	 
        $this->render_dashboard_table_only();

        $notice_text = $license_valid
    ? 'Portal mode — license validated. Showing CompatShield results (includes PHP 8.1–8.5).'
    : 'WPTide mode — showing community results (limited to PHP ≤ 8.0). <a href="'.$signup_link.'" target="_blank" rel="noopener">Subscribe — $1/month </a> to enable CompatShield results (PHP 8.1–8.5).';

        // Inline JS: set mode notice + auto-fetch remote when license_valid and remote map empty
        $pcc_settings_nonce = wp_create_nonce('pcc_settings_nonce');
        ?>
        <script>
        (function($){
            $(document).ready(function(){
                var noticeHtml = <?php echo json_encode($notice_text); ?>;
                $('#pcc-mode-notice').html(noticeHtml).css('color','<?php echo $license_valid ? '#155724' : '#b71c1c'; ?>');

                $('#pcc-rescan').on('click', function(){
                    var btn = $(this);
                    btn.prop('disabled',true).text('Requesting scan...');
                    $.post(ajaxurl, {
                        action: '<?php echo self::AJAX_ACTION?>',
                        nonce: '<?php echo wp_create_nonce(self::NONCE_ACTION);?>'
                    }).done(function(resp){
                        if (resp.success) {
                            var data = resp.data || {};
                            if (data.scan_pending) {
                                $('#pcc-mode-notice').html('Portal mode — scan in progress; results will appear when ready.').css('color','#856404');
                                alert('Scan requested on Portal. Results will be available when the Portal completes scanning.');
                            } else {
                                alert('Local scan refreshed (fallback) or scan request failed to queue; check messages.');
                            }
                            setTimeout(function(){ location.reload(); }, 700);
                        } else {
                            alert('Rescan failed: ' + JSON.stringify(resp.data));
                        }
                    }).fail(function(){ alert('Rescan failed (network)'); }).always(function(){ btn.prop('disabled',false).text('<?php echo esc_js(__('Rescan', 'pcc')); ?>'); });
                });

                // Auto-fetch remote mapping if license is valid but we have no mapping yet.
                <?php if ( $license_valid && empty($remote_map) ) : ?>
                    // attempt to fetch remote mapping automatically
                    (function(){
                        var btn = $('<button/>',{id:'pcc-autofetch', text:'Fetching remote results...'}).css({display:'none'});
                        $('body').append(btn);
                        $.post(ajaxurl, {
                            action: 'pcc_fetch_remote',
                            nonce: '<?php echo esc_js($pcc_settings_nonce); ?>'
                        }).done(function(resp){
                            if ( resp && resp.success && resp.data && resp.data.updated > 0 ) {
                                // mapping applied; reload to show new mapping
                                setTimeout(function(){ location.reload(); }, 700);
                            }
                        }).fail(function(){ /* ignore silently */ });
                    })();
                <?php endif; ?>
            });
        })(jQuery);
        </script>
        <?php
    }

    public function render_sysinfo() {
        $cap = is_multisite() ? self::CAP_SINGLE : self::CAP_SINGLE;
        if ( ! current_user_can($cap) ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pcc'));
        }
        echo '<h1>'.esc_html__('System Info', 'pcc').'</h1><br>';
        $php = phpversion();
        echo '<b style="font-size:18px;">'.sprintf(esc_html__('Your Current PHP Version is: %s', 'pcc'), esc_html($php)).'</b><br>';

        $space_total_gb = (int) ( @disk_total_space(ABSPATH) / 1024 / 1024 / 1024 );
        $space_free_gb  = (int) ( @disk_free_space(ABSPATH)  / 1024 / 1024 / 1024 );
        $space_used_gb  = max(0, $space_total_gb - $space_free_gb);

        echo '<table class="sysinfo"><tr><td><b>'.esc_html__('Disk Total Space', 'pcc').'</b></td><td><b>'.intval($space_total_gb).' GB</b></td></tr>';
        echo '<tr><td><b>'.esc_html__('Disk Space Used', 'pcc').'</b></td><td><b>'.intval($space_used_gb).' GB</b></td></tr>';
        echo '<tr><td><b>'.esc_html__('Disk Space Free', 'pcc').'</b></td><td><b>'.intval($space_free_gb).' GB</b></td></tr></table>';

        $ini = [
            'Max Execution Time' => ini_get('max_execution_time'),
            'Max File Uploads'   => ini_get('max_file_uploads'),
            'Max Input Vars'     => ini_get('max_input_vars'),
            'Post Max Size'      => ini_get('post_max_size'),
            'Memory Limit'       => ini_get('memory_limit'),
            'Upload Max Filesize'=> ini_get('upload_max_filesize'),
        ];

        echo '<table class="sysinfo">';
        foreach ($ini as $k => $v) {
            echo '<tr><td><b>'.esc_html($k).'</b></td><td><b>'.esc_html($v).'</b></td></tr>';
        }
        echo '</table>';

        $exts = get_loaded_extensions();
        sort($exts, SORT_NATURAL | SORT_FLAG_CASE);
        echo '<center><p class="pheading">'.esc_html__('List of Loaded Extensions', 'pcc').'</p><br>';
        echo '<table class="extntable">';
        foreach ( $exts as $ext ) {
            echo '<tr><td class="tdstyle">'.esc_html($ext).'</td></tr>';
        }
        echo '</table></center>';
    }

    public function render_settings() {
        if ( ! current_user_can(self::CAP_SINGLE) ) {
            wp_die(esc_html__('You do not have permission to access this page.', 'pcc'));
        }

        if ( isset($_POST['pcc_save_settings']) && check_admin_referer('pcc_settings_save') ) {
            $license = isset($_POST['pcc_license_key']) ? sanitize_text_field(trim($_POST['pcc_license_key'])) : '';
            update_option(self::OPTION_LICENSE_KEY, $license);
            update_option(self::OPTION_LICENSE_VALID, false);
            echo '<div class="updated"><p>Settings saved. Please validate the license using the "Validate License" button.</p></div>';
        }

        $license = esc_attr(get_option(self::OPTION_LICENSE_KEY, ''));
        $license_valid = (bool) get_option(self::OPTION_LICENSE_VALID, false);
        $buy_link   = esc_url(self::PRODUCT_URL);
		$signup_link = esc_url(self::SIGNUP_URL);


        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Plugin Compatibility Checker', 'pcc'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('pcc_settings_save'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="pcc_license_key">Portal License Key</label></th>
                        <td>
                            <input type="text" id="pcc_license_key" name="pcc_license_key" value="<?php echo $license; ?>" class="regular-text" />
<p class="description">
   Enter your CompatShield license key to enable Portal mode.
Without a validated license, the plugin uses WPTide data limited to PHP ≤ 8.0.
With a validated license (entry plan $1/month or Pro), CompatShield shows newer PHP compatibility (8.1–8.5) right in your dashboard.
Don’t have a key? <a href="<?php echo $signup_link; ?>" target="_blank" rel="noopener">Subscribe for $1/month </a>.

</p>
                        
						</td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="pcc_save_settings" id="submit" class="button button-primary" value="Save Settings"></p>
            </form>

            <p>
                <button id="pcc-request-scan" class="button button-secondary">Request Scan on Portal</button>
                &nbsp;
                <button id="pcc-fetch-latest" class="button">Fetch latest result</button>
                &nbsp;
                <button id="pcc-validate-license" class="button">Validate License</button>
                <span id="pcc-settings-msg" style="margin-left:12px;"></span>
                <span id="pcc-license-mode" style="margin-left:12px;font-weight:600;"></span>
            </p>

            <p style="margin-top:8px;">
               <?php if (! $license_valid && $license) : ?>
    <em style="color:#856404;">Saved license not validated yet. Click <strong>Validate License</strong> to enable Portal mode.</em>
<?php elseif (! $license) : ?>
    <em style="color:#b71c1c;">No license configured — using WPTide results (PHP ≤ 8.0). <a href="<?php echo $signup_link; ?>" target="_blank" rel="noopener">Subscribe — $1/month </a> or <a href="<?php echo $buy_link; ?>" target="_blank" rel="noopener">upgrade to Pro</a>.</em>
<?php else: ?>
    <em style="color:#155724;">License validated — Portal mode enabled (PHP 8.1–8.5 shown).</em>
<?php endif; ?>
            </p>
			
			
			<div style="margin-top:12px;padding:14px;border:1px solid #e5e5e5;border-radius:6px;background:#fafafa;">
    <strong>No license yet?</strong>
<a class="button button-primary" href="<?php echo $signup_link; ?>" target="_blank" rel="noopener">Subscribe — $1/month </a>
&nbsp; or &nbsp;
<a class="button" href="<?php echo $buy_link; ?>" target="_blank" rel="noopener">View Pro </a>
<div style="margin-top:6px;color:#555;">The $1 entry plan unlocks PHP 8.1–8.5 compatibility visibility via the Portal. Pro adds deeper summaries and recommendations.</div>

</div>


            <hr />

            <p><em><?php esc_html_e('Use the main "Plugin Compatibility Checker" menu item to view the local compatibility table and dashboard. Settings page only manages portal/license and remote actions.', 'pcc'); ?></em></p>

<p><em><?php esc_html_e('Use the User-Guide if you face any issue after license activation or you can raise a ticket or mail at support@compatshield.com.', 'pcc'); ?></em></p>

<p><a href="https://www.compatshield.com/user-guide/" target="_blank"><strong><?php esc_html_e('Open Full User Guide', 'pcc'); ?></strong></a></p>

<!-- YouTube Video Tutorial -->
<div style="margin-top:15px; margin-bottom:25px;">
<iframe width="100%" height="350" src="https://www.youtube.com/embed/PCxhJmO-Tb4" frameborder="0" allowfullscreen></iframe>
</div>

<!-- Quick Steps Summary -->
<p><strong><?php esc_html_e('Quick Steps:', 'pcc'); ?></strong></p>
<ol style="margin-left:20px;">
    <li><?php esc_html_e('Login to Portal Dashboard → Add your domain inside License tab', 'pcc'); ?></li>
    <li><?php esc_html_e('Copy your License Key from Portal', 'pcc'); ?></li>
    <li><?php esc_html_e('Paste License Key inside Plugin settings here', 'pcc'); ?></li>
    <li><?php esc_html_e('Click Validate License', 'pcc'); ?></li>
    <li><?php esc_html_e('Click Save Settings', 'pcc'); ?></li>
    <li><?php esc_html_e('Click Rescan on main scan page to fetch plugins', 'pcc'); ?></li>
</ol>

        </div>

        <script>
        (function($){
            $(document).ready(function(){
                if (typeof PCCSettings !== 'undefined' && typeof PCCSettings.licenseActive !== 'undefined') {
                    var active = PCCSettings.licenseActive;
                    $('#pcc-license-mode').text(active ? 'Portal mode — license validated' : 'Fallback mode — no validated license').css('color', active ? '#155724' : '#b71c1c');
                } else {
                    var srvActive = <?php echo json_encode(($license !== '' && $license_valid)); ?>;
                    $('#pcc-license-mode').text(srvActive ? 'Portal mode — license validated' : 'Fallback mode — no validated license').css('color', srvActive ? '#155724' : '#b71c1c');
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    private function render_dashboard_table_only() {
        $is_network = is_multisite();
        $stats     = $this->get_environment_stats($is_network);
        $scan      = $this->get_scan_results(false, $is_network);
        echo '<div class="table-responsive table-hover"><table class="table table-bordered" id="pcctable">
            <thead class="thead-dark">
                <tr>
                    <th scope="col">'.esc_html__('Plugin Name', 'pcc').'</th>
                    <th scope="col">'.esc_html__('Current Plugin Version', 'pcc').'</th>
                    <th scope="col">'.esc_html__('Latest Plugin Version', 'pcc').'</th>
                    <th scope="col">'.esc_html__('Compatible With WordPress Version', 'pcc').'</th>
                    <th scope="col">'.esc_html__('PHP Supported (WPTide: ≤8.0 • Portal: 8.1–8.5)', 'pcc').'</th>
                    <th scope="col">'.esc_html($is_network ? 'Plugin Network Status' : 'Plugin Status').'</th>
                    <th scope="col">'.esc_html__('Updateable With Latest Version of WordPress', 'pcc').'</th>
                    <th scope="col">'.esc_html__('Issues Resolved in Last Two Months', 'pcc').'</th>
                </tr>
            </thead>
            <tbody class="tbdy">';

        $export_rows = [];
        foreach ( $scan['rows'] as $row ) {
            // reverted to original colors
            $bg = ($row['current_version'] === $row['latest_version'] && $row['latest_version'] !== 'No Data') ? '#135e96' : '#f64855';

            echo '<tr style="background-color:'.esc_attr($bg).'">';
            echo '<th scope="row">'.esc_html($row['name']).'</th>';
            echo '<td>'.esc_html($row['current_version']).'</td>';
            echo '<td>'.esc_html($row['latest_version']).'</td>';
            echo '<td>'.esc_html($row['tested_wp']).'</td>';
            echo '<td>'.esc_html($row['php_supported']).'</td>';
            echo '<td>'.esc_html($row['status']).'</td>';
            echo '<td>'.esc_html($row['upgradeable']).'</td>';
            echo '<td>'.esc_html($row['issues_ratio']).'</td>';
            echo '</tr>';

            $export_rows[] = [
                'Plugin Name' => str_replace(',', ' ', $row['name']),
                'Current Plugin Version' => $row['current_version'],
                'Latest Plugin Version' => $row['latest_version'],
                'Compatible With WordPress Version' => $row['tested_wp'],
                'Supported PHP Version' => str_replace(',', ' ', $row['php_supported']),
                $is_network ? 'Plugin Network Status' : 'Plugin Status' => $row['status'],
                'Updateable With Latest Version of WordPress' => $row['upgradeable'],
                'Issues Resolved in Last Two Months' => "'" . str_replace(':','', $row['issues_ratio']),
            ];
        }

        echo '</tbody></table></div>';

// ===== Add a note below the table (Option B) =====
echo '<div class="pcc-table-note" style="margin-top:12px;padding:10px;border-left:4px solid #135e96;background:#f7fbff;">';
echo '<strong>' . esc_html__('Note:', 'pcc') . '</strong> ' . esc_html__('Plugins showing No Data are likely custom/premium — check with the plugin author or the plugin version you are using have been removed by the author from wporg.', 'pcc');
echo '</div>';
// ================================================

printf('<script>window.PCCExportData=%s;</script>', wp_json_encode($export_rows));

    }

    /* Helpers (unchanged) */

    private function resolve_plugin_slug($plugin_file, $text_domain, $plugin_uri) {
        $dir = dirname($plugin_file);
        if ( $dir && $dir !== '.' ) {
            return $dir;
        }
        if ( ! empty($text_domain) ) {
            return sanitize_title($text_domain);
        }
        if ( $plugin_uri ) {
            $parts = explode('/', $plugin_uri, 5);
            if ( isset($parts[4]) && ! empty($parts[4]) ) {
                return rtrim($parts[4], '/');
            }
        }
        return '';
    }

    private function fetch_wp_latest_version() {
        $url = 'https://api.wordpress.org/core/version-check/1.7/';
        $res = wp_remote_get($url, [ 'timeout' => 15 ]);
        if ( is_wp_error($res) ) {
            return get_bloginfo('version');
        }
        $body = wp_remote_retrieve_body($res);
        $obj  = json_decode($body);
        return isset($obj->offers[0]->version) ? $obj->offers[0]->version : get_bloginfo('version');
    }

    private function fetch_wporg_plugin_info($slug) {
        if ( empty($slug) ) return [];
        $url = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode($slug);
        $res = wp_remote_get($url, [ 'timeout' => 20 ]);
        if ( is_wp_error($res) ) return [];
        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        if ( ! is_array($data) || isset($data['error']) ) return [];
        return [
            'tested'           => $data['tested']              ?? null,
            'version'          => $data['version']             ?? null,
            'support_threads'  => $data['support_threads']     ?? null,
            'support_resolved' => $data['support_threads_resolved'] ?? null,
            'requires_php'     => $data['requires_php']        ?? null,
        ];
    }

    private function fetch_wptide_php_compat_list($slug, $latest_version) {
        $url = sprintf('https://wptide.org/api/v1/audit/wporg/plugin/%s/%s?reports=all', rawurlencode($slug), rawurlencode($latest_version));
        $res = wp_remote_get($url, [ 'timeout' => 20 ]);
        if ( is_wp_error($res) ) {
            return null;
        }
        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);
        if ( ! is_array($data) || isset($data['error']) ) return null;

        $compat_arr = $data['reports']['phpcs_phpcompatibilitywp']['report']['compatible'] ?? null;
        if ( ! is_array($compat_arr) || empty($compat_arr) ) return null;

        return implode(', ', $compat_arr);
    }

    private function get_environment_stats($is_network) {
        $wp_version = get_bloginfo('version');
        $php_ver    = phpversion();
        $plugins    = get_plugins();
        $total      = count($plugins);

        if ( $is_network ) {
            $active_sitewide = (array) get_site_option('active_sitewide_plugins', []);
            $active = 0;
            foreach ($plugins as $file => $info) {
                if ( isset($active_sitewide[$file]) ) $active++;
            }
        } else {
            $active_plugins = (array) get_option('active_plugins', []);
            $active = 0;
            foreach ($plugins as $file => $info) {
                if ( in_array($file, $active_plugins, true) ) $active++;
            }
        }
        $inactive = max(0, $total - $active);

        $wp_latest = $this->fetch_wp_latest_version();

        return [
            'wp_version'      => $wp_version,
            'php_version'     => $php_ver,
            'plugins_total'   => $total,
            'plugins_active'  => $active,
            'plugins_inactive'=> $inactive,
            'wp_latest'       => $wp_latest,
        ];
    }

    private function get_scan_results($force_rebuild = false, $is_network = null) {
        if ( is_null($is_network) ) $is_network = is_multisite();
        $key = self::TRANSIENT_KEY;
        $ttl = apply_filters('pcc_cache_ttl', self::TRANSIENT_TTL_DEFAULT);

        $data = $is_network ? get_site_transient($key) : get_transient($key);
        if ( $force_rebuild || empty($data) || ! is_array($data) ) {
            $data = $this->build_scan_results($is_network);
            if ( $is_network ) {
                set_site_transient($key, $data, $ttl);
            } else {
                set_transient($key, $data, $ttl);
            }
        }
        return $data;
    }

    private function build_scan_results($is_network) {
        $plugins        = get_plugins();
        $wp_latest      = $this->fetch_wp_latest_version();

        if ( $is_network ) {
            $active_map = (array) get_site_option('active_sitewide_plugins', []);
        } else {
            $active_map = array_fill_keys( (array) get_option('active_plugins', []), true );
        }

        $rows = [];
        $remote_map = get_option(self::OPTION_REMOTE_MAP, []);
        if ( ! is_array($remote_map) ) $remote_map = [];

        foreach ( $plugins as $file => $plug ) {
            // skip plugins whose main file doesn't exist (handles removals)
            $candidate = WP_PLUGIN_DIR . '/' . $file;
            if ( ! file_exists($candidate) ) continue;

            $name        = isset($plug['Name']) ? $plug['Name'] : $file;
            $current_ver = isset($plug['Version']) ? $plug['Version'] : '';
            $plugin_uri  = isset($plug['PluginURI']) ? $plug['PluginURI'] : '';
            $text_domain = isset($plug['TextDomain']) ? $plug['TextDomain'] : '';
            $slug        = $this->resolve_plugin_slug($file, $text_domain, $plugin_uri);

            $info = $slug ? $this->fetch_wporg_plugin_info($slug) : [];

            $tested_wp     = $info['tested']           ?? 'No Data';
            $latest_ver    = $info['version']          ?? 'No Data';
            $support_total = $info['support_threads']  ?? 'No Data';
            $support_res   = $info['support_resolved'] ?? 'No Data';
            $requires_php  = $info['requires_php']     ?? 'No Data';

            $status = ( isset($active_map[$file]) ? 'Activated' : 'Deactivated' );

            $upgradeable = 'No Data';
            if ( 'No Data' !== $tested_wp ) {
                if ( version_compare($tested_wp, $wp_latest, '>=') ) $upgradeable = 'Yes';
                else $upgradeable = ( $tested_wp === '6.2.0' ) ? 'Yes' : 'No';
            }

            $issues_ratio = 'No Data';
            if ($support_total === ':0' && $support_res === ':0') {
                $issues_ratio = 'There Are No Issues';
            } elseif ($support_total !== 'No Data' && $support_res !== 'No Data') {
                $issues_ratio = str_replace(':','', $support_res) . '/' . str_replace(':','', $support_total);
            }

            $php_supported = 'No Data';
            if ( $slug && isset($remote_map[$slug]) && ! empty($remote_map[$slug]) ) {
                $php_supported = $remote_map[$slug];
            } else {
                if ( $slug && 'No Data' !== $latest_ver ) {
                    $compat = $this->fetch_wptide_php_compat_list($slug, $latest_ver);
                    $php_supported = $compat ? $compat : 'No Data';
                }
            }

            $rows[] = [
                'name'            => $name,
                'current_version' => $current_ver ?: 'No Data',
                'latest_version'  => $latest_ver,
                'tested_wp'       => $tested_wp,
                'php_supported'   => $php_supported,
                'status'          => $status,
                'upgradeable'     => $upgradeable,
                'issues_ratio'    => $issues_ratio,
            ];
        }

        return [ 'rows' => $rows ];
    }
}

new PCC();

endif;
