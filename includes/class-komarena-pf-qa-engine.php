<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_QA_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function run($product_id, $manifest = array()) {
		$product = wc_get_product($product_id);
		if (!$product) {
			return array(
				'status'           => 'failed',
				'ready_to_publish' => false,
				'checks'           => array(),
				'missing'          => array('Produkt neexistuje.'),
				'warnings'         => array(),
			);
		}

		$description = (string) $product->get_description();
		$gallery_ids = $product->get_gallery_image_ids();
		$image_count = (has_post_thumbnail($product_id) ? 1 : 0) + count($gallery_ids);
		$price = (string) $product->get_price();
		$ean = get_post_meta($product_id, '_komarena_ean', true);
		$categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
		$attributes = $product->get_attributes();
		$safety = $manifest['safety'] ?? array();

		$required_sections = array(
			'Úvodný technický blok',
			'3 KPI karty',
			'Prečo si vybrať',
			'Pre koho je určený',
			'Praktický príklad použitia',
			'Ako to spolu funguje',
			'Ako produkt využiť v projekte',
			'3D tlačený doplnok k produktu',
			'Technické parametre',
			'Kompatibilita',
			'Typická kombinácia',
			'Obsah balenia',
			'Bezpečnostné odporúčanie',
			'Záruka a vrátenie',
			'Poznámka ku klonu / variantom',
			'Súvisiace produkty a odporúčané doplnky',
		);

		$checks = array(
			'four_images_exist'       => $image_count >= 4,
			'featured_image_exists'   => has_post_thumbnail($product_id),
			'gallery_exists'          => count($gallery_ids) >= 3,
			'sku_exists'              => '' !== (string) $product->get_sku(),
			'ean_exists'              => '' !== (string) $ean && 0 === strpos((string) $ean, '2998'),
			'price_ends_99'           => $this->price_ends_99($price),
			'valid_categories'        => !is_wp_error($categories) && !empty($categories),
			'required_sections_exist' => $this->sections_exist($description, $required_sections),
			'internal_links_clickable'=> (bool) preg_match('/<a\s+[^>]*href=["\'][^"\']+["\']/i', $description),
			'images_in_description'   => substr_count(strtolower($description), '<img') >= 4,
			'safety_block_exists'     => $this->contains($description, 'Bezpečnostné odporúčanie'),
			'warranty_block_exists'   => $this->contains($description, 'Záruka a vrátenie'),
			'yoast_title_exists'      => '' !== (string) get_post_meta($product_id, '_yoast_wpseo_title', true),
			'yoast_meta_exists'       => '' !== (string) get_post_meta($product_id, '_yoast_wpseo_metadesc', true),
			'technical_attributes'     => !$this->plugin->settings->get('auto_apply_attributes', 1) || !empty($attributes),
		);

		if (!empty($safety['risk']) && 'mains_voltage' === $safety['risk']) {
			$checks['high_voltage_safety'] = $this->contains($description, 'odborná montáž') || $this->contains($description, 'odborna montaz');
		}

		if ($this->plugin->settings->get('strict_source_verification', 1)) {
			$checks['source_verification'] = $this->source_verification_passes($manifest);
			$checks['image_verification']  = empty($manifest['image_mode']) || 'placeholder_generated_for_review' !== $manifest['image_mode'];
			$checks['image_provenance_exists'] = $this->image_provenance_passes($manifest);
			$checks['image_usage_allowed'] = $this->image_usage_passes($manifest);
			$checks['image_visual_clean'] = $this->image_visual_clean_passes($manifest);
			$checks['image_ai_check'] = $this->image_ai_check_passes($manifest);
			$checks['exact_image_match'] = $this->exact_image_match_passes($manifest);
		}
		if ($this->plugin->settings->get('require_verified_product_facts', 1)) {
			$checks['facts_verified'] = $this->facts_verified_passes($manifest);
		}
		if ($this->plugin->settings->get('require_real_product_images', 1)) {
			$checks['real_product_images'] = $this->real_product_images_pass($manifest);
		}

		$missing = array();
		foreach ($checks as $key => $passed) {
			if (!$passed) {
				$missing[] = $this->human_check_name($key);
			}
		}

		$ready = empty($missing);
		$report = array(
			'status'           => $ready ? 'ready' : 'needs_review',
			'ready_to_publish' => $ready,
			'checks'           => $checks,
			'missing'          => $missing,
			'warnings'         => $manifest['warnings'] ?? array(),
			'checked_at'       => current_time('mysql'),
		);

		update_post_meta($product_id, '_komarena_pf_qa_report', wp_json_encode($report));
		$this->plugin->logger->log($ready ? 'info' : 'warning', 'Kontrola kvality dokončená.', 'kontrola kvality', $report, null, $product_id);

		return $report;
	}

	private function price_ends_99($price) {
		$price = number_format((float) $price, 2, '.', '');
		return '.99' === substr($price, -3);
	}

	private function sections_exist($description, $sections) {
		foreach ($sections as $section) {
			if (!$this->contains($description, $section)) {
				return false;
			}
		}

		return true;
	}

	private function source_verification_passes($manifest) {
		$min = (float) $this->plugin->settings->get('minimum_confidence', 0.65);
		$confidence = isset($manifest['confidence_score']) ? (float) $manifest['confidence_score'] : 0;
		if ($confidence < $min) {
			return false;
		}

		if (empty($manifest['source_urls'])) {
			return false;
		}

		$evidence = $manifest['source_evidence'] ?? array();
		if (!empty($evidence) && 'verified' !== ($evidence['status'] ?? '')) {
			return false;
		}

		foreach ((array) ($manifest['warnings'] ?? array()) as $warning) {
			if (false !== stripos($warning, 'overene produktove zdroje')) {
				return false;
			}
		}

		return true;
	}

	private function facts_verified_passes($manifest) {
		$fact_verification = $manifest['fact_verification'] ?? array();
		if (!is_array($fact_verification) || empty($fact_verification['verified'])) {
			return false;
		}

		if ('verified' !== sanitize_key($fact_verification['status'] ?? '')) {
			return false;
		}

		if (empty($fact_verification['verified_sources']) || !is_array($fact_verification['verified_sources'])) {
			return false;
		}

		if (!empty($fact_verification['is_clone_or_variant']) && empty($fact_verification['has_listing_source'])) {
			return false;
		}

		return true;
	}

	private function image_provenance_passes($manifest) {
		$provenance = $manifest['image_provenance'] ?? array();
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}

		foreach ($provenance as $item) {
			if (empty($item['mode']) || empty($item['attachment_id']) || empty($item['url']) || empty($item['source_type']) || empty($item['usage_permission']) || empty($item['watermark_status']) || empty($item['seller_logo_status']) || !array_key_exists('ai_generated', $item)) {
				return false;
			}
		}

		return true;
	}

	private function image_usage_passes($manifest) {
		$provenance = $manifest['image_provenance'] ?? array();
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}

		foreach ($provenance as $item) {
			$permission = sanitize_key($item['usage_permission'] ?? '');
			if (!in_array($permission, array('allowed', 'allowed_internal', 'own_photo', 'licensed', 'manufacturer_allowed'), true)) {
				return false;
			}
		}

		return true;
	}

	private function image_visual_clean_passes($manifest) {
		$provenance = $manifest['image_provenance'] ?? array();
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}

		foreach ($provenance as $item) {
			if ('clean' !== sanitize_key($item['watermark_status'] ?? '') || 'clean' !== sanitize_key($item['seller_logo_status'] ?? '')) {
				return false;
			}
		}

		return true;
	}

	private function image_ai_check_passes($manifest) {
		$provenance = $manifest['image_provenance'] ?? array();
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}

		foreach ($provenance as $item) {
			if (!empty($item['ai_generated']) || !empty($item['generated'])) {
				return false;
			}
		}

		return true;
	}

	private function exact_image_match_passes($manifest) {
		$provenance = $manifest['image_provenance'] ?? array();
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}

		foreach ($provenance as $item) {
			$source_type = sanitize_key($item['source_type'] ?? '');
			$permission = sanitize_key($item['usage_permission'] ?? '');
			$internal_approved = in_array($source_type, array('internal_komarena', 'internal_komarena_media'), true)
				&& 'own_photo' === $permission
				&& !empty($item['is_original_or_real'])
				&& !empty($item['is_final_eligible'])
				&& 'clean' === sanitize_key($item['watermark_status'] ?? '')
				&& 'clean' === sanitize_key($item['seller_logo_status'] ?? '');
			if (empty($item['exact_image_match']) && !$internal_approved) {
				return false;
			}
		}

		return true;
	}

	private function real_product_images_pass($manifest) {
		$mode = sanitize_key($manifest['image_mode'] ?? '');
		$accepted = array(
			'sideloaded_verified_source',
			'existing_preserved',
			'media_library_url_derived',
			'approved_original_media',
			'media_library_approved_original',
			'real_standardized',
			'real_partial',
		);

		if (!in_array($mode, $accepted, true)) {
			return false;
		}

		foreach ((array) ($manifest['warnings'] ?? array()) as $warning) {
			$warning = strtolower(remove_accents((string) $warning));
			if (false !== strpos($warning, 'placeholder') || false !== strpos($warning, 'fallback') || false !== strpos($warning, 'kontrolny nahlad') || false !== strpos($warning, 'nenasiel 4 realne')) {
				return false;
			}
		}

		$provenance = $manifest['image_provenance'] ?? array();
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}
		foreach ($provenance as $item) {
			if (empty($item['is_original_or_real']) || empty($item['is_final_eligible'])) {
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

	private function human_check_name($key) {
		$map = array(
			'four_images_exist'        => 'Chýbajú 4 obrázky.',
			'featured_image_exists'    => 'Chýba hlavný obrázok.',
			'gallery_exists'           => 'Chýba galéria.',
			'sku_exists'               => 'Chýba SKU.',
			'ean_exists'               => 'Chýba interný EAN so začiatkom 2998.',
			'price_ends_99'            => 'Cena nekončí na .99.',
			'valid_categories'         => 'Chýbajú platné kategórie.',
			'required_sections_exist'  => 'Popis nemá všetky povinné sekcie.',
			'internal_links_clickable' => 'Chýbajú klikateľné interné odkazy.',
			'images_in_description'    => 'Obrázky nie sú vložené aj v popise.',
			'safety_block_exists'      => 'Chýba bezpečnostný blok.',
			'warranty_block_exists'    => 'Chýba záruka a vrátenie.',
			'yoast_title_exists'       => 'Chýba titulok pre Yoast SEO.',
			'yoast_meta_exists'        => 'Chýba meta popis pre Yoast SEO.',
			'technical_attributes'      => 'Chýbajú technické atribúty WooCommerce.',
			'high_voltage_safety'       => 'Pri rizikovom produkte chýba explicitné upozornenie na odbornú montáž.',
			'source_verification'      => 'Chýba overenie zdrojov alebo dostatočná istota.',
			'facts_verified'           => 'Technické fakty v popise nie sú 100 % naviazané na overené zdroje.',
			'image_verification'       => 'Obrázky sú iba kontrolné náhľady.',
			'image_provenance_exists'  => 'Chýba kompletný pôvod obrázkov: zdroj, typ zdroja, kontrola vodoznaku/loga, príznak AI a práva použitia.',
			'image_usage_allowed'      => 'Chýba potvrdené právo použiť obrázky vo finálnom produkte.',
			'image_visual_clean'       => 'Obrázky nemajú potvrdený čistý stav bez vodoznaku a cudzieho predajného loga.',
			'image_ai_check'           => 'Obrázky nesmú byť AI generované ani dočasné kontrolné náhľady.',
			'exact_image_match'        => 'Obrázky nemajú potvrdenú presnú zhodu s konkrétnym modelom produktu.',
			'real_product_images'      => 'Chýbajú originálne/reálne produktové obrázky bez AI, dočasného náhľadu alebo kreslenej náhrady.',
		);

		return $map[$key] ?? $key;
	}
}
