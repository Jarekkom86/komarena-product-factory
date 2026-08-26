<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Product_Creator {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function create_from_name($product_name, $options = array()) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.');
		}

		$name = sanitize_text_field($product_name);
		if ('' === $name) {
			return new WP_Error('komarena_pf_empty_name', 'Nazov produktu je prazdny.');
		}

		$status = $this->status_callback($options);
		if (empty($options['queue_id'])) {
			$this->plugin->logger->new_run_id('product');
		}
		$this->plugin->logger->log('info', 'Tvorba produktu spustená.', 'vstup', array('product_name' => $name), $options['queue_id'] ?? null);

		try {
			$duplicate = $this->plugin->duplicates->find($name, $options['sku'] ?? '', $options['ean'] ?? '');
			if ($duplicate && 'reuse_review' === $this->plugin->settings->get('duplicate_strategy', 'reuse_review')) {
				$product_id = (int) $duplicate['product_id'];
				update_post_meta($product_id, '_komarena_pf_status', 'needs_review');
				update_post_meta($product_id, '_komarena_pf_duplicate_of', wp_json_encode($duplicate));
				update_post_meta($product_id, '_komarena_pf_review_notes', wp_json_encode(array(
					'type'       => 'duplicate_detected',
					'reason'     => $duplicate['reason'],
					'confidence' => $duplicate['confidence'],
					'input_name' => $name,
					'created_at' => current_time('mysql'),
				)));
				$this->plugin->logger->log('warning', 'Duplicate detected, existing product marked for review', 'duplicate', $duplicate, $options['queue_id'] ?? null, $product_id);

				return array(
					'product_id'        => $product_id,
					'ready_to_publish'  => false,
					'qa'                => array('status' => 'needs_review', 'missing' => array('Možná duplicita produktu.')),
					'manifest'          => array('duplicate' => $duplicate, 'agent_version' => KOMARENA_PF_VERSION),
				);
			}

			$status('researching');
			$research = $this->plugin->research->research($name, $options);
			$research['safety'] = $this->plugin->safety->analyze($research);
			$price    = $this->plugin->pricing->price($research);

			$status('creating_product');
			$product = new WC_Product_Simple();
			$product->set_name($research['normalized_name']);
			$product->set_slug($research['slug']);
			$product->set_status('draft');
			$product->set_catalog_visibility('visible');
			$product->set_regular_price($price['price']);
			$product->set_price($price['price']);
			$product->set_manage_stock(true);
			$product->set_stock_quantity((int) $this->plugin->settings->get('default_stock', 10));
			$product->set_stock_status('instock');
			$product->set_sku($this->plugin->sku_ean->sku($name, $options['sku'] ?? ''));
			$product_id = $product->save();

			$category_reasoning = $this->assign_categories($product_id, $research['categories']);
			$ean = $this->plugin->sku_ean->ean($options['ean'] ?? '');
			$this->set_ean_meta($product_id, $ean);

			$status('uploading');
			$status('images');
			$images = $this->plugin->images->prepare_images($product_id, $research, $research['slug']);

			$status('content');
			$short = $this->plugin->content->short_description($research);
			$long  = $this->plugin->content->long_description($research, $images, $price);

			$product = wc_get_product($product_id);
			$product->set_short_description($short);
			$product->set_description($long);
			$product->save();

			$this->set_yoast_meta($product_id, $research);
			if ($this->plugin->settings->get('auto_apply_attributes', 1)) {
				$this->plugin->attributes->apply($product_id, $research);
			}

			$warnings = array_values(array_unique(array_merge(
				(array) ($research['warnings'] ?? array()),
				(array) ($images['warnings'] ?? array())
			)));

			$manifest = array(
				'source_urls'        => $research['source_urls'],
				'source_evidence'    => $research['source_evidence'] ?? array(),
				'fact_verification'  => $research['fact_verification'] ?? array(),
				'verified_facts_policy' => $research['verified_facts_policy'] ?? '',
				'image_mode'         => $images['image_mode'],
				'image_provenance'   => $images['image_provenance'] ?? array(),
				'qa_status'          => 'pending',
				'ready_to_publish'   => false,
				'generated_at'       => current_time('mysql'),
				'agent_version'      => KOMARENA_PF_VERSION,
				'price_reasoning'    => $price['reasoning'],
				'category_reasoning' => $category_reasoning,
				'warnings'           => $warnings,
				'confidence_score'   => $research['confidence_score'],
				'supplier_data'      => $options['supplier_data'] ?? array(),
				'safety'             => $research['safety'],
			);
			$this->save_manifest_meta($product_id, $manifest);
			if (!empty($options['supplier_data'])) {
				update_post_meta($product_id, '_komarena_pf_supplier_data', wp_json_encode($options['supplier_data']));
			}

			$status('qa');
			$qa = $this->plugin->qa->run($product_id, $manifest);
			$manifest['qa_status']        = $qa['status'];
			$manifest['ready_to_publish'] = $qa['ready_to_publish'];
			$this->save_manifest_meta($product_id, $manifest);
			update_post_meta($product_id, '_komarena_pf_status', $qa['ready_to_publish'] ? 'ready_to_publish' : 'needs_review');
			update_post_meta($product_id, '_komarena_pf_ready_to_publish', $qa['ready_to_publish'] ? '1' : '0');

			if ($qa['ready_to_publish'] && empty($options['force_draft']) && $this->plugin->settings->get('auto_publish', 0)) {
				wp_update_post(array('ID' => $product_id, 'post_status' => 'publish'));
			}

			$this->plugin->logger->log('info', 'Tvorba produktu dokončená.', 'výstup', array(
				'product_id' => $product_id,
				'qa'         => $qa,
				'manifest'   => $manifest,
			), $options['queue_id'] ?? null, $product_id);
			$this->plugin->memory->learn_from_product($product_id, $research, $manifest);

			return array(
				'product_id'        => $product_id,
				'ready_to_publish'  => $qa['ready_to_publish'],
				'qa'                => $qa,
				'manifest'          => $manifest,
			);
		} catch (Exception $e) {
			$this->plugin->logger->log('error', $e->getMessage(), 'exception', array(
				'product_name' => $name,
				'trace'        => $e->getTraceAsString(),
			), $options['queue_id'] ?? null);

			return new WP_Error('komarena_pf_create_failed', $e->getMessage());
		}
	}

	public function assign_categories($product_id, $categories) {
		$term_ids = array();
		$reasoning = array();

		foreach ((array) $categories as $category) {
			$category = $this->normalize_category_name($category);
			if ('' === $category) {
				continue;
			}

			$term = term_exists($category, 'product_cat');
			if (!$term) {
				$term = wp_insert_term($category, 'product_cat');
			}

			if (!is_wp_error($term)) {
				$term_id = is_array($term) ? (int) $term['term_id'] : (int) $term;
				$term_ids[] = $term_id;
				$reasoning[] = sprintf('Priradena kategoria "%s" na zaklade typu produktu a typickeho pouzitia.', $category);
			}
		}

		if (!empty($term_ids)) {
			wp_set_object_terms($product_id, array_values(array_unique($term_ids)), 'product_cat');
		}

		return $reasoning;
	}

	private function normalize_category_name($category) {
		$category = sanitize_text_field($category);
		$key = strtolower(remove_accents($category));
		$key = preg_replace('/\s+/', ' ', trim($key));
		$map = array(
			'esp & esphome' => 'ESP / ESPHome',
			'esp / esphome' => 'ESP / ESPHome',
			'esphome' => 'ESP / ESPHome',
		);

		return $map[$key] ?? $category;
	}

	public function set_yoast_meta($product_id, $research) {
		$title = sprintf('%s | KomArena', $research['normalized_name']);
		$verified = !empty($research['fact_verification']['verified']);
		$desc  = $verified
			? sprintf('%s pre Arduino-kompatibilne klony, ESP / ESPHome a Home Assistant projekty. Technicke parametre su naviazane na overene zdroje.', $research['normalized_name'])
			: sprintf('%s je pracovny KomArena navrh; technicke parametre cakaju na 100%% zdrojove overenie pred publikovanim.', $research['normalized_name']);

		update_post_meta($product_id, '_yoast_wpseo_title', sanitize_text_field($title));
		update_post_meta($product_id, '_yoast_wpseo_metadesc', sanitize_text_field(wp_trim_words($desc, 24, '')));
		update_post_meta($product_id, '_yoast_wpseo_focuskw', sanitize_text_field($research['normalized_name']));
	}

	public function set_ean_meta($product_id, $ean) {
		$ean = preg_replace('/\D+/', '', $ean);
		update_post_meta($product_id, '_komarena_ean', $ean);
		update_post_meta($product_id, '_global_unique_id', $ean);
		update_post_meta($product_id, '_wpm_gtin_code', $ean);
	}

	private function save_manifest_meta($product_id, $manifest) {
		$options = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0;
		$encoded = wp_json_encode($manifest, $options);
		if (false === $encoded) {
			$encoded = wp_json_encode($manifest);
		}
		update_post_meta($product_id, '_komarena_pf_manifest', wp_slash((string) $encoded));
	}

	private function status_callback($options) {
		if (!empty($options['status_callback']) && is_callable($options['status_callback'])) {
			return $options['status_callback'];
		}

		return function($status) {
			$this->plugin->logger->log('info', 'Stav pracovného postupu: ' . $this->visible_status($status), 'pracovný postup');
		};
	}

	private function visible_status($status) {
		$labels = array(
			'researching'      => 'overuje zdroje',
			'creating_product' => 'vytvára produkt',
			'uploading'        => 'nahráva',
			'images'           => 'spracúva obrázky',
			'content'          => 'tvorí obsah',
			'qa'               => 'kontrola kvality',
			'ready_to_publish' => 'pripravené na publikovanie',
			'needs_review'     => 'na kontrolu',
			'failed'           => 'zlyhalo',
		);

		$key = sanitize_key($status);
		return $labels[$key] ?? (string) $status;
	}
}
