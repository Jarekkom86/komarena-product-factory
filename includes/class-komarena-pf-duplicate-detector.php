<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Duplicate_Detector {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function find($product_name, $sku = '', $ean = '') {
		$sku = sanitize_text_field($sku);
		if ($sku && function_exists('wc_get_product_id_by_sku')) {
			$product_id = wc_get_product_id_by_sku($sku);
			if ($product_id) {
				return array('product_id' => (int) $product_id, 'reason' => 'sku', 'confidence' => 1.0);
			}
		}

		$ean = preg_replace('/\D+/', '', (string) $ean);
		if ($ean) {
			$by_ean = $this->find_by_ean($ean);
			if ($by_ean) {
				return array('product_id' => (int) $by_ean, 'reason' => 'ean', 'confidence' => 1.0);
			}
		}

		$slug = sanitize_title($product_name);
		$page = get_page_by_path($slug, OBJECT, 'product');
		if ($page) {
			return array('product_id' => (int) $page->ID, 'reason' => 'slug', 'confidence' => 0.94);
		}

		$candidate = $this->find_by_title_similarity($product_name);
		if ($candidate) {
			return $candidate;
		}

		return null;
	}

	private function find_by_ean($ean) {
		global $wpdb;

		return $wpdb->get_var($wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key IN ('_komarena_ean', '_global_unique_id', '_wpm_gtin_code') LIMIT 1",
			$ean
		));
	}

	private function find_by_title_similarity($product_name) {
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			's'              => sanitize_text_field($product_name),
			'posts_per_page' => 8,
			'fields'         => 'ids',
		));

		$needle = strtolower(remove_accents((string) $product_name));
		foreach ($query->posts as $product_id) {
			$title = strtolower(remove_accents(get_the_title($product_id)));
			similar_text($needle, $title, $percent);
			if ($percent >= 88) {
				return array('product_id' => (int) $product_id, 'reason' => 'title_similarity', 'confidence' => round($percent / 100, 2));
			}
		}

		return null;
	}
}
