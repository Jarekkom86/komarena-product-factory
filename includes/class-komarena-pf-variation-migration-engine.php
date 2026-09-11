<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Variation_Migration_Engine {
	private $plugin;
	private $namespace = 'komarena-pf/v1';
	private $lock_option = 'komarena_pf_variation_migration_lock';

	public function __construct($plugin) {
		$this->plugin = $plugin;
		add_action('rest_api_init', array($this, 'register_routes'));
		add_action('template_redirect', array($this, 'maybe_redirect_migrated_source'), 1);
	}

	public function register_routes() {
		register_rest_route($this->namespace, '/variation-migrations/preview', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rest_preview'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/variation-migrations/execute', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rest_execute'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/variation-migrations/rollback', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rest_rollback'),
			'permission_callback' => array($this, 'permission'),
		));
	}

	public function permission($request = null) {
		return current_user_can($this->plugin->capability());
	}

	public function rest_preview(WP_REST_Request $request) {
		$params = $request->get_json_params();
		$params = is_array($params) ? $params : array();
		$result = $this->preview($params);
		return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'preview' => $result));
	}

	public function rest_execute(WP_REST_Request $request) {
		$params = $request->get_json_params();
		$params = is_array($params) ? $params : array();

		if ('MIGRATE_VARIABLES' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error(
				'komarena_pf_variation_confirm_missing',
				'Migrácia vyžaduje confirm=MIGRATE_VARIABLES.',
				array('status' => 400)
			);
		}

		$result = $this->execute($params);
		return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'result' => $result));
	}

	public function rest_rollback(WP_REST_Request $request) {
		$params = $request->get_json_params();
		$params = is_array($params) ? $params : array();

		if ('ROLLBACK_VARIABLES' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error(
				'komarena_pf_variation_rollback_confirm_missing',
				'Rollback vyžaduje confirm=ROLLBACK_VARIABLES.',
				array('status' => 400)
			);
		}

		$result = $this->rollback(absint($params['parent_id'] ?? 0));
		return is_wp_error($result) ? $result : rest_ensure_response(array('ok' => true, 'result' => $result));
	}

	public function preview($args) {
		if (!$this->plugin->has_woocommerce() || !class_exists('WC_Product_Variable') || !class_exists('WC_Product_Variation')) {
			return new WP_Error('komarena_pf_wc_missing', 'WooCommerce variabilné produkty nie sú dostupné.', array('status' => 503));
		}

		$parent_name = sanitize_text_field($args['parent_name'] ?? '');
		$attribute_name = sanitize_text_field($args['attribute_name'] ?? 'Farba');
		$variants = isset($args['variants']) && is_array($args['variants']) ? $args['variants'] : array();
		$parent_id = absint($args['parent_id'] ?? 0);
		$blockers = array();
		$warnings = array();
		$normalized = array();
		$seen_sources = array();
		$seen_values = array();

		if (!$parent_id && '' === $parent_name) {
			$blockers[] = 'Chýba parent_name pre nový rodičovský produkt.';
		}
		if ('' === $attribute_name) {
			$blockers[] = 'Chýba attribute_name.';
		}
		if (empty($variants)) {
			$blockers[] = 'Chýba zoznam variants.';
		}

		$existing_parent = null;
		if ($parent_id) {
			$existing_parent = wc_get_product($parent_id);
			if (!$existing_parent || !$existing_parent->is_type('variable')) {
				$blockers[] = 'parent_id neodkazuje na variabilný WooCommerce produkt.';
			}
		}

		foreach ($variants as $index => $variant) {
			if (!is_array($variant)) {
				$blockers[] = 'Variant #' . ($index + 1) . ' nemá platný formát.';
				continue;
			}

			$source_id = absint($variant['source_id'] ?? 0);
			$value = sanitize_text_field($variant['value'] ?? '');
			if (!$source_id || '' === $value) {
				$blockers[] = 'Variant #' . ($index + 1) . ' potrebuje source_id aj value.';
				continue;
			}
			if (isset($seen_sources[$source_id])) {
				$blockers[] = 'Produkt #' . $source_id . ' je v migrácii uvedený viackrát.';
				continue;
			}
			$value_key = sanitize_title($value);
			if (isset($seen_values[$value_key])) {
				$blockers[] = 'Duplicitná hodnota variantu: ' . $value . '.';
				continue;
			}
			$seen_sources[$source_id] = true;
			$seen_values[$value_key] = true;

			$product = wc_get_product($source_id);
			if (!$product) {
				$blockers[] = 'Zdrojový produkt #' . $source_id . ' neexistuje.';
				continue;
			}
			if (!$product->is_type('simple')) {
				$blockers[] = 'Zdrojový produkt #' . $source_id . ' nie je simple produkt.';
				continue;
			}
			if (get_post_meta($source_id, '_komarena_pf_migrated_to_parent', true)) {
				$blockers[] = 'Zdrojový produkt #' . $source_id . ' už bol migrovaný.';
				continue;
			}

			$sku = (string) $product->get_sku();
			if ('' === $sku) {
				$blockers[] = 'Zdrojový produkt #' . $source_id . ' nemá SKU.';
			} else {
				$sku_owner = absint(wc_get_product_id_by_sku($sku));
				if ($sku_owner && $sku_owner !== $source_id) {
					$blockers[] = 'SKU ' . $sku . ' už používa produkt #' . $sku_owner . '.';
				}
			}

			$regular_price = (string) $product->get_regular_price();
			$current_price = (string) $product->get_price();
			if ('' === $regular_price && '' === $current_price) {
				$blockers[] = 'Zdrojový produkt #' . $source_id . ' nemá cenu.';
			}

			$image_id = absint($product->get_image_id());
			if (!$image_id) {
				$warnings[] = 'Zdrojový produkt #' . $source_id . ' nemá hlavný obrázok pre variáciu.';
			}

			$normalized[] = array(
				'source_id'       => $source_id,
				'name'            => $product->get_name(),
				'value'           => $value,
				'sku'             => $sku,
				'ean'             => $this->read_ean($product),
				'regular_price'   => $regular_price,
				'sale_price'      => (string) $product->get_sale_price(),
				'price'           => $current_price,
				'manage_stock'    => (bool) $product->get_manage_stock(),
				'stock_quantity'  => $product->get_stock_quantity(),
				'stock_status'    => $product->get_stock_status(),
				'backorders'      => $product->get_backorders(),
				'image_id'        => $image_id,
				'catalog_visibility' => $product->get_catalog_visibility(),
				'status'          => $product->get_status(),
				'permalink'       => get_permalink($source_id),
			);
		}

		return array(
			'ready'          => empty($blockers),
			'blockers'       => array_values(array_unique($blockers)),
			'warnings'       => array_values(array_unique($warnings)),
			'parent_id'      => $parent_id,
			'parent_name'    => $parent_id && $existing_parent ? $existing_parent->get_name() : $parent_name,
			'parent_slug'    => sanitize_title($args['parent_slug'] ?? $parent_name),
			'attribute_name' => $attribute_name,
			'variants'       => $normalized,
			'plan'           => array(
				'parent_status' => $this->sanitize_parent_status($args['parent_status'] ?? 'draft'),
				'source_after_migration' => 'published + catalog hidden + 301 po publikovaní rodiča',
				'sku_strategy' => 'SKU sa presunie zo zdrojového simple produktu na novú variáciu; originál sa uloží do rollback snapshotu.',
				'fail_closed' => true,
			),
		);
	}

	public function execute($args) {
		$preview = $this->preview($args);
		if (is_wp_error($preview)) {
			return $preview;
		}
		if (empty($preview['ready'])) {
			return new WP_Error('komarena_pf_variation_preview_blocked', 'Migráciu blokuje preflight kontrola.', array('status' => 409, 'preview' => $preview));
		}
		if (!$this->acquire_lock()) {
			return new WP_Error('komarena_pf_variation_locked', 'Iná migrácia variácií práve prebieha.', array('status' => 423));
		}

		$this->plugin->logger->new_run_id('variation-migration');
		$parent = null;
		$created_parent = false;
		$created_variations = array();
		$touched_sources = array();

		try {
			$parent_id = absint($preview['parent_id']);
			if ($parent_id) {
				$parent = wc_get_product($parent_id);
			} else {
				$parent = $this->create_parent($preview, $args);
				if (is_wp_error($parent)) {
					throw new Exception($parent->get_error_message());
				}
				$created_parent = true;
				$parent_id = $parent->get_id();
			}

			$this->apply_parent_attribute($parent, $preview['attribute_name'], wp_list_pluck($preview['variants'], 'value'));
			$parent->save();

			$source_map = array();
			foreach ($preview['variants'] as $variant_plan) {
				$source = wc_get_product($variant_plan['source_id']);
				if (!$source || !$source->is_type('simple')) {
					throw new Exception('Zdrojový produkt #' . absint($variant_plan['source_id']) . ' sa počas migrácie zmenil alebo zmizol.');
				}

				$snapshot = $this->snapshot_source($source);
				update_post_meta($source->get_id(), '_komarena_pf_variant_migration_snapshot', wp_json_encode($snapshot));
				$touched_sources[$source->get_id()] = $snapshot;

				$this->release_unique_identifiers($source);
				$source->set_catalog_visibility('hidden');
				$source->save();

				$variation = $this->create_variation($parent, $source, $variant_plan['value'], $snapshot);
				if (is_wp_error($variation)) {
					throw new Exception($variation->get_error_message());
				}

				$variation_id = $variation->get_id();
				$created_variations[] = $variation_id;
				update_post_meta($source->get_id(), '_komarena_pf_migrated_to_parent', $parent_id);
				update_post_meta($source->get_id(), '_komarena_pf_migrated_to_variation', $variation_id);
				update_post_meta($source->get_id(), '_komarena_pf_migrated_at', current_time('mysql'));
				update_post_meta($source->get_id(), '_komarena_pf_old_permalink', esc_url_raw($snapshot['permalink']));

				$source_map[] = array(
					'source_id'    => $source->get_id(),
					'variation_id' => $variation_id,
					'value'        => $variant_plan['value'],
				);

				$this->plugin->logger->log('info', 'Simple produkt bol prevedený na variáciu.', 'variation_migration', array(
					'parent_id' => $parent_id,
					'variation_id' => $variation_id,
					'value' => $variant_plan['value'],
				), null, $source->get_id());
			}

			update_post_meta($parent_id, '_komarena_pf_variant_source_map', wp_json_encode($source_map));
			update_post_meta($parent_id, '_komarena_pf_variation_migration_status', 'complete');
			update_post_meta($parent_id, '_komarena_pf_variation_migration_created_at', current_time('mysql'));
			update_post_meta($parent_id, '_komarena_pf_variation_attribute_name', $preview['attribute_name']);
			update_post_meta($parent_id, '_komarena_pf_variation_migration_engine', KOMARENA_PF_VERSION);

			WC_Product_Variable::sync($parent_id);
			wc_delete_product_transients($parent_id);

			$parent = wc_get_product($parent_id);
			if ($created_parent && $parent) {
				$parent->set_status($this->sanitize_parent_status($args['parent_status'] ?? 'draft'));
				$parent->save();
			}

			$this->plugin->logger->log('info', 'Migrácia variabilného produktu dokončená.', 'variation_migration', array(
				'parent_id' => $parent_id,
				'created_parent' => $created_parent,
				'variation_ids' => $created_variations,
			));

			return array(
				'parent_id'       => $parent_id,
				'parent_status'   => $parent ? $parent->get_status() : '',
				'created_parent'  => $created_parent,
				'variation_ids'   => $created_variations,
				'source_map'      => $source_map,
				'rollback_ready'  => true,
				'redirects_active'=> $parent && 'publish' === $parent->get_status(),
			);
		} catch (Throwable $e) {
			$this->rollback_partial($created_variations, $created_parent && $parent ? $parent->get_id() : 0, $touched_sources);
			$this->plugin->logger->log('error', 'Migrácia variácií zlyhala a spustil sa rollback.', 'variation_migration', array('error' => $e->getMessage()));
			return new WP_Error('komarena_pf_variation_migration_failed', $e->getMessage(), array('status' => 500));
		} finally {
			$this->release_lock();
		}
	}

	public function rollback($parent_id) {
		if (!$parent_id) {
			return new WP_Error('komarena_pf_variation_parent_missing', 'Chýba parent_id.', array('status' => 400));
		}
		$parent = wc_get_product($parent_id);
		if (!$parent || !$parent->is_type('variable')) {
			return new WP_Error('komarena_pf_variation_parent_invalid', 'Rodičovský produkt neexistuje alebo nie je variable.', array('status' => 404));
		}
		if ('publish' === $parent->get_status()) {
			return new WP_Error('komarena_pf_live_rollback_blocked', 'FAIL CLOSED: publikovaný rodič sa automaticky nerollbackuje. Najprv ho prepnite do draftu.', array('status' => 409));
		}
		if (!$this->acquire_lock()) {
			return new WP_Error('komarena_pf_variation_locked', 'Iná migrácia variácií práve prebieha.', array('status' => 423));
		}

		try {
			$source_map = json_decode((string) get_post_meta($parent_id, '_komarena_pf_variant_source_map', true), true);
			if (!is_array($source_map) || empty($source_map)) {
				return new WP_Error('komarena_pf_variation_map_missing', 'K rodičovi nie je uložená migračná mapa.', array('status' => 409));
			}

			$restored = array();
			$deleted_variations = array();
			foreach ($source_map as $row) {
				$source_id = absint($row['source_id'] ?? 0);
				$variation_id = absint($row['variation_id'] ?? 0);
				if ($variation_id) {
					wp_delete_post($variation_id, true);
					$deleted_variations[] = $variation_id;
				}
				if ($source_id) {
					$snapshot = json_decode((string) get_post_meta($source_id, '_komarena_pf_variant_migration_snapshot', true), true);
					if (is_array($snapshot)) {
						$this->restore_source($source_id, $snapshot);
						$restored[] = $source_id;
					}
				}
			}

			wp_delete_post($parent_id, true);
			$this->plugin->logger->log('warning', 'Migrácia variabilného produktu bola rollbacknutá.', 'variation_migration', array(
				'parent_id' => $parent_id,
				'restored_sources' => $restored,
				'deleted_variations' => $deleted_variations,
			));

			return array(
				'parent_id' => $parent_id,
				'restored_sources' => $restored,
				'deleted_variations' => $deleted_variations,
				'parent_deleted' => true,
			);
		} finally {
			$this->release_lock();
		}
	}

	public function maybe_redirect_migrated_source() {
		if (is_admin() || !is_singular('product')) {
			return;
		}
		$source_id = get_queried_object_id();
		$parent_id = absint(get_post_meta($source_id, '_komarena_pf_migrated_to_parent', true));
		if (!$parent_id) {
			return;
		}
		$parent = wc_get_product($parent_id);
		if (!$parent || 'publish' !== $parent->get_status()) {
			return;
		}
		$target = get_permalink($parent_id);
		if ($target) {
			wp_safe_redirect($target, 301, 'KomArena Product Factory');
			exit;
		}
	}

	private function create_parent($preview, $args) {
		$first = !empty($preview['variants'][0]['source_id']) ? wc_get_product($preview['variants'][0]['source_id']) : null;
		if (!$first) {
			return new WP_Error('komarena_pf_variation_source_missing', 'Nie je dostupný prvý zdrojový produkt pre rodiča.');
		}

		$parent = new WC_Product_Variable();
		$parent->set_name($preview['parent_name']);
		if (!empty($preview['parent_slug'])) {
			$parent->set_slug($preview['parent_slug']);
		}
		$parent->set_status('draft');
		$parent->set_catalog_visibility('visible');
		$parent->set_description(isset($args['parent_description']) ? wp_kses_post($args['parent_description']) : $first->get_description());
		$parent->set_short_description(isset($args['parent_short_description']) ? wp_kses_post($args['parent_short_description']) : $first->get_short_description());
		$parent->set_category_ids($this->collect_taxonomy_ids($preview['variants'], 'category'));
		$parent->set_tag_ids($this->collect_taxonomy_ids($preview['variants'], 'tag'));
		$parent->set_image_id($first->get_image_id());
		$parent->set_gallery_image_ids($this->collect_gallery_ids($preview['variants']));
		$parent->set_tax_status($first->get_tax_status());
		$parent->set_tax_class($first->get_tax_class());
		$parent->set_virtual(false);
		$parent->set_manage_stock(false);
		$parent->save();
		return $parent;
	}

	private function apply_parent_attribute($parent, $attribute_name, $values) {
		$values = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $values))));
		$attribute = new WC_Product_Attribute();
		$attribute->set_id(0);
		$attribute->set_name($attribute_name);
		$attribute->set_options($values);
		$attribute->set_position(0);
		$attribute->set_visible(true);
		$attribute->set_variation(true);

		$attributes = $parent->get_attributes();
		$attributes[sanitize_title($attribute_name)] = $attribute;
		$parent->set_attributes($attributes);
	}

	private function create_variation($parent, $source, $value, $snapshot) {
		try {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id($parent->get_id());
			$variation->set_status('publish');
			$variation->set_attributes(array(sanitize_title((string) get_post_meta($parent->get_id(), '_komarena_pf_variation_attribute_name', true) ?: 'farba') => $value));
			$variation->set_sku((string) $snapshot['sku']);
			$variation->set_regular_price((string) $snapshot['regular_price']);
			$variation->set_sale_price((string) $snapshot['sale_price']);
			$variation->set_manage_stock(!empty($snapshot['manage_stock']));
			if (!empty($snapshot['manage_stock'])) {
				$variation->set_stock_quantity($snapshot['stock_quantity']);
			}
			$variation->set_stock_status((string) $snapshot['stock_status']);
			$variation->set_backorders((string) $snapshot['backorders']);
			$variation->set_weight((string) $snapshot['weight']);
			$variation->set_length((string) $snapshot['length']);
			$variation->set_width((string) $snapshot['width']);
			$variation->set_height((string) $snapshot['height']);
			$variation->set_tax_status((string) $snapshot['tax_status']);
			$variation->set_tax_class((string) $snapshot['tax_class']);
			$variation->set_virtual(!empty($snapshot['virtual']));
			$variation->set_downloadable(!empty($snapshot['downloadable']));
			$variation->set_image_id(absint($snapshot['image_id']));
			$variation->save();

			$this->write_ean($variation, $snapshot['ean']);
			update_post_meta($variation->get_id(), '_komarena_pf_migrated_from_product', $source->get_id());
			update_post_meta($variation->get_id(), '_komarena_pf_migration_source_url', esc_url_raw($snapshot['permalink']));
			return $variation;
		} catch (Throwable $e) {
			return new WP_Error('komarena_pf_variation_create_failed', $e->getMessage());
		}
	}

	private function snapshot_source($product) {
		return array(
			'id'                 => $product->get_id(),
			'name'               => $product->get_name(),
			'sku'                => (string) $product->get_sku(),
			'ean'                => $this->read_ean($product),
			'regular_price'      => (string) $product->get_regular_price(),
			'sale_price'         => (string) $product->get_sale_price(),
			'manage_stock'       => (bool) $product->get_manage_stock(),
			'stock_quantity'     => $product->get_stock_quantity(),
			'stock_status'       => (string) $product->get_stock_status(),
			'backorders'         => (string) $product->get_backorders(),
			'weight'             => (string) $product->get_weight(),
			'length'             => (string) $product->get_length(),
			'width'              => (string) $product->get_width(),
			'height'             => (string) $product->get_height(),
			'tax_status'         => (string) $product->get_tax_status(),
			'tax_class'          => (string) $product->get_tax_class(),
			'virtual'            => (bool) $product->get_virtual(),
			'downloadable'       => (bool) $product->get_downloadable(),
			'image_id'           => absint($product->get_image_id()),
			'catalog_visibility' => (string) $product->get_catalog_visibility(),
			'status'             => (string) $product->get_status(),
			'permalink'          => (string) get_permalink($product->get_id()),
		);
	}

	private function release_unique_identifiers($source) {
		$source->set_sku('');
		if (method_exists($source, 'set_global_unique_id')) {
			$source->set_global_unique_id('');
		}
		$source->save();
		foreach (array('_komarena_ean', '_alg_ean', '_wpm_gtin_code') as $key) {
			if (metadata_exists('post', $source->get_id(), $key)) {
				delete_post_meta($source->get_id(), $key);
			}
		}
	}

	private function read_ean($product) {
		$ean = array();
		if (method_exists($product, 'get_global_unique_id')) {
			$ean['global_unique_id'] = (string) $product->get_global_unique_id();
		}
		foreach (array('_komarena_ean', '_alg_ean', '_wpm_gtin_code') as $key) {
			$value = (string) get_post_meta($product->get_id(), $key, true);
			if ('' !== $value) {
				$ean[$key] = $value;
			}
		}
		return $ean;
	}

	private function write_ean($product, $ean) {
		$ean = is_array($ean) ? $ean : array();
		if (method_exists($product, 'set_global_unique_id') && !empty($ean['global_unique_id'])) {
			$product->set_global_unique_id(sanitize_text_field($ean['global_unique_id']));
			$product->save();
		}
		foreach (array('_komarena_ean', '_alg_ean', '_wpm_gtin_code') as $key) {
			if (!empty($ean[$key])) {
				update_post_meta($product->get_id(), $key, sanitize_text_field($ean[$key]));
			}
		}
	}

	private function restore_source($source_id, $snapshot) {
		$product = wc_get_product($source_id);
		if (!$product) {
			return false;
		}
		$product->set_sku((string) ($snapshot['sku'] ?? ''));
		$product->set_catalog_visibility((string) ($snapshot['catalog_visibility'] ?? 'visible'));
		$product->set_status((string) ($snapshot['status'] ?? 'publish'));
		if (method_exists($product, 'set_global_unique_id')) {
			$product->set_global_unique_id((string) (($snapshot['ean']['global_unique_id'] ?? '')));
		}
		$product->save();
		$this->write_ean($product, $snapshot['ean'] ?? array());
		delete_post_meta($source_id, '_komarena_pf_migrated_to_parent');
		delete_post_meta($source_id, '_komarena_pf_migrated_to_variation');
		delete_post_meta($source_id, '_komarena_pf_migrated_at');
		delete_post_meta($source_id, '_komarena_pf_old_permalink');
		delete_post_meta($source_id, '_komarena_pf_variant_migration_snapshot');
		wc_delete_product_transients($source_id);
		return true;
	}

	private function rollback_partial($variation_ids, $parent_id, $source_snapshots) {
		foreach ((array) $variation_ids as $variation_id) {
			wp_delete_post(absint($variation_id), true);
		}
		if ($parent_id) {
			wp_delete_post(absint($parent_id), true);
		}
		foreach ((array) $source_snapshots as $source_id => $snapshot) {
			$this->restore_source(absint($source_id), $snapshot);
		}
	}

	private function collect_taxonomy_ids($variant_plans, $kind) {
		$ids = array();
		foreach ($variant_plans as $plan) {
			$product = wc_get_product(absint($plan['source_id'] ?? 0));
			if (!$product) {
				continue;
			}
			$source_ids = 'tag' === $kind ? $product->get_tag_ids() : $product->get_category_ids();
			$ids = array_merge($ids, array_map('absint', (array) $source_ids));
		}
		return array_values(array_unique(array_filter($ids)));
	}

	private function collect_gallery_ids($variant_plans) {
		$ids = array();
		foreach ($variant_plans as $plan) {
			$product = wc_get_product(absint($plan['source_id'] ?? 0));
			if (!$product) {
				continue;
			}
			$ids[] = absint($product->get_image_id());
			$ids = array_merge($ids, array_map('absint', (array) $product->get_gallery_image_ids()));
		}
		return array_values(array_unique(array_filter($ids)));
	}

	private function sanitize_parent_status($status) {
		$status = sanitize_key($status);
		return in_array($status, array('draft', 'private', 'publish'), true) ? $status : 'draft';
	}

	private function acquire_lock() {
		$existing = get_option($this->lock_option, array());
		if (is_array($existing) && !empty($existing['created']) && (time() - absint($existing['created'])) > 600) {
			delete_option($this->lock_option);
		}
		return add_option($this->lock_option, array('created' => time(), 'user_id' => get_current_user_id()), '', 'no');
	}

	private function release_lock() {
		delete_option($this->lock_option);
	}
}
