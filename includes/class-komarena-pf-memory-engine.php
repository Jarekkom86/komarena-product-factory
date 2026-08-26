<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Memory_Engine {
	private $plugin;

	public function __construct($plugin = null) {
		$this->plugin = $plugin;
	}

	public function upsert($memory_key, $memory_type, $title, $data, $confidence = 0.5, $source_url = '') {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_memory';
		$now   = current_time('mysql');
		$key   = sanitize_key($memory_key);

		if ('' === $key) {
			return false;
		}

		$row = array(
			'memory_key'  => $key,
			'memory_type' => sanitize_key($memory_type),
			'title'       => sanitize_text_field($title),
			'data'        => wp_json_encode($data),
			'confidence'  => max(0, min(1, (float) $confidence)),
			'source_url'  => esc_url_raw($source_url),
			'updated_at'  => $now,
		);

		$exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE memory_key = %s", $key));
		if ($exists) {
			return $wpdb->update($table, $row, array('id' => (int) $exists));
		}

		$row['created_at'] = $now;
		return $wpdb->insert($table, $row);
	}

	public function match_product($product_name, $limit = 5) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_memory';
		$name  = strtolower(remove_accents((string) $product_name));
		$rows  = $wpdb->get_results($wpdb->prepare(
			"SELECT * FROM {$table} WHERE memory_type IN ('product_profile', 'chip_profile', 'safety_profile') ORDER BY confidence DESC, updated_at DESC LIMIT %d",
			100
		), ARRAY_A);

		$matches = array();
		foreach ($rows as $row) {
			$haystack = strtolower(remove_accents($row['memory_key'] . ' ' . $row['title']));
			$score = 0;
			foreach (preg_split('/[^a-z0-9]+/', $haystack) as $token) {
				if (strlen($token) < 3) {
					continue;
				}
				if (false !== strpos($name, $token)) {
					$score += strlen($token) >= 5 ? 2 : 1;
				}
			}

			if ($score > 0) {
				$row['match_score'] = $score + (float) $row['confidence'];
				$row['data_decoded'] = json_decode((string) $row['data'], true);
				if (!is_array($row['data_decoded'])) {
					$row['data_decoded'] = array();
				}
				$matches[] = $row;
			}
		}

		usort($matches, function($a, $b) {
			return $b['match_score'] <=> $a['match_score'];
		});

		return array_slice($matches, 0, max(1, absint($limit)));
	}

	public function learn_from_product($product_id, $research, $manifest) {
		$key = sanitize_title($research['normalized_name'] ?? get_the_title($product_id));
		if (!$key) {
			return false;
		}

		$data = array(
			'model'            => $research['model'] ?? '',
			'chip'             => $research['chip'] ?? '',
			'ports'            => $research['ports'] ?? '',
			'connectors'       => $research['connectors'] ?? '',
			'pin_layout'       => $research['pin_layout'] ?? '',
			'categories'       => $research['categories'] ?? array(),
			'specs'            => $research['specs'] ?? array(),
			'compatibility'    => $research['compatibility'] ?? array(),
			'package_contents' => $research['package_contents'] ?? array(),
			'safety'           => $research['safety'] ?? array(),
			'product_id'       => (int) $product_id,
			'last_manifest'    => $manifest,
		);

		return $this->upsert($key, 'product_profile', $research['normalized_name'] ?? get_the_title($product_id), $data, $research['confidence_score'] ?? 0.6);
	}

	public function recent($limit = 30) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_memory';
		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d", max(1, min(200, absint($limit)))), ARRAY_A);
	}

	public function seed_defaults() {
		$profiles = array(
			'esp32' => array('type' => 'chip_profile', 'title' => 'ESP32', 'data' => array('chip' => 'ESP32', 'logic' => '3.3 V', 'compatibility' => array('ESPHome', 'Arduino IDE', 'Home Assistant'))),
			'esp8266' => array('type' => 'chip_profile', 'title' => 'ESP8266', 'data' => array('chip' => 'ESP8266', 'logic' => '3.3 V', 'compatibility' => array('ESPHome', 'Arduino IDE'))),
			'bme280' => array('type' => 'product_profile', 'title' => 'BME280 senzor', 'data' => array('chip' => 'Bosch BME280', 'specs' => array('Meranie' => 'Teplota, vlhkost, tlak'))),
			'rele-230v' => array('type' => 'safety_profile', 'title' => 'Rele a AC zataze', 'data' => array('risk' => 'mains_voltage', 'required_warning' => 'odborna montaz')),
			'hlk-pm' => array('type' => 'safety_profile', 'title' => 'HLK-PM AC/DC napajanie', 'data' => array('risk' => 'mains_voltage', 'required_warning' => 'odborna montaz')),
		);

		foreach ($profiles as $key => $profile) {
			$this->upsert($key, $profile['type'], $profile['title'], $profile['data'], 0.85);
		}
	}
}
