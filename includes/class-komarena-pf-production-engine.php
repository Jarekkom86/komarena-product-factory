<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Production_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function activate($args = array()) {
		$args = is_array($args) ? $args : array();
		$settings = $this->plugin->settings->all();
		$settings = array_merge($settings, $this->production_preset());
		$settings['production_mode'] = 1;
		$settings['production_activated_at'] = current_time('mysql');
		$settings['production_note'] = sanitize_text_field($args['note'] ?? 'Ostrá prevádzka aktivovaná.');

		update_option(KomArena_PF_Settings::OPTION, $settings);
		wp_cache_delete(KomArena_PF_Settings::OPTION, 'options');
		$stored = get_option(KomArena_PF_Settings::OPTION, array());
		if (!is_array($stored) || empty($stored['production_mode'])) {
			delete_option(KomArena_PF_Settings::OPTION);
			add_option(KomArena_PF_Settings::OPTION, $settings, '', false);
			wp_cache_delete(KomArena_PF_Settings::OPTION, 'options');
		}
		$this->plugin->ensure_autopilot_cron();

		$result = $this->status();
		$this->plugin->logger->log('info', 'Ostrá prevádzka bola aktivovaná.', 'ostra-prevadzka', $result);

		return $result;
	}

	public function status() {
		$settings = $this->plugin->settings->all();
		$ready_products = $this->count_ready_unpublished_products();

		return array(
			'status' => !empty($settings['production_mode']) ? 'active' : 'inactive',
			'production_mode' => (int) ($settings['production_mode'] ?? 0),
			'activated_at' => (string) ($settings['production_activated_at'] ?? ''),
			'auto_publish' => (int) ($settings['auto_publish'] ?? 0),
			'agent_autopilot' => (int) ($settings['agent_autopilot'] ?? 0),
			'agent_full_site_autopilot' => (int) ($settings['agent_full_site_autopilot'] ?? 0),
			'agent_task_autopilot' => (int) ($settings['agent_task_autopilot'] ?? 0),
			'agent_repair_live_products' => (int) ($settings['agent_repair_live_products'] ?? 0),
			'agent_bulk_rebuild_lock' => (int) ($settings['agent_bulk_rebuild_lock'] ?? 1),
			'strict_source_verification' => (int) ($settings['strict_source_verification'] ?? 1),
			'require_verified_product_facts' => (int) ($settings['require_verified_product_facts'] ?? 1),
			'require_real_product_images' => (int) ($settings['require_real_product_images'] ?? 1),
			'agent_auto_image_recovery' => (int) ($settings['agent_auto_image_recovery'] ?? 1),
			'ready_unpublished_products' => $ready_products,
			'hard_gates' => array(
				'products' => 'Publikovanie produktu je povolené iba po úspešnej kontrole kvality, overených zdrojoch, overených faktoch, reálnych obrázkoch a presnej zhode obrázkov.',
				'images' => 'AI alebo kreslené obrázky nesmú byť finálne produktové obrázky.',
				'prices' => 'Ceny sa nemenia automaticky mimo produktovej tvorby alebo explicitnej opravy.',
				'plugins' => 'Doplnky sa nevypínajú bez explicitného príkazu.',
				'legal_pages' => 'Informačné stránky sa pripravujú ako koncepty, publikovanie vyžaduje finálnu kontrolu obsahu.',
			),
		);
	}

	public function publish_ready_products($limit = 20) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.');
		}

		$limit = max(1, min(100, absint($limit)));
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('draft', 'pending', 'private'),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_komarena_pf_ready_to_publish',
					'value' => '1',
				),
			),
		));

		$published = 0;
		$needs_review = 0;
		$failed = 0;
		$items = array();

		foreach ((array) $query->posts as $product_id) {
			$product_id = absint($product_id);
			$manifest = json_decode((string) get_post_meta($product_id, '_komarena_pf_manifest', true), true);
			if (!is_array($manifest)) {
				$manifest = array();
			}

			$qa = $this->plugin->qa->run($product_id, $manifest);
			$manifest['qa_status'] = $qa['status'];
			$manifest['ready_to_publish'] = $qa['ready_to_publish'];
			$this->save_manifest_meta($product_id, $manifest);
			update_post_meta($product_id, '_komarena_pf_status', $qa['ready_to_publish'] ? 'ready_to_publish' : 'needs_review');
			update_post_meta($product_id, '_komarena_pf_ready_to_publish', $qa['ready_to_publish'] ? '1' : '0');

			if (empty($qa['ready_to_publish'])) {
				$needs_review++;
				$items[] = array(
					'product_id' => $product_id,
					'title' => get_the_title($product_id),
					'status' => 'needs_review',
					'missing' => array_values((array) ($qa['missing'] ?? array())),
				);
				continue;
			}

			$updated = wp_update_post(array(
				'ID' => $product_id,
				'post_status' => 'publish',
			), true);
			if (is_wp_error($updated)) {
				$failed++;
				$items[] = array(
					'product_id' => $product_id,
					'title' => get_the_title($product_id),
					'status' => 'failed',
					'message' => $updated->get_error_message(),
				);
				continue;
			}

			update_post_meta($product_id, '_komarena_pf_published_by_production_agent', current_time('mysql'));
			$published++;
			$items[] = array(
				'product_id' => $product_id,
				'title' => get_the_title($product_id),
				'status' => 'published',
				'permalink' => get_permalink($product_id),
			);
		}

		$result = array(
			'status' => $failed ? 'needs_review' : 'done',
			'checked' => count((array) $query->posts),
			'published' => $published,
			'needs_review' => $needs_review,
			'failed' => $failed,
			'items' => $items,
			'finished_at' => current_time('mysql'),
		);

		update_option('komarena_pf_last_production_publish', $result, false);
		$this->plugin->logger->log($failed ? 'warning' : 'info', 'Produkty pripravene na publikovanie boli spracovane ostrym agentom.', 'ostra-prevadzka', $result);

		return $result;
	}

	public function run_cycle($args = array()) {
		$args = is_array($args) ? $args : array();
		$limit = max(1, min(100, absint($args['limit'] ?? 20)));

		$summary = array(
			'status' => 'done',
			'started_at' => current_time('mysql'),
			'production' => $this->status(),
			'agent' => null,
			'image_recovery' => null,
			'publish_ready_products' => null,
		);

		if (empty($summary['production']['production_mode'])) {
			$summary['production'] = $this->activate(array('note' => 'Ostrá prevádzka aktivovaná produkčným cyklom.'));
		}

		$summary['agent'] = $this->plugin->agent->run(array('trigger' => 'production_cycle'));
		if (!empty($summary['production']['agent_auto_image_recovery']) && !empty($this->plugin->image_recovery)) {
			$summary['image_recovery'] = $this->plugin->image_recovery->image_recovery_run($limit);
		}
		$summary['publish_ready_products'] = $this->publish_ready_products($limit);
		if (is_wp_error($summary['publish_ready_products'])) {
			$summary['status'] = 'needs_review';
			$summary['publish_ready_products'] = array(
				'status' => 'failed',
				'message' => $summary['publish_ready_products']->get_error_message(),
			);
		}

		$summary['finished_at'] = current_time('mysql');
		update_option('komarena_pf_last_production_cycle', $summary, false);

		return $summary;
	}

	private function production_preset() {
		return array(
			'auto_publish' => 1,
			'agent_autopilot' => 1,
			'agent_retry_failed' => 1,
			'agent_scheduled_audit' => 1,
			'agent_rebuild_own_products' => 1,
			'agent_bulk_rebuild_lock' => 0,
			'agent_repair_live_products' => 1,
			'agent_task_autopilot' => 1,
			'agent_full_site_autopilot' => 1,
			'agent_auto_seed_sources' => 1,
			'agent_active_source_discovery' => 1,
			'agent_auto_image_recovery' => 1,
			'agent_auto_plugin_audit' => 1,
			'agent_auto_homepage_layout' => 1,
			'agent_auto_cleanup_plan' => 1,
			'agent_audit_on_manual' => 1,
			'agent_auto_repair_audit' => 1,
			'agent_auto_autonomy_supervisor' => 1,
			'agent_autonomy_safe_repairs' => 1,
			'agent_autonomy_law_watch' => 1,
			'agent_autonomy_price_watch' => 1,
			'strict_source_verification' => 1,
			'require_verified_product_facts' => 1,
			'require_real_product_images' => 1,
			'allow_illustrated_image_fallback' => 0,
			'allow_placeholder_images' => 0,
			'auto_apply_attributes' => 1,
			'duplicate_strategy' => 'reuse_review',
			'queue_batch_size' => 5,
			'agent_task_batch_size' => 5,
			'agent_audit_repair_limit' => 5,
			'agent_rebuild_limit' => 3,
		);
	}

	private function count_ready_unpublished_products() {
		if (!$this->plugin->has_woocommerce()) {
			return 0;
		}

		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('draft', 'pending', 'private'),
			'posts_per_page' => 1000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => '_komarena_pf_ready_to_publish',
					'value' => '1',
				),
			),
		));

		return count((array) $query->posts);
	}

	private function save_manifest_meta($product_id, $manifest) {
		$options = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0;
		$encoded = wp_json_encode($manifest, $options);
		if (false === $encoded) {
			$encoded = wp_json_encode($manifest);
		}
		update_post_meta($product_id, '_komarena_pf_manifest', wp_slash((string) $encoded));
	}
}
