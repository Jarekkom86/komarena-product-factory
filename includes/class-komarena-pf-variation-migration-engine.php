<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Safe WooCommerce simple -> variable migration engine.
 *
 * Principles:
 * - preview is read-only and fail-closed;
 * - staging creates a draft variable parent while source products stay untouched;
 * - legacy descriptions are never copied to variations;
 * - final SKU/EAN ownership moves only during explicit activation;
 * - rollback restores the source snapshot unless generated variations are already used in orders.
 */
class KomArena_PF_Variation_Migration_Engine {
	private $plugin;
	private $namespace = 'komarena-pf/v1';
	private $migrations_option = 'komarena_pf_variation_migrations';
	private $redirects_option = 'komarena_pf_variant_redirects';
	private $ean_keys = array('_global_unique_id', '_komarena_ean', '_alg_ean', '_wpm_gtin_code');

	public function __construct($plugin) {
		$this->plugin = $plugin;
		add_action('rest_api_init', array($this, 'register_routes'));
		add_action('template_redirect', array($this, 'maybe_redirect_legacy_product'), 1);
	}

	public function register_routes() {
		register_rest_route($this->namespace, '/variations/preview', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rest_preview'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/variations/execute', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rest_execute'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/variations/activate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rest_activate'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/variations/rollback', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rest_rollback'),
			'permission_callback' => array($this, 'permission'),
		));
	}

	public function permission($request = null) {
		return current_user_can($this->plugin->capability());
	}

	public function rest_preview(WP_REST_Request $request) {
		return rest_ensure_response($this->preview($this->manifest_from_request($request)));
	}

	public function rest_execute(WP_REST_Request $request) {
		$result = $this->execute($this->manifest_from_request($request));
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function rest_activate(WP_REST_Request $request) {
		$params = (array) $request->get_json_params();
		$migration_id = sanitize_text_field((string) ($params['migration_id'] ?? ''));
		if ('' === $migration_id) {
			return new WP_Error('komarena_pf_missing_migration_id', 'Chýba migration_id.', array('status' => 400));
		}
		$result = $this->activate($migration_id);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	public function rest_rollback(WP_REST_Request $request) {
		$params = (array) $request->get_json_params();
		$migration_id = sanitize_text_field((string) ($params['migration_id'] ?? ''));
		if ('' === $migration_id) {
			return new WP_Error('komarena_pf_missing_migration_id', 'Chýba migration_id.', array('status' => 400));
		}
		$result = $this->rollback($migration_id);
		return is_wp_error($result) ? $result : rest_ensure_response($result);
	}

	private function manifest_from_request(WP_REST_Request $request) {
		$params = (array) $request->get_json_params();
		return isset($params['manifest']) && is_array($params['manifest']) ? $params['manifest'] : $params;
	}

	public function preview($manifest) {
		$manifest = is_array($manifest) ? $manifest : array();
		$errors = array();
		$warnings = array();
		$children_report = array();

		if (!$this->plugin->has_woocommerce() || !class_exists('WC_Product_Variable') || !class_exists('WC_Product_Variation')) {
			$errors[] = 'WooCommerce alebo required variable-product classes nie sú dostupné.';
			return $this->preview_response($manifest, $errors, $warnings, $children_report);
		}

		$parent = isset($manifest['parent']) && is_array($manifest['parent']) ? $manifest['parent'] : array();
		$parent_name = trim(wp_strip_all_tags((string) ($parent['name'] ?? '')));
		$parent_slug = sanitize_title((string) ($parent['slug'] ?? $parent_name));
		$attribute_name = trim(wp_strip_all_tags((string) ($manifest['attribute_name'] ?? 'Farba')));
		$children = isset($manifest['children']) && is_array($manifest['children']) ? array_values($manifest['children']) : array();

		if ('' === $parent_name) {
			$errors[] = 'Parent name je povinný.';
		}
		if ('' === $parent_slug) {
			$errors[] = 'Parent slug je povinný.';
		}
		if ('' === $attribute_name) {
			$errors[] = 'Variation attribute name je povinný.';
		}
		if (count($children) < 2) {
			$errors[] = 'Variable parent musí mať aspoň dve detské variácie.';
		}

		if ('' !== $parent_slug) {
			$existing_parent = get_page_by_path($parent_slug, OBJECT, 'product');
			if ($existing_parent) {
				$errors[] = sprintf('Parent slug "%s" už používa produkt ID %d.', $parent_slug, (int) $existing_parent->ID);
			}
		}

		$seen_source_ids = array();
		$seen_skus = array();
		$seen_eans = array();
		$seen_values = array();

		foreach ($children as $index => $child) {
			$child = is_array($child) ? $child : array();
			$source_id = absint($child['source_product_id'] ?? 0);
			$value = trim(wp_strip_all_tags((string) ($child['value'] ?? '')));
			$manifest_ean = $this->normalize_gtin((string) ($child['ean'] ?? ''));
			$report = array(
				'index' => $index,
				'source_product_id' => $source_id,
				'value' => $value,
				'ok' => true,
				'errors' => array(),
			);

			if (!$source_id) {
				$report['errors'][] = 'Chýba source_product_id.';
			} elseif (isset($seen_source_ids[$source_id])) {
				$report['errors'][] = 'Duplicitný source_product_id v manifeste.';
			} else {
				$seen_source_ids[$source_id] = true;
			}

			if ('' === $value) {
				$report['errors'][] = 'Chýba hodnota variácie.';
			} else {
				$value_key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
				if (isset($seen_values[$value_key])) {
					$report['errors'][] = 'Duplicitná hodnota variácie: ' . $value;
				} else {
					$seen_values[$value_key] = true;
				}
			}

			$product = $source_id ? wc_get_product($source_id) : false;
			if (!$product) {
				$report['errors'][] = 'Zdrojový produkt neexistuje.';
			} elseif (!$product->is_type('simple')) {
				$report['errors'][] = 'Zdrojový produkt musí byť typu simple.';
			} else {
				$sku = trim((string) $product->get_sku());
				$stored_ean = $this->normalize_gtin($this->get_product_ean($product));
				$resolved_ean = $manifest_ean ?: $stored_ean;
				$price = trim((string) $product->get_price());
				$stock_check = $this->validate_stock($product);

				$report['name'] = $product->get_name();
				$report['sku'] = $sku;
				$report['stored_ean'] = $stored_ean;
				$report['manifest_ean'] = $manifest_ean;
				$report['ean'] = $resolved_ean;
				$report['price'] = $price;
				$report['stock'] = $stock_check['summary'];

				if ('' === $sku) {
					$report['errors'][] = 'Chýba SKU.';
				} else {
					$sku_owner = function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($sku) : 0;
					if ($sku_owner && $sku_owner !== $source_id) {
						$report['errors'][] = sprintf('SKU %s už vlastní produkt ID %d.', $sku, $sku_owner);
					}
					if (isset($seen_skus[$sku])) {
						$report['errors'][] = 'Duplicitné SKU v manifeste: ' . $sku;
					} else {
						$seen_skus[$sku] = true;
					}
				}

				if ($manifest_ean && $stored_ean && $manifest_ean !== $stored_ean) {
					$report['errors'][] = sprintf('EAN drift: manifest %s != uložený EAN %s.', $manifest_ean, $stored_ean);
				}
				if ('' === $resolved_ean) {
					$report['errors'][] = 'Chýba EAN/GTIN.';
				} elseif (!$this->valid_gtin($resolved_ean)) {
					$report['errors'][] = 'EAN/GTIN nemá platnú dĺžku alebo kontrolnú číslicu: ' . $resolved_ean;
				} else {
					if (isset($seen_eans[$resolved_ean])) {
						$report['errors'][] = 'Duplicitný EAN v manifeste: ' . $resolved_ean;
					} else {
						$seen_eans[$resolved_ean] = true;
					}
					$ean_collisions = $this->find_ean_collisions($resolved_ean, $source_id);
					if (!empty($ean_collisions)) {
						$report['errors'][] = 'EAN už používa iný produkt/variácia: ' . implode(', ', array_map('intval', $ean_collisions));
					}
				}

				if ('' === $price || !is_numeric($price)) {
					$report['errors'][] = 'Chýba platná cena.';
				}

				foreach ($stock_check['errors'] as $stock_error) {
					$report['errors'][] = $stock_error;
				}
			}

			if (!empty($report['errors'])) {
				$report['ok'] = false;
				foreach ($report['errors'] as $child_error) {
					$errors[] = sprintf('Child #%d (source %d): %s', $index + 1, $source_id, $child_error);
				}
			}
			$children_report[] = $report;
		}

		return $this->preview_response($manifest, $errors, $warnings, $children_report);
	}

	private function preview_response($manifest, $errors, $warnings, $children_report) {
		return array(
			'ok' => empty($errors),
			'mode' => 'preview',
			'fail_closed' => true,
			'errors' => array_values($errors),
			'warnings' => array_values($warnings),
			'children' => array_values($children_report),
			'activate_requested' => !empty($manifest['activate']),
		);
	}

	public function execute($manifest) {
		$preview = $this->preview($manifest);
		if (empty($preview['ok'])) {
			return new WP_Error('komarena_pf_variation_preview_failed', 'Migrácia bola zablokovaná FAIL CLOSED kontrolou.', array(
				'status' => 409,
				'preview' => $preview,
			));
		}

		$migration_id = wp_generate_uuid4();
		$parent_spec = $manifest['parent'];
		$attribute_name = trim(wp_strip_all_tags((string) ($manifest['attribute_name'] ?? 'Farba')));
		$children = array_values($manifest['children']);
		$snapshot = array();
		$resolved_eans = array();
		$variation_ids = array();
		$parent_id = 0;

		try {
			$parent = new WC_Product_Variable();
			$parent->set_name(wp_strip_all_tags((string) $parent_spec['name']));
			$parent->set_slug(sanitize_title((string) ($parent_spec['slug'] ?? $parent_spec['name'])));
			$parent->set_status('draft');
			$parent->set_catalog_visibility('visible');
			$parent->set_description(isset($parent_spec['description']) ? wp_kses_post((string) $parent_spec['description']) : '');
			$parent->set_short_description(isset($parent_spec['short_description']) ? wp_kses_post((string) $parent_spec['short_description']) : '');

			$first_source = wc_get_product((int) $children[0]['source_product_id']);
			if ($first_source) {
				$parent->set_category_ids($first_source->get_category_ids());
				$parent->set_tag_ids($first_source->get_tag_ids());
				$parent->set_image_id((int) $first_source->get_image_id());
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id(0);
			$attribute->set_name($attribute_name);
			$attribute->set_options(array_values(array_map(function($child) {
				return trim(wp_strip_all_tags((string) $child['value']));
			}, $children)));
			$attribute->set_position(0);
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$parent->set_attributes(array(sanitize_title($attribute_name) => $attribute));
			$parent_id = $parent->save();

			if (!$parent_id) {
				throw new Exception('Nepodarilo sa vytvoriť variable parent produkt.');
			}

			foreach ($children as $child) {
				$source_id = absint($child['source_product_id']);
				$value = trim(wp_strip_all_tags((string) $child['value']));
				$source = wc_get_product($source_id);
				if (!$source || !$source->is_type('simple')) {
					throw new Exception('Zdrojový produkt zmizol alebo zmenil typ počas migrácie: ' . $source_id);
				}

				$stored_ean = $this->normalize_gtin($this->get_product_ean($source));
				$manifest_ean = $this->normalize_gtin((string) ($child['ean'] ?? ''));
				$resolved_ean = $manifest_ean ?: $stored_ean;
				if (!$this->valid_gtin($resolved_ean)) {
					throw new Exception('Neplatný resolved EAN pre source ' . $source_id);
				}
				$resolved_eans[$source_id] = $resolved_ean;

				$source_url = get_permalink($source_id);
				$snapshot[$source_id] = array(
					'post_status' => get_post_status($source_id),
					'catalog_visibility' => $source->get_catalog_visibility(),
					'sku' => (string) $source->get_sku(),
					'stored_ean' => $stored_ean,
					'ean_meta' => $this->capture_ean_meta($source_id),
					'permalink' => $source_url ? $source_url : '',
				);

				$variation = new WC_Product_Variation();
				$variation->set_parent_id($parent_id);
				$variation->set_status('publish');
				$variation->set_attributes(array(sanitize_title($attribute_name) => $value));
				$variation->set_sku($this->temporary_sku($migration_id, $source_id));
				$variation->set_regular_price((string) $source->get_regular_price());
				$variation->set_sale_price((string) $source->get_sale_price());
				$variation->set_manage_stock((bool) $source->get_manage_stock());
				if ($source->get_manage_stock()) {
					$variation->set_stock_quantity($source->get_stock_quantity());
				}
				$variation->set_stock_status($source->get_stock_status());
				$variation->set_backorders($source->get_backorders());
				$variation->set_weight((string) $source->get_weight());
				$variation->set_length((string) $source->get_length());
				$variation->set_width((string) $source->get_width());
				$variation->set_height((string) $source->get_height());
				$variation->set_tax_status($source->get_tax_status());
				$variation->set_tax_class($source->get_tax_class());
				$variation->set_image_id((int) $source->get_image_id());
				$variation->set_description('');
				$variation_id = $variation->save();

				if (!$variation_id) {
					throw new Exception('Nepodarilo sa vytvoriť variation pre source ' . $source_id);
				}

				update_post_meta($variation_id, '_komarena_pf_migration_id', $migration_id);
				update_post_meta($variation_id, '_komarena_pf_source_product_id', $source_id);
				update_post_meta($variation_id, '_komarena_pf_pending_sku', (string) $source->get_sku());
				update_post_meta($variation_id, '_komarena_pf_pending_ean', $resolved_ean);
				$variation_ids[$source_id] = $variation_id;
			}

			$record = array(
				'id' => $migration_id,
				'status' => 'staged',
				'created_at' => current_time('mysql'),
				'parent_id' => $parent_id,
				'variation_ids' => $variation_ids,
				'sources' => $snapshot,
				'resolved_eans' => $resolved_eans,
				'attribute_name' => $attribute_name,
				'manifest' => $this->sanitize_manifest_for_storage($manifest),
				'redirect_paths' => array(),
			);
			$this->save_migration($record);

			$staged_validation = $this->validate_staged($record, true);
			if (empty($staged_validation['ok'])) {
				$this->rollback($migration_id, true);
				return new WP_Error('komarena_pf_variation_stage_validation_failed', 'Staged migrácia neprešla kontrolou a bola automaticky rollbacknutá.', array(
					'status' => 500,
					'validation' => $staged_validation,
				));
			}

			if (!empty($manifest['activate'])) {
				return $this->activate($migration_id);
			}

			return array(
				'ok' => true,
				'status' => 'staged',
				'migration_id' => $migration_id,
				'parent_id' => $parent_id,
				'variation_ids' => $variation_ids,
				'sources_changed' => false,
				'next' => 'Skontrolovať draft parent a variácie; potom POST /komarena-pf/v1/variations/activate s migration_id.',
			);
		} catch (Throwable $e) {
			if ($parent_id) {
				foreach ($variation_ids as $variation_id) {
					wp_delete_post((int) $variation_id, true);
				}
				wp_delete_post((int) $parent_id, true);
			}
			return new WP_Error('komarena_pf_variation_execute_failed', $e->getMessage(), array('status' => 500));
		}
	}

	public function activate($migration_id) {
		$record = $this->get_migration($migration_id);
		if (!$record || 'staged' !== ($record['status'] ?? '')) {
			return new WP_Error('komarena_pf_invalid_migration_state', 'Migrácia neexistuje alebo nie je v stave staged.', array('status' => 409));
		}

		$validation = $this->validate_staged($record, true);
		if (empty($validation['ok'])) {
			return new WP_Error('komarena_pf_staged_validation_failed', 'Aktivácia bola zablokovaná FAIL CLOSED kontrolou.', array('status' => 409, 'validation' => $validation));
		}

		$parent = wc_get_product((int) $record['parent_id']);
		if (!$parent || !$parent->is_type('variable')) {
			return new WP_Error('komarena_pf_missing_parent', 'Variable parent sa nenašiel.', array('status' => 404));
		}

		$redirects = get_option($this->redirects_option, array());
		$redirects = is_array($redirects) ? $redirects : array();
		$added_paths = array();

		try {
			foreach ($record['variation_ids'] as $source_id => $variation_id) {
				$source = wc_get_product((int) $source_id);
				$variation = wc_get_product((int) $variation_id);
				if (!$source || !$variation || !$variation->is_type('variation')) {
					throw new Exception('Chýba source alebo staged variation pre source ' . (int) $source_id);
				}

				$final_sku = (string) ($record['sources'][$source_id]['sku'] ?? '');
				$final_ean = (string) ($record['resolved_eans'][$source_id] ?? '');
				if ('' === $final_sku || !$this->valid_gtin($final_ean)) {
					throw new Exception('Snapshot SKU/EAN nie je kompletný pre source ' . (int) $source_id);
				}

				$source->set_sku('');
				$source->set_catalog_visibility('hidden');
				$source->set_status('draft');
				$source->save();
				$this->clear_product_ean((int) $source_id);

				$variation->set_sku($final_sku);
				$variation->save();
				$this->set_product_ean((int) $variation_id, $final_ean, $record['sources'][$source_id]['ean_meta'] ?? array());

				$path = $this->path_from_url((string) ($record['sources'][$source_id]['permalink'] ?? ''));
				if ($path) {
					$redirects[$path] = (int) $record['parent_id'];
					$added_paths[] = $path;
				}
			}

			$requested_status = sanitize_key((string) ($record['manifest']['parent']['status'] ?? 'publish'));
			if (!in_array($requested_status, array('publish', 'draft', 'private'), true)) {
				$requested_status = 'publish';
			}
			$parent->set_status($requested_status);
			$parent->save();
			WC_Product_Variable::sync((int) $record['parent_id']);

			update_option($this->redirects_option, $redirects, false);
			$record['status'] = 'active';
			$record['activated_at'] = current_time('mysql');
			$record['redirect_paths'] = $added_paths;
			$this->save_migration($record);

			return array(
				'ok' => true,
				'status' => 'active',
				'migration_id' => $migration_id,
				'parent_id' => (int) $record['parent_id'],
				'variation_ids' => $record['variation_ids'],
				'redirect_paths' => $added_paths,
			);
		} catch (Throwable $e) {
			$this->rollback($migration_id, true);
			return new WP_Error('komarena_pf_variation_activation_failed', 'Aktivácia zlyhala; bol vykonaný rollback. ' . $e->getMessage(), array('status' => 500));
		}
	}

	public function rollback($migration_id, $internal = false) {
		$record = $this->get_migration($migration_id);
		if (!$record) {
			return new WP_Error('komarena_pf_migration_not_found', 'Migrácia sa nenašla.', array('status' => 404));
		}

		if ('active' === ($record['status'] ?? '') && $this->variations_used_in_orders((array) ($record['variation_ids'] ?? array()))) {
			return new WP_Error('komarena_pf_rollback_order_history_block', 'Rollback je zablokovaný: aspoň jedna vytvorená variácia už bola použitá v objednávke.', array('status' => 409));
		}

		$redirects = get_option($this->redirects_option, array());
		$redirects = is_array($redirects) ? $redirects : array();
		foreach ((array) ($record['redirect_paths'] ?? array()) as $path) {
			unset($redirects[$path]);
		}
		update_option($this->redirects_option, $redirects, false);

		foreach ((array) ($record['variation_ids'] ?? array()) as $variation_id) {
			wp_delete_post((int) $variation_id, true);
		}
		if (!empty($record['parent_id'])) {
			wp_delete_post((int) $record['parent_id'], true);
		}

		foreach ((array) ($record['sources'] ?? array()) as $source_id => $source_snapshot) {
			$source = wc_get_product((int) $source_id);
			if (!$source) {
				continue;
			}
			$source->set_sku((string) ($source_snapshot['sku'] ?? ''));
			$source->set_catalog_visibility((string) ($source_snapshot['catalog_visibility'] ?? 'visible'));
			$source->set_status((string) ($source_snapshot['post_status'] ?? 'draft'));
			$source->save();
			$this->restore_ean_meta((int) $source_id, (array) ($source_snapshot['ean_meta'] ?? array()));
		}

		$record['status'] = 'rolled_back';
		$record['rolled_back_at'] = current_time('mysql');
		$record['rollback_internal'] = (bool) $internal;
		$this->save_migration($record);

		return array('ok' => true, 'status' => 'rolled_back', 'migration_id' => $migration_id);
	}

	private function validate_staged($record, $check_source_drift = false) {
		$errors = array();
		$parent = wc_get_product((int) ($record['parent_id'] ?? 0));
		if (!$parent || !$parent->is_type('variable')) {
			$errors[] = 'Staged parent neexistuje alebo nie je variable.';
		}

		foreach ((array) ($record['variation_ids'] ?? array()) as $source_id => $variation_id) {
			$source = wc_get_product((int) $source_id);
			$variation = wc_get_product((int) $variation_id);
			if (!$source || !$source->is_type('simple')) {
				$errors[] = 'Source ' . (int) $source_id . ' už nie je dostupný simple produkt.';
			}
			if (!$variation || !$variation->is_type('variation')) {
				$errors[] = 'Variation ' . (int) $variation_id . ' chýba.';
				continue;
			}
			if ((int) $variation->get_parent_id() !== (int) ($record['parent_id'] ?? 0)) {
				$errors[] = 'Variation ' . (int) $variation_id . ' má nesprávneho parenta.';
			}
			if ($check_source_drift && $source) {
				$snapshot = (array) ($record['sources'][$source_id] ?? array());
				if ((string) $source->get_sku() !== (string) ($snapshot['sku'] ?? '')) {
					$errors[] = 'Source ' . (int) $source_id . ' zmenil SKU od stagingu.';
				}
				if ((string) get_post_status((int) $source_id) !== (string) ($snapshot['post_status'] ?? '')) {
					$errors[] = 'Source ' . (int) $source_id . ' zmenil status od stagingu.';
				}
				if ((string) $source->get_catalog_visibility() !== (string) ($snapshot['catalog_visibility'] ?? '')) {
					$errors[] = 'Source ' . (int) $source_id . ' zmenil catalog visibility od stagingu.';
				}
				$current_ean = $this->normalize_gtin($this->get_product_ean($source));
				$stored_ean = (string) ($snapshot['stored_ean'] ?? '');
				if ($stored_ean && $current_ean !== $stored_ean) {
					$errors[] = 'Source ' . (int) $source_id . ' zmenil uložený EAN od stagingu.';
				}
			}
		}

		return array('ok' => empty($errors), 'errors' => $errors);
	}

	private function validate_stock($product) {
		$errors = array();
		if ($product->get_manage_stock()) {
			$qty = $product->get_stock_quantity();
			if (null === $qty || !is_numeric($qty)) {
				$errors[] = 'Manage stock je zapnuté, ale stock quantity chýba.';
			}
			$summary = 'manage_stock qty=' . (null === $qty ? 'NULL' : (string) $qty) . ', status=' . $product->get_stock_status() . ', backorders=' . $product->get_backorders();
		} else {
			$status = (string) $product->get_stock_status();
			if (!in_array($status, array('instock', 'outofstock', 'onbackorder'), true)) {
				$errors[] = 'Stock status je nejednoznačný alebo neplatný.';
			}
			$summary = 'stock_status=' . $status;
		}
		return array('errors' => $errors, 'summary' => $summary);
	}

	private function normalize_gtin($value) {
		return preg_replace('/\D+/', '', (string) $value);
	}

	private function valid_gtin($code) {
		$code = $this->normalize_gtin($code);
		$length = strlen($code);
		if (!in_array($length, array(8, 12, 13, 14), true)) {
			return false;
		}
		$check = (int) substr($code, -1);
		$body = substr($code, 0, -1);
		$sum = 0;
		$weight = 3;
		for ($i = strlen($body) - 1; $i >= 0; $i--) {
			$sum += ((int) $body[$i]) * $weight;
			$weight = 3 === $weight ? 1 : 3;
		}
		return ((10 - ($sum % 10)) % 10) === $check;
	}

	private function get_product_ean($product) {
		$product_id = is_object($product) ? (int) $product->get_id() : absint($product);
		if (!$product_id) {
			return '';
		}
		if (is_object($product) && method_exists($product, 'get_global_unique_id')) {
			$value = trim((string) $product->get_global_unique_id());
			if ('' !== $value) {
				return $value;
			}
		}
		foreach ($this->ean_keys as $key) {
			$value = trim((string) get_post_meta($product_id, $key, true));
			if ('' !== $value) {
				return $value;
			}
		}
		return '';
	}

	private function capture_ean_meta($product_id) {
		$out = array();
		foreach ($this->ean_keys as $key) {
			$out[$key] = (string) get_post_meta($product_id, $key, true);
		}
		return $out;
	}

	private function clear_product_ean($product_id) {
		foreach ($this->ean_keys as $key) {
			delete_post_meta($product_id, $key);
		}
		$product = wc_get_product($product_id);
		if ($product && method_exists($product, 'set_global_unique_id')) {
			$product->set_global_unique_id('');
			$product->save();
		}
	}

	private function set_product_ean($product_id, $ean, $source_meta = array()) {
		$product = wc_get_product($product_id);
		if ($product && method_exists($product, 'set_global_unique_id')) {
			$product->set_global_unique_id($ean);
			$product->save();
		}

		$written = false;
		foreach ($this->ean_keys as $key) {
			if (isset($source_meta[$key]) && '' !== (string) $source_meta[$key]) {
				update_post_meta($product_id, $key, $ean);
				$written = true;
			}
		}
		if (!$written && !($product && method_exists($product, 'set_global_unique_id'))) {
			update_post_meta($product_id, '_komarena_ean', $ean);
		}
	}

	private function restore_ean_meta($product_id, $meta) {
		$this->clear_product_ean($product_id);
		foreach ($this->ean_keys as $key) {
			if (isset($meta[$key]) && '' !== (string) $meta[$key]) {
				update_post_meta($product_id, $key, (string) $meta[$key]);
			}
		}
		$product = wc_get_product($product_id);
		if ($product && method_exists($product, 'set_global_unique_id') && !empty($meta['_global_unique_id'])) {
			$product->set_global_unique_id((string) $meta['_global_unique_id']);
			$product->save();
		}
	}

	private function find_ean_collisions($ean, $source_id) {
		$ids = array();
		foreach ($this->ean_keys as $key) {
			$query = new WP_Query(array(
				'post_type' => array('product', 'product_variation'),
				'post_status' => 'any',
				'fields' => 'ids',
				'posts_per_page' => 20,
				'post__not_in' => array((int) $source_id),
				'meta_query' => array(array('key' => $key, 'value' => $ean, 'compare' => '=')),
			));
			foreach ((array) $query->posts as $id) {
				$ids[(int) $id] = true;
			}
		}
		return array_keys($ids);
	}

	private function variations_used_in_orders($variation_ids) {
		$variation_ids = array_values(array_filter(array_map('absint', $variation_ids)));
		if (empty($variation_ids)) {
			return false;
		}
		global $wpdb;
		$ids = implode(',', $variation_ids);
		$table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE meta_key = '_variation_id' AND CAST(meta_value AS UNSIGNED) IN ({$ids})");
		return $count > 0;
	}

	private function temporary_sku($migration_id, $source_id) {
		return 'KPFM-' . absint($source_id) . '-' . substr(md5((string) $migration_id), 0, 8);
	}

	private function sanitize_manifest_for_storage($manifest) {
		$clean = array(
			'parent' => array(
				'name' => wp_strip_all_tags((string) ($manifest['parent']['name'] ?? '')),
				'slug' => sanitize_title((string) ($manifest['parent']['slug'] ?? '')),
				'status' => sanitize_key((string) ($manifest['parent']['status'] ?? 'publish')),
			),
			'attribute_name' => wp_strip_all_tags((string) ($manifest['attribute_name'] ?? 'Farba')),
			'activate' => !empty($manifest['activate']),
			'children' => array(),
		);
		foreach ((array) ($manifest['children'] ?? array()) as $child) {
			$clean['children'][] = array(
				'source_product_id' => absint($child['source_product_id'] ?? 0),
				'value' => wp_strip_all_tags((string) ($child['value'] ?? '')),
				'ean' => $this->normalize_gtin((string) ($child['ean'] ?? '')),
			);
		}
		return $clean;
	}

	private function get_migrations() {
		$value = get_option($this->migrations_option, array());
		return is_array($value) ? $value : array();
	}

	private function get_migration($migration_id) {
		$migrations = $this->get_migrations();
		return isset($migrations[$migration_id]) && is_array($migrations[$migration_id]) ? $migrations[$migration_id] : null;
	}

	private function save_migration($record) {
		$migrations = $this->get_migrations();
		$migrations[$record['id']] = $record;
		update_option($this->migrations_option, $migrations, false);
	}

	private function path_from_url($url) {
		$path = wp_parse_url($url, PHP_URL_PATH);
		return $path ? '/' . trim($path, '/') . '/' : '';
	}

	public function maybe_redirect_legacy_product() {
		if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
			return;
		}
		$uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
		$path = $this->path_from_url($uri);
		if (!$path) {
			return;
		}
		$redirects = get_option($this->redirects_option, array());
		if (!is_array($redirects) || empty($redirects[$path])) {
			return;
		}
		$target = get_permalink((int) $redirects[$path]);
		if (!$target) {
			return;
		}
		wp_safe_redirect($target, 301, 'KomArena Variation Migration');
		exit;
	}
}
