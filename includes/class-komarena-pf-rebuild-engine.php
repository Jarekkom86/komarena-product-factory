<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Rebuild_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function rebuild($product_id, $options = array()) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.');
		}

		$product_id = absint($product_id);
		$product = wc_get_product($product_id);
		if (!$product) {
			return new WP_Error('komarena_pf_missing_product', 'Produkt neexistuje.');
		}

		$backup = $this->create_backup_before_rebuild($product_id, $product);
		if (is_wp_error($backup)) {
			return $backup;
		}

		$this->plugin->logger->new_run_id('rebuild-' . $product_id);
		$this->plugin->logger->log('info', 'Prebudovanie produktu spustené.', 'prebudovanie', array('product_id' => $product_id), null, $product_id);

		$allow_title_update    = !empty($options['allow_title_update']);
		$allow_image_update    = !empty($options['allow_image_update']);
		$allow_price_update    = !empty($options['allow_price_update']) || !empty($options['overwrite_price']);
		$allow_category_update = !empty($options['allow_category_update']);

		$old_slug   = get_post_field('post_name', $product_id);
		$old_status = get_post_status($product_id);
		$old_title  = $product->get_name();
		$old_sku    = $product->get_sku();
		$old_price  = $product->get_regular_price() ? $product->get_regular_price() : $product->get_price();

		$research = $this->plugin->research->research($old_title, $options);
		$research['safety'] = $this->plugin->safety->analyze($research);

		$suggested_title = sanitize_text_field($research['normalized_name'] ?? $old_title);
		if ('' === $suggested_title) {
			$suggested_title = $old_title;
		}
		update_post_meta($product_id, '_komarena_pf_suggested_title', $suggested_title);

		if ($allow_price_update || !$old_price) {
			$price = $this->plugin->pricing->price($research);
		} else {
			$price = array(
				'price'     => $old_price,
				'reasoning' => array('preserved_existing_price' => true, 'note' => 'Cena bola zachovana, lebo AllowPriceUpdate nebol povoleny.'),
			);
		}

		$product->set_name($allow_title_update ? $suggested_title : $old_title);
		$product->set_slug($old_slug);
		$product->set_status(!empty($options['preserve_status']) && $old_status ? $old_status : 'draft');
		if ('' !== (string) ($price['price'] ?? '')) {
			$product->set_regular_price($price['price']);
			$product->set_price($price['price']);
		}
		if ($old_sku) {
			$product->set_sku($old_sku);
		} else {
			$product->set_sku($this->plugin->sku_ean->sku($old_title));
		}
		$product->save();

		if ($allow_category_update) {
			$category_reasoning = $this->plugin->creator->assign_categories($product_id, $research['categories']);
		} else {
			$category_reasoning = array(
				'preserved_existing_categories' => true,
				'category_ids' => $this->category_ids($product_id),
				'note' => 'Kategorie boli zachovane, lebo AllowCategoryUpdate nebol povoleny.',
			);
		}

		$ean = get_post_meta($product_id, '_komarena_ean', true);
		if (!$ean) {
			$ean = $this->plugin->sku_ean->ean();
			$this->plugin->creator->set_ean_meta($product_id, $ean);
		}

		$images = $this->existing_images($product_id, $research);
		if ($allow_image_update) {
			$new_images = $this->plugin->images->prepare_images($product_id, $research, $old_slug, array(
				'attach_to_product' => true,
				'require_exact_match' => true,
			));
			if (count((array) ($new_images['image_ids'] ?? array())) >= 4) {
				$images = $new_images;
			} else {
				$images['warnings'] = array_values(array_unique(array_merge(
					(array) ($images['warnings'] ?? array()),
					(array) ($new_images['warnings'] ?? array()),
					array('Aktualizácia obrázkov bola povolená, ale agent nenašiel 4 presne overené finálne obrázky; existujúce obrázky zostali zachované.')
				)));
			}
		} else {
			$images['warnings'][] = 'Aktualizácia obrázkov je zamknutá. Prebudovanie zachovalo hlavný obrázok a galériu.';
		}

		$short = $this->plugin->content->short_description($research);
		$long  = $this->plugin->content->long_description($research, $this->images_for_content($images), $price);

		$product = wc_get_product($product_id);
		$product->set_short_description($short);
		$product->set_description($long);
		$product->save();

		$this->plugin->creator->set_yoast_meta($product_id, $research);
		if ($this->plugin->settings->get('auto_apply_attributes', 1)) {
			$this->plugin->attributes->apply($product_id, $research);
		}

		$warnings = array_values(array_unique(array_merge(
			(array) ($research['warnings'] ?? array()),
			(array) ($images['warnings'] ?? array())
		)));

		$manifest = array(
			'source_urls'        => $research['source_urls'] ?? array(),
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
			'confidence_score'   => $research['confidence_score'] ?? 0,
			'safety'             => $research['safety'],
			'rebuild_locks'      => array(
				'backup_created' => true,
				'backup_meta' => '_komarena_pf_backup_before_rebuild',
				'title_locked' => !$allow_title_update,
				'image_locked' => !$allow_image_update,
				'price_locked' => !$allow_price_update && (bool) $old_price,
				'category_locked' => !$allow_category_update,
				'stock_locked' => true,
				'suggested_title' => $suggested_title,
				'preserved_title' => $old_title,
			),
		);

		$qa = $this->plugin->qa->run($product_id, $manifest);
		$manifest['qa_status'] = $qa['status'];
		$manifest['ready_to_publish'] = $qa['ready_to_publish'];
		$this->save_manifest_meta($product_id, $manifest);
		update_post_meta($product_id, '_komarena_pf_status', $qa['ready_to_publish'] ? 'ready_to_publish' : 'needs_review');
		update_post_meta($product_id, '_komarena_pf_ready_to_publish', $qa['ready_to_publish'] ? '1' : '0');

		if ($qa['ready_to_publish'] && $this->plugin->settings->get('auto_publish', 0)) {
			wp_update_post(array('ID' => $product_id, 'post_status' => 'publish'));
		}

		$this->plugin->logger->log('info', 'Prebudovanie produktu dokončené.', 'prebudovanie', array(
			'product_id' => $product_id,
			'qa'         => $qa,
			'manifest'   => $manifest,
		), null, $product_id);
		$this->plugin->memory->learn_from_product($product_id, $research, $manifest);

		return array(
			'product_id'       => $product_id,
			'ready_to_publish' => $qa['ready_to_publish'],
			'qa'               => $qa,
			'manifest'         => $manifest,
		);
	}

	public function cleanup_bad_product_images($args = array()) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.');
		}

		$limit = max(1, min(500, absint($args['limit'] ?? 100)));
		$product_ids = array_values(array_filter(array_map('absint', (array) ($args['product_ids'] ?? array()))));
		if (empty($product_ids)) {
			$query = new WP_Query(array(
				'post_type'      => 'product',
				'post_status'    => array('publish', 'draft', 'pending', 'private'),
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_komarena_pf_manifest',
						'compare' => 'EXISTS',
					),
				),
			));
			$product_ids = array_map('absint', $query->posts);
		}

		$title_restores = $this->restore_known_hotfix_titles();
		$items = array();
		$checked = 0;
		$changed = 0;
		$detached_total = 0;

		foreach ($product_ids as $product_id) {
			$checked++;
			$product = wc_get_product($product_id);
			if (!$product) {
				continue;
			}

			$manifest = json_decode((string) get_post_meta($product_id, '_komarena_pf_manifest', true), true);
			if (!is_array($manifest)) {
				$manifest = array();
			}

			$bad_ids = $this->bad_image_ids_from_manifest($manifest);
			$bad_ids = array_values(array_unique(array_filter(array_map('absint', $bad_ids))));
			if (empty($bad_ids)) {
				continue;
			}

			$featured = has_post_thumbnail($product_id) ? absint(get_post_thumbnail_id($product_id)) : 0;
			$gallery = array_values(array_filter(array_map('absint', $product->get_gallery_image_ids())));
			$current_ids = array_values(array_unique(array_filter(array_merge(array($featured), $gallery))));
			$attached_bad = array_values(array_intersect($current_ids, $bad_ids));
			$bad_urls = $this->attachment_urls($bad_ids);
			$description = (string) $product->get_description();
			$description_had_bad = $this->description_contains_urls($description, $bad_urls);

			if (empty($attached_bad) && !$description_had_bad) {
				continue;
			}

			$previous_backup_raw = (string) get_post_meta($product_id, '_komarena_pf_backup_before_rebuild', true);
			$backup = $this->create_backup_before_rebuild($product_id, $product);
			if (is_wp_error($backup)) {
				$items[] = array(
					'product_id' => $product_id,
					'title'      => get_the_title($product_id),
					'status'     => 'failed',
					'message'    => $backup->get_error_message(),
				);
				continue;
			}

			$restored = $this->restore_images_from_backup_if_safe($product_id, $bad_ids, $previous_backup_raw);
			$product = wc_get_product($product_id);
			if (!$restored) {
				if ($featured && in_array($featured, $bad_ids, true)) {
					delete_post_thumbnail($product_id);
					$product->set_image_id(0);
				}
				$product->set_gallery_image_ids(array_values(array_diff($gallery, $bad_ids)));
			}

			if ($description_had_bad) {
				$product->set_description($this->remove_bad_image_tags_from_description($description, $bad_urls));
			}
			$product->save();

			foreach ($attached_bad as $id) {
				update_post_meta($id, '_komarena_image_review_status', 'detached_bad_product_image');
				update_post_meta($id, '_komarena_image_detached_by_cleanup', current_time('mysql'));
			}

			$manifest['ready_to_publish'] = false;
			$manifest['qa_status'] = 'needs_review';
			$manifest['cleanup_bad_product_images'] = array(
				'checked_at' => current_time('mysql'),
				'detached_attachment_ids' => $attached_bad,
				'bad_attachment_ids' => $bad_ids,
				'restored_from_backup' => (bool) $restored,
				'description_cleaned' => (bool) $description_had_bad,
				'media_deleted' => false,
			);
			$manifest['warnings'] = array_values(array_unique(array_merge(
				(array) ($manifest['warnings'] ?? array()),
				array('Upratanie odpojilo neoverené alebo nepresné produktové obrázky. Knižnica médií nebola mazaná.')
			)));
			$this->save_manifest_meta($product_id, $manifest);
			update_post_meta($product_id, '_komarena_pf_status', 'needs_review');
			update_post_meta($product_id, '_komarena_pf_ready_to_publish', '0');

			$qa = $this->plugin->qa->run($product_id, $manifest);
			$manifest['qa_status'] = $qa['status'];
			$manifest['ready_to_publish'] = $qa['ready_to_publish'];
			$this->save_manifest_meta($product_id, $manifest);

			$changed++;
			$detached_total += count($attached_bad);
			$items[] = array(
				'product_id' => $product_id,
				'title'      => get_the_title($product_id),
				'status'     => 'cleaned',
				'detached_attachment_ids' => $attached_bad,
				'restored_from_backup' => (bool) $restored,
				'description_cleaned' => (bool) $description_had_bad,
				'qa_status' => $qa['status'],
			);
		}

		$result = array(
			'status' => 'done',
			'checked' => $checked,
			'changed' => $changed,
			'detached_images' => $detached_total,
			'media_deleted' => false,
			'title_restores' => $title_restores,
			'items' => $items,
		);
		$this->plugin->logger->log('warning', 'Upratanie nevhodných produktových obrázkov dokončené.', 'upratanie-obrázkov', $result);

		return $result;
	}

	public function restore_known_hotfix_titles() {
		$titles = array(
			2922 => 'ESPHome školský základný kit – NodeMCU, DHT22, breadboard a vodiče',
			2923 => 'Home Assistant miestnostný senzor kit – ESP32, BME280 a BH1750',
			2924 => 'ESPHome RFID prístupový kit – ESP32 a RC522 NFC/RFID modul',
			2925 => 'ESPHome mini dashboard kit – ESP32 a OLED displej pre stav domácnosti',
			2921 => 'ESPHome štartovací kit – ESP32 DevKit, BME280 a OLED displej',
		);

		$out = array();
		foreach ($titles as $product_id => $title) {
			$current = get_post_field('post_title', $product_id);
			if ('' === (string) $current) {
				$out[] = array('product_id' => $product_id, 'status' => 'missing_product');
				continue;
			}
			if ((string) $current === (string) $title) {
				$out[] = array('product_id' => $product_id, 'status' => 'unchanged', 'title' => $title);
				continue;
			}

			update_post_meta($product_id, '_komarena_pf_title_before_hotfix_restore', $current);
			$updated = wp_update_post(array(
				'ID' => $product_id,
				'post_title' => $title,
			), true);
			$out[] = array(
				'product_id' => $product_id,
				'status' => is_wp_error($updated) ? 'failed' : 'restored',
				'old_title' => $current,
				'title' => $title,
				'error' => is_wp_error($updated) ? $updated->get_error_message() : '',
			);
		}

		return $out;
	}

	private function existing_images($product_id, $research = array()) {
		$product = wc_get_product($product_id);
		$ids = array();
		if (has_post_thumbnail($product_id)) {
			$ids[] = get_post_thumbnail_id($product_id);
		}
		if ($product) {
			$ids = array_merge($ids, $product->get_gallery_image_ids());
		}

		$urls = array();
		foreach ($ids as $id) {
			$url = wp_get_attachment_url($id);
			if ($url) {
				$urls[] = $url;
			}
		}

		return array(
			'image_ids'  => array_map('absint', $ids),
			'image_urls' => $urls,
			'image_mode' => 'existing_preserved',
			'image_provenance' => $this->existing_image_provenance($ids, $research),
			'warnings'   => array(),
		);
	}

	private function images_for_content($images) {
		$out = $images;
		$urls = array();
		$roles = array('main', 'angle', 'detail', 'technical');
		foreach ((array) ($images['image_provenance'] ?? array()) as $index => $item) {
			if (!$this->image_item_is_final_for_content($item)) {
				continue;
			}
			$role = sanitize_key($item['role'] ?? ($roles[$index] ?? ''));
			$url = esc_url_raw($item['url'] ?? '');
			if ($role && $url) {
				$urls[$role] = $url;
			}
		}
		$out['image_urls'] = $urls;
		if (count($urls) < 4) {
			$out['warnings'][] = 'Do HTML popisu boli vložené iba finálne overené obrázky. Neoverené alebo kontrolné obrázky boli z popisu vynechané.';
		}

		return $out;
	}

	private function image_item_is_final_for_content($item) {
		if (!is_array($item)) {
			return false;
		}
		if (empty($item['is_original_or_real']) || empty($item['is_final_eligible'])) {
			return false;
		}
		if (!empty($item['ai_generated']) || !empty($item['generated'])) {
			return false;
		}
		$mode = sanitize_key($item['mode'] ?? '');
		if (in_array($mode, array('placeholder_generated_for_review', 'illustrated_fallback_colored_pencil', 'generated_review_only'), true)) {
			return false;
		}

		return true;
	}

	private function create_backup_before_rebuild($product_id, $product = null) {
		$product_id = absint($product_id);
		$product = $product ? $product : wc_get_product($product_id);
		if (!$product) {
			return new WP_Error('komarena_pf_backup_product_missing', 'Produkt neexistuje, záloha pred prebudovaním sa nedá vytvoriť.');
		}

		$backup = array(
			'created_at' => current_time('mysql'),
			'agent_version' => KOMARENA_PF_VERSION,
			'product_id' => $product_id,
			'title' => $product->get_name(),
			'slug' => get_post_field('post_name', $product_id),
			'post_status' => get_post_status($product_id),
			'short_description' => $product->get_short_description(),
			'description' => $product->get_description(),
			'featured_image_id' => has_post_thumbnail($product_id) ? absint(get_post_thumbnail_id($product_id)) : 0,
			'gallery_image_ids' => array_map('absint', $product->get_gallery_image_ids()),
			'yoast_title' => get_post_meta($product_id, '_yoast_wpseo_title', true),
			'yoast_metadesc' => get_post_meta($product_id, '_yoast_wpseo_metadesc', true),
			'yoast_focuskw' => get_post_meta($product_id, '_yoast_wpseo_focuskw', true),
			'category_ids' => $this->category_ids($product_id),
			'regular_price' => $product->get_regular_price(),
			'price' => $product->get_price(),
			'sale_price' => $product->get_sale_price(),
			'sku' => $product->get_sku(),
			'ean' => get_post_meta($product_id, '_komarena_ean', true),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status' => $product->get_stock_status(),
		);

		$encoded = wp_json_encode($backup, defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0);
		if (!$encoded) {
			return new WP_Error('komarena_pf_backup_encode_failed', 'Zálohu pred prebudovaním sa nepodarilo zakódovať.');
		}
		$previous = (string) get_post_meta($product_id, '_komarena_pf_backup_before_rebuild', true);
		if ('' !== $previous) {
			update_post_meta($product_id, '_komarena_pf_backup_before_rebuild_previous', wp_slash($previous));
		}
		update_post_meta($product_id, '_komarena_pf_backup_before_rebuild', wp_slash($encoded));

		return $backup;
	}

	private function restore_images_from_backup_if_safe($product_id, $bad_ids, $raw = '') {
		if ('' === (string) $raw) {
			$raw = (string) get_post_meta($product_id, '_komarena_pf_backup_before_rebuild_previous', true);
		}
		$backup = json_decode($raw, true);
		if (!is_array($backup)) {
			return false;
		}

		$featured = absint($backup['featured_image_id'] ?? 0);
		$gallery = array_values(array_filter(array_map('absint', (array) ($backup['gallery_image_ids'] ?? array()))));
		$bad_ids = array_map('absint', (array) $bad_ids);
		if ($featured && in_array($featured, $bad_ids, true)) {
			$featured = 0;
		}
		$gallery = array_values(array_diff($gallery, $bad_ids));
		if (!$featured && empty($gallery)) {
			return false;
		}

		$product = wc_get_product($product_id);
		if (!$product) {
			return false;
		}
		if ($featured) {
			$product->set_image_id($featured);
		}
		$product->set_gallery_image_ids($gallery);
		$product->save();

		return true;
	}

	private function category_ids($product_id) {
		$ids = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
		if (is_wp_error($ids)) {
			return array();
		}

		return array_map('absint', (array) $ids);
	}

	private function bad_image_ids_from_manifest($manifest) {
		$ids = array();
		foreach ((array) ($manifest['image_provenance'] ?? array()) as $item) {
			if ($this->image_item_is_bad($item)) {
				$ids[] = absint($item['attachment_id'] ?? 0);
			}
		}

		return $ids;
	}

	private function image_item_is_bad($item) {
		if (!is_array($item)) {
			return false;
		}
		$mode = sanitize_key($item['mode'] ?? '');
		$source_type = sanitize_key($item['source_type'] ?? '');
		$permission = sanitize_key($item['usage_permission'] ?? '');
		if (in_array($mode, array('placeholder_generated_for_review', 'generated_review_only', 'illustrated_fallback_colored_pencil'), true)) {
			return true;
		}
		if ('generated_review_only' === $source_type) {
			return true;
		}
		if ('sideloaded_verified_source' === $mode && empty($item['exact_image_match'])) {
			return true;
		}
		if ('needs_rights_review' === $permission || 'blocked_for_final' === $permission) {
			return true;
		}
		if (array_key_exists('is_final_eligible', $item) && empty($item['is_final_eligible'])) {
			return true;
		}

		return false;
	}

	private function attachment_urls($ids) {
		$out = array();
		foreach ((array) $ids as $id) {
			$url = wp_get_attachment_url(absint($id));
			if ($url) {
				$out[] = esc_url_raw($url);
			}
		}

		return array_values(array_unique($out));
	}

	private function description_contains_urls($description, $urls) {
		foreach ((array) $urls as $url) {
			if ($url && false !== strpos((string) $description, (string) $url)) {
				return true;
			}
		}

		return false;
	}

	private function remove_bad_image_tags_from_description($description, $urls) {
		foreach ((array) $urls as $url) {
			if (!$url) {
				continue;
			}
			$pattern = '/<img\b[^>]*src=["\']' . preg_quote($url, '/') . '["\'][^>]*>/i';
			$description = preg_replace($pattern, '', (string) $description);
		}

		return wp_kses_post($description);
	}

	private function save_manifest_meta($product_id, $manifest) {
		$options = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0;
		$encoded = wp_json_encode($manifest, $options);
		if (false === $encoded) {
			$encoded = wp_json_encode($manifest);
		}
		update_post_meta($product_id, '_komarena_pf_manifest', wp_slash((string) $encoded));
	}

	private function existing_image_provenance($ids, $research = array()) {
		$out = array();
		$roles = array('main', 'angle', 'detail', 'technical');
		foreach (array_values(array_map('absint', (array) $ids)) as $index => $id) {
			$url = wp_get_attachment_url($id);
			$role = $roles[$index] ?? ('existing_' . ($index + 1));
			$source_url = esc_url_raw(get_post_meta($id, '_komarena_image_source_url', true));
			$usage_permission = sanitize_key(get_post_meta($id, '_komarena_image_usage_permission', true));
			$watermark_status = sanitize_key(get_post_meta($id, '_komarena_image_watermark_status', true));
			$seller_logo_status = sanitize_key(get_post_meta($id, '_komarena_image_seller_logo_status', true));
			$ai_generated = (bool) get_post_meta($id, '_komarena_image_ai_generated', true);
			$is_final = in_array($usage_permission, array('allowed', 'allowed_internal', 'own_photo', 'licensed', 'manufacturer_allowed'), true) && 'clean' === $watermark_status && 'clean' === $seller_logo_status && !$ai_generated;
			$is_approved_internal = 'own_photo' === $usage_permission && 'clean' === $watermark_status && 'clean' === $seller_logo_status && !$ai_generated;

			$out[$role] = array(
				'role'               => $role,
				'mode'               => 'existing_preserved',
				'source_url'         => $source_url,
				'source_host'        => $source_url ? wp_parse_url($source_url, PHP_URL_HOST) : '',
				'source_type'        => $source_url && false !== strpos($source_url, 'komarena.sk') ? 'internal_komarena_media' : 'existing_media_library',
				'attachment_id'      => $id,
				'url'                => $url ? $url : '',
				'usage_permission'   => $usage_permission ? $usage_permission : 'needs_origin_confirmation',
				'watermark_status'   => $watermark_status ? $watermark_status : 'unknown',
				'seller_logo_status' => $seller_logo_status ? $seller_logo_status : 'unknown',
				'ai_generated'       => $ai_generated,
				'generated'          => false,
				'is_original_or_real'=> true,
				'exact_image_match'  => $is_approved_internal,
				'exact_image_match_reason' => $is_approved_internal ? 'interné schválenie vlastnej fotografie KomArena' : 'existujúce médium potrebuje výslovné potvrdenie pôvodu a presnej zhody',
				'is_final_eligible'  => $is_final && $is_approved_internal,
				'checked_at'         => current_time('mysql'),
				'notes'              => 'Zachovaný existujúci obrázok z knižnice médií; finálna vhodnosť vyžaduje vyplnené obrazové meta polia KomArena a potvrdenie presnej zhody.',
			);
		}

		return $out;
	}
}
