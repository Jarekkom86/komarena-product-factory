<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_SKU_EAN_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function sku($product_name, $preferred = '') {
		if ($preferred) {
			return sanitize_text_field($preferred);
		}

		$abbr = $this->abbreviation($product_name);

		for ($i = 0; $i < 30; $i++) {
			$sku = sprintf('KOM-%s-%04d', $abbr, wp_rand(1, 9999));
			if (!function_exists('wc_get_product_id_by_sku') || !wc_get_product_id_by_sku($sku)) {
				return $sku;
			}
		}

		return sprintf('KOM-%s-%06d', $abbr, wp_rand(100000, 999999));
	}

	public function ean($preferred = '') {
		if ($preferred && $this->starts_with_2998($preferred) && !$this->ean_exists($preferred)) {
			return preg_replace('/\D+/', '', $preferred);
		}

		for ($i = 0; $i < 100; $i++) {
			$base = '2998' . str_pad((string) wp_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
			$ean  = $base . $this->ean13_checksum($base);
			if (!$this->ean_exists($ean)) {
				return $ean;
			}
		}

		$base = '2998' . substr((string) time(), -8);
		return $base . $this->ean13_checksum($base);
	}

	private function abbreviation($product_name) {
		$name = strtoupper(remove_accents($product_name));
		preg_match_all('/[A-Z0-9]+/', $name, $matches);
		$tokens = $matches[0] ?? array();

		$skip = array('MODUL', 'SENZOR', 'DOSKA', 'PRE', 'S', 'A', 'NA');
		$abbr = '';
		foreach ($tokens as $token) {
			if (in_array($token, $skip, true)) {
				continue;
			}
			$abbr .= preg_match('/\d/', $token) ? $token : substr($token, 0, 3);
			if (strlen($abbr) >= 8) {
				break;
			}
		}

		$abbr = preg_replace('/[^A-Z0-9]/', '', $abbr);
		return $abbr ? substr($abbr, 0, 8) : 'PROD';
	}

	private function starts_with_2998($ean) {
		return 0 === strpos(preg_replace('/\D+/', '', $ean), '2998');
	}

	private function ean_exists($ean) {
		global $wpdb;

		$ean = preg_replace('/\D+/', '', $ean);
		if ('' === $ean) {
			return false;
		}

		$found = $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key IN ('_komarena_ean', '_global_unique_id', '_wpm_gtin_code') LIMIT 1",
			$ean
		));

		return !empty($found);
	}

	private function ean13_checksum($base12) {
		$sum = 0;
		for ($i = 0; $i < 12; $i++) {
			$digit = (int) substr($base12, $i, 1);
			$sum += ($i % 2) ? $digit * 3 : $digit;
		}

		return (string) ((10 - ($sum % 10)) % 10);
	}
}
