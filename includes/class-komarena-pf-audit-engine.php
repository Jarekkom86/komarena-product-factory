<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Audit_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function audit_products($limit = 100) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.');
		}

		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => max(1, min(500, absint($limit))),
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		));

		$summary = array(
			'checked'      => 0,
			'with_issues'  => 0,
			'issue_counts' => array(),
		);

		foreach ($query->posts as $product_id) {
			$issues = $this->inspect_product($product_id);
			update_post_meta($product_id, '_komarena_pf_audit_issues', wp_json_encode($issues));
			$summary['checked']++;
			if (!empty($issues)) {
				$summary['with_issues']++;
				foreach ($issues as $issue) {
					if (!isset($summary['issue_counts'][$issue])) {
						$summary['issue_counts'][$issue] = 0;
					}
					$summary['issue_counts'][$issue]++;
				}
			}
		}

		$this->plugin->logger->log('info', 'Audit dokončený.', 'audit', $summary);
		return $summary;
	}

	public function inspect_product($product_id) {
		$product = wc_get_product($product_id);
		if (!$product) {
			return array('Produkt sa nepodarilo nacitat.');
		}

		$issues = array();
		$description = (string) $product->get_description();
		$gallery = $product->get_gallery_image_ids();
		$image_count = (has_post_thumbnail($product_id) ? 1 : 0) + count($gallery);
		$ean = get_post_meta($product_id, '_komarena_ean', true);
		if (!$ean) {
			$ean = get_post_meta($product_id, '_global_unique_id', true);
		}

		if (!has_post_thumbnail($product_id)) {
			$issues[] = 'chýba hlavný obrázok';
		}
		if (empty($gallery)) {
			$issues[] = 'chýba galéria';
		}
		if ($image_count < 4) {
			$issues[] = 'menej ako 4 obrázky';
		}
		if (!$product->get_sku()) {
			$issues[] = 'chýba SKU';
		}
		if (!$ean) {
			$issues[] = 'chýba EAN';
		}
		if (!get_post_meta($product_id, '_yoast_wpseo_title', true) || !get_post_meta($product_id, '_yoast_wpseo_metadesc', true)) {
			$issues[] = 'chýba Yoast SEO';
		}
		if (!$this->contains($description, 'Bezpečnostné odporúčanie')) {
			$issues[] = 'chýba bezpečnostný blok';
		}
		if (!$this->contains($description, 'Záruka a vrátenie')) {
			$issues[] = 'chýba záruka';
		}
		if (!$this->contains($description, 'Ako produkt využiť v projekte')) {
			$issues[] = 'chýba projektový blok použitia produktu';
		}
		if (!$this->contains($description, '3D tlačený doplnok k produktu')) {
			$issues[] = 'chýba 3D tlačový blok produktu';
		}
		if (!preg_match('/<a\s+[^>]*href=["\'][^"\']+["\']/i', $description)) {
			$issues[] = 'chýbajú interné odkazy';
		}
		if (false === strpos($description, 'data-komarena-standard="1.1"')) {
			$issues[] = 'nejednotný dizajn';
		}
		$terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
		if (is_wp_error($terms) || empty($terms)) {
			$issues[] = 'zlá kategória';
		}
		if ('.99' !== substr(number_format((float) $product->get_price(), 2, '.', ''), -3)) {
			$issues[] = 'cena nekončí .99';
		}
		if (!$this->looks_like_komarena_description($description)) {
			$issues[] = 'popis nie je podľa KomArena štandardu';
		}

		return array_values(array_unique($issues));
	}

	public function dashboard_counts() {
		if (!$this->plugin->has_woocommerce()) {
			return array(
				'products_without_seo'       => 0,
				'products_without_4_images'  => 0,
				'products_with_old_design'   => 0,
			);
		}

		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => 500,
			'fields'         => 'ids',
		));

		$out = array(
			'products_without_seo'       => 0,
			'products_without_4_images'  => 0,
			'products_with_old_design'   => 0,
		);

		foreach ($query->posts as $product_id) {
			$product = wc_get_product($product_id);
			if (!$product) {
				continue;
			}

			if (!get_post_meta($product_id, '_yoast_wpseo_title', true) || !get_post_meta($product_id, '_yoast_wpseo_metadesc', true)) {
				$out['products_without_seo']++;
			}
			$image_count = (has_post_thumbnail($product_id) ? 1 : 0) + count($product->get_gallery_image_ids());
			if ($image_count < 4) {
				$out['products_without_4_images']++;
			}
			if (false === strpos((string) $product->get_description(), 'data-komarena-standard="1.1"')) {
				$out['products_with_old_design']++;
			}
		}

		return $out;
	}

	private function looks_like_komarena_description($description) {
		$needles = array(
			'Úvodný technický blok',
			'Technické parametre',
			'Ako produkt využiť v projekte',
			'3D tlačený doplnok k produktu',
			'Bezpečnostné odporúčanie',
			'Záruka a vrátenie',
			'Súvisiace produkty a odporúčané doplnky',
		);

		foreach ($needles as $needle) {
			if (!$this->contains($description, $needle)) {
				return false;
			}
		}

		return true;
	}

	private function contains($haystack, $needle) {
		if (function_exists('mb_stripos')) {
			return false !== mb_stripos($haystack, $needle);
		}

		return false !== stripos($haystack, $needle);
	}
}
