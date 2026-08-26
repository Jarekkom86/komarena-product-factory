<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Logger {
	private $run_id = '';

	public function new_run_id($prefix = 'kpf') {
		$this->run_id = sanitize_key($prefix . '-' . gmdate('YmdHis') . '-' . wp_generate_password(6, false, false));
		return $this->run_id;
	}

	public function run_id() {
		if ('' === $this->run_id) {
			$this->new_run_id();
		}

		return $this->run_id;
	}

	public function log($level, $message, $context = 'general', $data = array(), $queue_id = null, $product_id = null) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_logs';

		$wpdb->insert(
			$table,
			array(
				'run_id'     => $this->run_id(),
				'queue_id'   => $queue_id ? absint($queue_id) : null,
				'product_id' => $product_id ? absint($product_id) : null,
				'level'      => sanitize_key($level),
				'context'    => sanitize_key($context),
				'message'    => sanitize_textarea_field($message),
				'data'       => wp_json_encode($data),
				'created_at' => current_time('mysql'),
			),
			array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
		);
	}

	public function recent($limit = 50) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_logs';
		$limit = max(1, min(200, absint($limit)));

		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
	}
}
