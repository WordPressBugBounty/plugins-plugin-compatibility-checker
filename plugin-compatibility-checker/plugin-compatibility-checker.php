<?php
/**
 * Plugin Name: Plugin Compatibility Checker
 * Description: Check which WordPress and PHP versions your plugins are compatible with before updating WordPress. Adds caching and a Rescan button.
 * Version: 5.0.2
 * Author: Dinesh Pilani
 * Author URI: https://www.linkedin.com/in/dineshpilani/
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! class_exists('PCC') ) :

class PCC {

	const MENU_SLUG                 = 'PCC_Check';
	const SUBMENU_SYSINFO_SLUG      = 'websiteinfo';
	const CAP_SINGLE                = 'manage_options';
	const CAP_NETWORK               = 'manage_network';
	const TRANSIENT_KEY             = 'pcc_scan_results';
	const TRANSIENT_TTL_DEFAULT     = 6 * HOUR_IN_SECONDS; // filterable via 'pcc_cache_ttl'
	const NONCE_ACTION              = 'pcc_rescan';
	const AJAX_ACTION               = 'pcc_rescan_now';

	public function __construct() {
		if ( is_multisite() ) {
			add_action('network_admin_menu', [ $this, 'register_network_menus' ]);
			register_activation_hook(__FILE__, [ $this, 'hw_check_network_activation' ]);
		} else {
			add_action('admin_menu', [ $this, 'register_menus' ]);
		}

		add_action('admin_enqueue_scripts', [ $this, 'enqueue_assets' ]);
		add_action('wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_rescan' ]);
	}

	/* -------------------------
	 * Activation guard (MS only)
	 * ------------------------- */
	public function hw_check_network_activation($network_wide) {
		if ( ! $network_wide && is_multisite() ) {
			deactivate_plugins(plugin_basename(__FILE__));
			wp_die(esc_html__('This plugin can only be activated network-wide.', 'pcc'));
		}
	}

	/* ---------
	 * Menus
	 * --------- */
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

		add_submenu_page(
			self::MENU_SLUG,
			__('System Info', 'pcc'),
			__('System Info', 'pcc'),
			self::CAP_SINGLE,
			self::SUBMENU_SYSINFO_SLUG,
			[ $this, 'render_sysinfo' ]
		);
	}

	public function register_network_menus() {
		add_menu_page(
			__('Plugin Compatibility Checker', 'pcc'),
			__('Plugin Compatibility Checker', 'pcc'),
			self::CAP_NETWORK,
			self::MENU_SLUG,
			[ $this, 'render_network' ],
			'dashicons-screenoptions',
			90
		);

		add_submenu_page(
			self::MENU_SLUG,
			__('System Info', 'pcc'),
			__('System Info', 'pcc'),
			self::CAP_NETWORK,
			self::SUBMENU_SYSINFO_SLUG,
			[ $this, 'render_sysinfo' ]
		);
	}

	/* -------------
	 * Assets
	 * ------------- */
	public function enqueue_assets($hook) {
		$screen = get_current_screen();
		if ( empty($screen->id) ) return;
		if ( false === strpos($screen->id, self::MENU_SLUG) && false === strpos($screen->id, self::SUBMENU_SYSINFO_SLUG) ) return;

		// Styles
		wp_enqueue_style('pcc-custom', plugin_dir_url(__FILE__) . 'customcss/pcccustom.css', [], '1.0.0');
		wp_enqueue_style('pcc-bootstrap', plugin_dir_url(__FILE__) . 'customcss/bootstrap.min.css', [], '1.0.1');

		// Your existing scripts
		wp_enqueue_script('pcc-filter', plugin_dir_url(__FILE__) . 'customjs/filtertable.js', ['jquery'], '1.0.0', true);

// Ensure your export script is enqueued (rename filename/handle if different)
wp_enqueue_script('pcc-export', plugin_dir_url(__FILE__) . 'customjs/export.js', ['jquery'], '1.0.0', true);


		// NEW: rescan script (kept separate so your export JS remains untouched)
		wp_register_script('pcc-rescan', plugin_dir_url(__FILE__) . 'customjs/pcc-rescan.js', ['jquery'], '1.0.0', true);
		wp_localize_script('pcc-rescan', 'PCCVars', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce'   => wp_create_nonce(self::NONCE_ACTION),
			'action'  => self::AJAX_ACTION,
		]);
		wp_enqueue_script('pcc-rescan');
	}

	/* -------------
	 * AJAX: Rescan
	 * ------------- */
	public function ajax_rescan() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		if ( is_multisite() ) {
			if ( ! current_user_can(self::CAP_NETWORK) ) wp_send_json_error('forbidden', 403);
			delete_site_transient(self::TRANSIENT_KEY);
		} else {
			if ( ! current_user_can(self::CAP_SINGLE) ) wp_send_json_error('forbidden', 403);
			delete_transient(self::TRANSIENT_KEY);
		}

		// Rebuild now so reload is instant
		$this->get_scan_results(true);

		wp_send_json_success(['ok'=>true]);
	}

	/* -----------------------
	 * Renderers (UI screens)
	 * ----------------------- */
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

	private function render_dashboard($is_network) {
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

		// Stats grid
		echo '<div class="stats-grid">';
		$this->stat_card($icons['wp'], 'WordPress', $stats['wp_version']);
		$this->stat_card($icons['php'], 'PHP', $stats['php_version']);
		$this->stat_card($icons['plug'], 'Plugins Installed', (string) $stats['plugins_total']);
		$this->stat_card($icons['active'], $is_network ? 'Plugins Active (Network)' : 'Plugins Active', (string) $stats['plugins_active']);
		$this->stat_card($icons['inactive'], $is_network ? 'Plugins Inactive (Network)' : 'Plugins Inactive', (string) $stats['plugins_inactive']);
		echo '</div>';

		// Core version message
		if ( version_compare($stats['wp_version'], $stats['wp_latest'], '>=') ) {
			echo '<br><b>'.esc_html__('You are already on the latest WordPress version.', 'pcc').'</b><br><br>';
		} else {
			echo '<br><b>'.sprintf(
				esc_html__('The latest stable WordPress version available is: %s', 'pcc'),
				esc_html($stats['wp_latest'])
			).'</b><br><br>';
		}

		// Controls (filter + export + rescan)
	echo '
<div class="tnip">
    <div class="pcc-left">
        <b class="filter">'.esc_html__('Filter By Plugin Status', 'pcc').'</b>
        <select class="form-control fltr" data-role="select-dropdown" id="plgstatus">
            <option value="all">'.esc_html__('Plugin Status', 'pcc').'</option>
            <option value="Activated">'.esc_html__('Activated', 'pcc').'</option>
            <option value="Deactivated">'.esc_html__('Deactivated', 'pcc').'</option>
        </select>
    </div>
    <div class="pcc-button-group">
        <button id="exportButton" class="button button-secondary">'.esc_html__('Export to CSV', 'pcc').'</button>
        <button id="pcc-rescan" class="button button-primary">'.esc_html__('Rescan', 'pcc').'</button>
    </div>
</div>';


		// Table
		$col_status = $is_network ? __('Plugin Network Status', 'pcc') : __('Plugin Status', 'pcc');

		echo '<div class="table-responsive table-hover"><table class="table table-bordered" id="pcctable">
			<thead class="thead-dark">
				<tr>
					<th scope="col">'.esc_html__('Plugin Name', 'pcc').'</th>
					<th scope="col">'.esc_html__('Current Plugin Version', 'pcc').'</th>
					<th scope="col">'.esc_html__('Latest Plugin Version', 'pcc').'</th>
					<th scope="col">'.esc_html__('Compatible With WordPress Version', 'pcc').'</th>
					<th scope="col">'.esc_html__('Supported PHP Version', 'pcc').'</th>
					<th scope="col">'.esc_html($col_status).'</th>
					<th scope="col">'.esc_html__('Updateable With Latest Version of WordPress', 'pcc').'</th>
					<th scope="col">'.esc_html__('Issues Resolved in Last Two Months', 'pcc').'</th>
				</tr>
			</thead>
			<tbody class="tbdy">';

		$export_rows = [];
		foreach ( $scan['rows'] as $row ) {
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
				'Plugin Name'                                 => str_replace(',', ' ', $row['name']),
				'Current Plugin Version'                      => $row['current_version'],
				'Latest Plugin Version'                       => $row['latest_version'],
				'Compatible With WordPress Version'           => $row['tested_wp'],
				'Supported PHP Version'                       => str_replace(',', ' ', $row['php_supported']),
				$is_network ? 'Plugin Network Status' : 'Plugin Status' => $row['status'],
				'Updateable With Latest Version of WordPress' => $row['upgradeable'],
				'Issues Resolved in Last Two Months'          => "'" . str_replace(':','', $row['issues_ratio']),
			];
		}

		echo '</tbody></table></div>';

		// Note
		echo '<br><b>'.esc_html__('Note: "No Data" plugins were not found on WordPress.org (custom or licensed). Check with the author/vendor for their latest version.', 'pcc').'</b>';
		echo '<br><br><b>'.esc_html__('After reviewing the table above, update WordPress accordingly.', 'pcc').'</b>';

		// Expose export data (keeps your export JS compatible)
		printf('<script>window.PCCExportData=%s;</script>', wp_json_encode($export_rows));
	}

	private function stat_card($icon_url, $title, $value_html) {
		echo '<div class="stat-card">
			<div class="stat-icon"><img src="'.esc_url($icon_url).'" alt="'.esc_attr($title).' Icon" id="iconclass"></div>
			<div class="stat-content">
				<h3>'.esc_html($title).'</h3>
				<p>'.esc_html($value_html).'</p>
			</div>
		</div>';
	}

	/* -----------------
	 * System Info page
	 * ----------------- */
	public function render_sysinfo() {
		$cap = is_multisite() ? self::CAP_NETWORK : self::CAP_SINGLE;
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

	/* -----------------------
	 * Data builders (cached)
	 * ----------------------- */
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

		// Active map
		if ( $is_network ) {
			$active_map = (array) get_site_option('active_sitewide_plugins', []);
		} else {
			$active_map = array_fill_keys( (array) get_option('active_plugins', []), true );
		}

		$rows = [];

		foreach ( $plugins as $file => $plug ) {
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

			$status = ($is_network)
				? ( isset($active_map[$file]) ? 'Activated' : 'Deactivated' )
				: ( isset($active_map[$file]) ? 'Activated' : 'Deactivated' );

			$upgradeable = 'No Data';
			if ( 'No Data' !== $tested_wp ) {
				if ( version_compare($tested_wp, $wp_latest, '>=') ) $upgradeable = 'Yes';
				else $upgradeable = ( $tested_wp === '6.2.0' ) ? 'Yes' : 'No'; // kept your exception
			}

			$issues_ratio = 'No Data';
			if ($support_total === ':0' && $support_res === ':0') {
				$issues_ratio = 'There Are No Issues';
			} elseif ($support_total !== 'No Data' && $support_res !== 'No Data') {
				$issues_ratio = str_replace(':','', $support_res) . '/' . str_replace(':','', $support_total);
			}

			$php_supported = 'No Data';
			if ( $slug && 'No Data' !== $latest_ver ) {
				$php_supported = $this->fetch_wptide_php_compat_list($slug, $latest_ver);
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

	/* -------------------
	 * Helpers (fetching)
	 * ------------------- */
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
			error_log('PCC: WP core version-check failed: ' . $res->get_error_message());
			return get_bloginfo('version');
		}
		$body = wp_remote_retrieve_body($res);
		$obj  = json_decode($body);
		return isset($obj->offers[0]->version) ? $obj->offers[0]->version : get_bloginfo('version');
	}

	private function fetch_wporg_plugin_info($slug) {
		$url = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode($slug);
		$res = wp_remote_get($url, [ 'timeout' => 20 ]);
		if ( is_wp_error($res) ) {
			error_log('PCC: Plugin info API failed for '.$slug.': '.$res->get_error_message());
			return [];
		}
		$body = wp_remote_retrieve_body($res);
		$data = json_decode($body, true);
		if ( isset($data['error']) ) return [];
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
			error_log('PCC: WPTide request failed for '.$slug.': '.$res->get_error_message());
			return 'No Data';
		}
		$body = wp_remote_retrieve_body($res);
		$data = json_decode($body, true);
		if ( ! is_array($data) || isset($data['error']) ) return 'No Data';

		$status = $data['status'] ?? null;
		if ( (string) $status === '404' ) return 'No Data';

		$compat_arr = $data['reports']['phpcs_phpcompatibilitywp']['report']['compatible'] ?? null;
		if ( ! is_array($compat_arr) || empty($compat_arr) ) return 'No Data';

		return implode(', ', $compat_arr);
	}
}

new PCC();

endif;
