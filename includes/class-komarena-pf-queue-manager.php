<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Queue_Manager {
	private $plugin;
	private $statuses = array(
		'waiting',
		'researching',
		'images',
		'content',
		'uploading',
		'creating_product',
		'qa',
		'ready',
		'needs_review',
		'failed',
	);

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function enqueue_from_text($raw_text) {
		if (!is_scalar($raw_text)) {
			return 0;
		}

		$created = 0;

		foreach (preg_split('/\r\n|\r|\n/', (string) $raw_text) as $line) {
			$line = trim(wp_unslash($line));
			if ('' === $line) {
				continue;
			}

			$parts = array_map('trim', explode('|', $line));
			$name  = sanitize_text_field(array_shift($parts));
			if ('' === $name) {
				continue;
			}

			$source_urls = array();
			$image_sources = array();
			foreach ($parts as $url) {
				$url = esc_url_raw($url);
				if ($url) {
					if ($this->looks_like_image_url($url)) {
						$image_sources[] = $url;
					} else {
						$source_urls[] = $url;
					}
				}
			}

			$this->insert($name, array('source_urls' => $source_urls, 'image_sources' => $image_sources));
			$created++;
		}

		if ($created > 0 && $this->plugin->settings->get('agent_autopilot', 1)) {
			$this->schedule_soon();
		}

		return $created;
	}

	public function insert($product_name, $payload = array()) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_queue';
		$now   = current_time('mysql');

		$wpdb->insert(
			$table,
			array(
				'product_name' => sanitize_text_field($product_name),
				'status'       => 'waiting',
				'payload'      => wp_json_encode($payload),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array('%s', '%s', '%s', '%s', '%s')
		);

		return (int) $wpdb->insert_id;
	}

	public function counts() {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_queue';
		$rows  = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A);
		$out   = array_fill_keys($this->statuses, 0);

		foreach ($rows as $row) {
			$out[$row['status']] = (int) $row['total'];
		}

		return $out;
	}

	public function items($limit = 50) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_queue';
		$limit = max(1, min(200, absint($limit)));

		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
	}

	public function get($id) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_queue';
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($id)), ARRAY_A);
	}

	public function update($id, $fields) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_queue';
		$fields['updated_at'] = current_time('mysql');
		return $wpdb->update($table, $fields, array('id' => absint($id)));
	}

	public function mark_status($id, $status, $extra = array()) {
		if (!in_array($status, $this->statuses, true)) {
			$status = 'failed';
		}

		$fields = array_merge(array('status' => $status), $extra);
		$this->update($id, $fields);
		$this->plugin->logger->log('info', 'Stav fronty zmenený na ' . $this->visible_status($status) . '.', 'fronta', $fields, $id);
	}

	public function next_waiting() {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_queue';
		return $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'waiting' ORDER BY id ASC LIMIT 1", ARRAY_A);
	}

	public function run_next($limit = 1) {
		$results = array();
		$limit   = max(1, min(25, absint($limit)));

		for ($i = 0; $i < $limit; $i++) {
			$item = $this->next_waiting();
			if (!$item) {
				break;
			}

			$payload = json_decode($item['payload'], true);
			if (!is_array($payload)) {
				$payload = array();
			}

			$queue_id = (int) $item['id'];
			$this->plugin->logger->new_run_id('queue-' . $queue_id);
			$this->mark_status($queue_id, 'researching', array('attempts' => ((int) $item['attempts']) + 1));

			$result = $this->plugin->creator->create_from_name($item['product_name'], array(
				'queue_id'        => $queue_id,
				'source_urls'     => $payload['source_urls'] ?? array(),
				'supplier_data'   => $payload['supplier_data'] ?? array(),
				'image_sources'   => $payload['image_sources'] ?? array(),
				'sku'             => $payload['sku'] ?? '',
				'ean'             => $payload['ean'] ?? '',
				'status_callback' => function($status) use ($queue_id) {
					$this->mark_status($queue_id, $status);
				},
			));

			if (is_wp_error($result)) {
				$this->mark_status($queue_id, 'failed', array('last_error' => $result->get_error_message()));
				$results[] = $result;
				continue;
			}

			$status = empty($result['ready_to_publish']) ? 'needs_review' : 'ready';
			$this->mark_status($queue_id, $status, array(
				'product_id'  => (int) $result['product_id'],
				'last_error'  => '',
			));
			$results[] = $result;
		}

		return $results;
	}

	public function run_scheduled() {
		if (!$this->plugin->settings->get('agent_autopilot', 1)) {
			return array();
		}

		if (get_transient('komarena_pf_queue_lock')) {
			$this->plugin->logger->log('info', 'Autopilot fronty bol preskočený, pretože je aktívny zámok fronty.', 'fronta');
			return array();
		}

		set_transient('komarena_pf_queue_lock', 1, 4 * MINUTE_IN_SECONDS);

		try {
			$limit = (int) $this->plugin->settings->get('queue_batch_size', 3);
			$this->plugin->logger->new_run_id('autopilot');
			$this->plugin->logger->log('info', 'Spracovanie fronty autopilotom spustené.', 'fronta', array('limit' => $limit));
			$results = $this->run_next($limit);
			$this->plugin->logger->log('info', 'Spracovanie fronty autopilotom dokončené.', 'fronta', array('processed' => count($results)));
		} catch (Exception $e) {
			$this->plugin->logger->log('error', $e->getMessage(), 'fronta');
			$results = array();
		}

		delete_transient('komarena_pf_queue_lock');
		return $results;
	}

	public function schedule_soon() {
		if (!empty($this->plugin->agent) && method_exists($this->plugin->agent, 'schedule_soon')) {
			$this->plugin->agent->schedule_soon();
			return;
		}

		if (!wp_next_scheduled('komarena_pf_process_queue')) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'komarena_pf_every_five_minutes', 'komarena_pf_process_queue');
		}

		if (!wp_next_scheduled('komarena_pf_process_queue_once')) {
			wp_schedule_single_event(time() + 15, 'komarena_pf_process_queue_once');
		}
	}

	private function looks_like_image_url($url) {
		return (bool) preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', (string) $url);
	}

	private function visible_status($status) {
		$labels = array(
			'waiting'          => 'čaká',
			'researching'      => 'overuje zdroje',
			'images'           => 'spracúva obrázky',
			'content'          => 'tvorí obsah',
			'uploading'        => 'nahráva',
			'creating_product' => 'vytvára produkt',
			'qa'               => 'kontrola kvality',
			'ready'            => 'pripravené',
			'needs_review'     => 'na kontrolu',
			'failed'           => 'zlyhalo',
		);

		$key = sanitize_key($status);
		return $labels[$key] ?? (string) $status;
	}
}
