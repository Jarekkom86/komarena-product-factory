<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_Product_Factory_Activator {
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$queue_table     = $wpdb->prefix . 'komarena_pf_queue';
		$logs_table      = $wpdb->prefix . 'komarena_pf_logs';
		$memory_table    = $wpdb->prefix . 'komarena_pf_memory';
		$tasks_table     = $wpdb->prefix . 'komarena_pf_tasks';

		$sql_queue = "CREATE TABLE {$queue_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_name text NOT NULL,
			product_id bigint(20) unsigned DEFAULT NULL,
			status varchar(40) NOT NULL DEFAULT 'waiting',
			payload longtext NULL,
			last_error text NULL,
			attempts int(11) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY product_id (product_id),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		$sql_logs = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(64) NOT NULL,
			queue_id bigint(20) unsigned DEFAULT NULL,
			product_id bigint(20) unsigned DEFAULT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			context varchar(80) NOT NULL DEFAULT 'general',
			message text NOT NULL,
			data longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY queue_id (queue_id),
			KEY product_id (product_id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		$sql_memory = "CREATE TABLE {$memory_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			memory_key varchar(191) NOT NULL,
			memory_type varchar(40) NOT NULL DEFAULT 'product_profile',
			title text NOT NULL,
			data longtext NULL,
			confidence decimal(4,2) NOT NULL DEFAULT 0.50,
			source_url text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY memory_key (memory_key),
			KEY memory_type (memory_type),
			KEY confidence (confidence),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		$sql_tasks = "CREATE TABLE {$tasks_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			task_type varchar(80) NOT NULL,
			title text NOT NULL,
			status varchar(40) NOT NULL DEFAULT 'waiting',
			priority int(11) unsigned NOT NULL DEFAULT 10,
			payload longtext NULL,
			result longtext NULL,
			last_error text NULL,
			attempts int(11) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			scheduled_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY task_type (task_type),
			KEY status (status),
			KEY priority (priority),
			KEY scheduled_at (scheduled_at),
			KEY updated_at (updated_at)
		) {$charset_collate};";

		dbDelta($sql_queue);
		dbDelta($sql_logs);
		dbDelta($sql_memory);
		dbDelta($sql_tasks);

		update_option('komarena_pf_version', KOMARENA_PF_VERSION);
		add_option('komarena_pf_settings', KomArena_PF_Settings::defaults());
		$settings = get_option('komarena_pf_settings', array());
		if (!is_array($settings)) {
			$settings = array();
		}
		$settings = wp_parse_args($settings, KomArena_PF_Settings::defaults());
		$production_mode = !empty($settings['production_mode']);
		$settings['allow_illustrated_image_fallback'] = 0;
		$settings['allow_placeholder_images'] = 0;
		$settings['require_real_product_images'] = 1;
		if (!$production_mode) {
			$settings['auto_publish'] = 0;
			$settings['agent_bulk_rebuild_lock'] = 1;
			$settings['agent_auto_repair_audit'] = 0;
			$settings['agent_repair_live_products'] = 0;
		}
		update_option('komarena_pf_settings', $settings, false);

		if (class_exists('KomArena_PF_Memory_Engine')) {
			$memory = new KomArena_PF_Memory_Engine(null);
			$memory->seed_defaults();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook('komarena_pf_process_queue');
		wp_clear_scheduled_hook('komarena_pf_process_queue_once');
		wp_clear_scheduled_hook('komarena_pf_agent_tick');
		wp_clear_scheduled_hook('komarena_pf_agent_tick_once');
		wp_clear_scheduled_hook('komarena_pf_task_tick');
		wp_clear_scheduled_hook('komarena_pf_task_tick_once');
	}
}
