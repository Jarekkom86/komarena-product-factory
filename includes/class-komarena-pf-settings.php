<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Settings {
	const OPTION = 'komarena_pf_settings';

	public static function defaults() {
		return array(
			'default_stock'              => 10,
			'price_markup_percent'       => 45,
			'minimum_confidence'         => 0.65,
			'strict_source_verification' => 1,
			'require_verified_product_facts' => 1,
			'require_real_product_images'=> 1,
			'allow_illustrated_image_fallback' => 0,
			'allow_placeholder_images'   => 0,
			'production_mode'            => 0,
			'production_activated_at'    => '',
			'production_note'            => '',
			'auto_publish'               => 0,
			'agent_autopilot'            => 1,
			'agent_retry_failed'         => 1,
			'agent_scheduled_audit'      => 1,
			'agent_rebuild_own_products' => 1,
			'agent_bulk_rebuild_lock'    => 1,
			'agent_audit_interval_hours' => 24,
			'agent_rebuild_limit'        => 2,
			'agent_retry_limit'          => 3,
			'agent_task_autopilot'       => 1,
			'agent_task_batch_size'      => 3,
			'agent_task_max_attempts'    => 3,
			'agent_full_site_autopilot'  => 1,
			'agent_auto_seed_sources'    => 1,
			'agent_active_source_discovery' => 1,
			'agent_auto_image_recovery'  => 1,
			'agent_source_discovery_limit' => 12,
			'agent_auto_plugin_audit'    => 1,
			'agent_auto_homepage_layout' => 1,
			'agent_auto_cleanup_plan'    => 1,
			'agent_audit_on_manual'      => 1,
			'agent_full_audit_limit'     => 100,
			'agent_auto_repair_audit'    => 1,
			'agent_audit_repair_limit'   => 3,
			'agent_repair_live_products' => 0,
			'agent_auto_autonomy_supervisor' => 1,
			'agent_autonomy_audit_limit' => 100,
			'agent_autonomy_safe_repairs' => 1,
			'agent_autonomy_law_watch'   => 1,
			'agent_autonomy_price_watch' => 1,
			'duplicate_strategy'         => 'reuse_review',
			'auto_apply_attributes'      => 1,
			'supplier_fetch_timeout'     => 12,
			'supplier_import_limit'      => 20,
			'queue_batch_size'           => 3,
			'trusted_source_urls'        => '',
			'home_assistant_url'         => home_url('/?s=Home+Assistant'),
			'esphome_url'                => home_url('/?s=ESPHome'),
		);
	}

	public static function recommended_source_urls() {
		return array(
			'https://www.espressif.com/',
			'https://docs.espressif.com/',
			'https://www.home-assistant.io/',
			'https://esphome.io/',
			'https://www.raspberrypi.com/documentation/',
		);
	}

	public function all() {
		$settings = get_option(self::OPTION, array());
		if (!is_array($settings)) {
			$settings = array();
		}

		return wp_parse_args($settings, self::defaults());
	}

	public function get($key, $default = null) {
		$settings = $this->all();
		return array_key_exists($key, $settings) ? $settings[$key] : $default;
	}

	public function register() {
		register_setting('komarena_pf_settings', self::OPTION, array(
			'sanitize_callback' => array($this, 'sanitize'),
		));

		add_settings_section(
			'komarena_pf_main',
			__('KomArena produktový agent', 'komarena-product-factory'),
			'__return_false',
			'komarena_pf_settings'
		);
	}

	public function sanitize($input) {
		if (!is_array($input)) {
			$input = array();
		}

		$defaults = self::defaults();
		$output   = array();
		$current  = get_option(self::OPTION, array());
		if (!is_array($current)) {
			$current = array();
		}

		$output['default_stock']              = max(0, absint($input['default_stock'] ?? $defaults['default_stock']));
		$output['price_markup_percent']       = max(0, min(500, absint($input['price_markup_percent'] ?? $defaults['price_markup_percent'])));
		$output['minimum_confidence']         = max(0, min(1, (float) ($input['minimum_confidence'] ?? $defaults['minimum_confidence'])));
		$output['strict_source_verification'] = empty($input['strict_source_verification']) ? 0 : 1;
		$output['require_verified_product_facts'] = empty($input['require_verified_product_facts']) ? 0 : 1;
		$output['require_real_product_images']= empty($input['require_real_product_images']) ? 0 : 1;
		$output['allow_illustrated_image_fallback'] = empty($input['allow_illustrated_image_fallback']) ? 0 : 1;
		$output['allow_placeholder_images']   = empty($input['allow_placeholder_images']) ? 0 : 1;
		$output['production_mode']            = empty($input['production_mode']) ? 0 : 1;
		$output['production_activated_at']    = sanitize_text_field($input['production_activated_at'] ?? ($current['production_activated_at'] ?? ''));
		$output['production_note']            = sanitize_text_field($input['production_note'] ?? ($current['production_note'] ?? ''));
		$output['auto_publish']               = empty($input['auto_publish']) ? 0 : 1;
		$output['agent_autopilot']            = empty($input['agent_autopilot']) ? 0 : 1;
		$output['agent_retry_failed']         = empty($input['agent_retry_failed']) ? 0 : 1;
		$output['agent_scheduled_audit']      = empty($input['agent_scheduled_audit']) ? 0 : 1;
		$output['agent_rebuild_own_products'] = empty($input['agent_rebuild_own_products']) ? 0 : 1;
		$output['agent_bulk_rebuild_lock']    = empty($input['agent_bulk_rebuild_lock']) ? 0 : 1;
		$output['agent_audit_interval_hours'] = max(1, min(168, absint($input['agent_audit_interval_hours'] ?? $defaults['agent_audit_interval_hours'])));
		$output['agent_rebuild_limit']        = max(0, min(10, absint($input['agent_rebuild_limit'] ?? $defaults['agent_rebuild_limit'])));
		$output['agent_retry_limit']          = max(0, min(25, absint($input['agent_retry_limit'] ?? $defaults['agent_retry_limit'])));
		$output['agent_task_autopilot']       = empty($input['agent_task_autopilot']) ? 0 : 1;
		$output['agent_task_batch_size']      = max(1, min(25, absint($input['agent_task_batch_size'] ?? $defaults['agent_task_batch_size'])));
		$output['agent_task_max_attempts']    = max(1, min(10, absint($input['agent_task_max_attempts'] ?? $defaults['agent_task_max_attempts'])));
		$output['agent_full_site_autopilot']  = empty($input['agent_full_site_autopilot']) ? 0 : 1;
		$output['agent_auto_seed_sources']    = empty($input['agent_auto_seed_sources']) ? 0 : 1;
		$output['agent_active_source_discovery'] = empty($input['agent_active_source_discovery']) ? 0 : 1;
		$output['agent_auto_image_recovery']  = empty($input['agent_auto_image_recovery']) ? 0 : 1;
		$output['agent_source_discovery_limit'] = max(4, min(30, absint($input['agent_source_discovery_limit'] ?? $defaults['agent_source_discovery_limit'])));
		$output['agent_auto_plugin_audit']    = empty($input['agent_auto_plugin_audit']) ? 0 : 1;
		$output['agent_auto_homepage_layout'] = empty($input['agent_auto_homepage_layout']) ? 0 : 1;
		$output['agent_auto_cleanup_plan']    = empty($input['agent_auto_cleanup_plan']) ? 0 : 1;
		$output['agent_audit_on_manual']      = empty($input['agent_audit_on_manual']) ? 0 : 1;
		$output['agent_full_audit_limit']     = max(1, min(500, absint($input['agent_full_audit_limit'] ?? $defaults['agent_full_audit_limit'])));
		$output['agent_auto_repair_audit']    = empty($input['agent_auto_repair_audit']) ? 0 : 1;
		$output['agent_audit_repair_limit']   = max(0, min(25, absint($input['agent_audit_repair_limit'] ?? $defaults['agent_audit_repair_limit'])));
		$output['agent_repair_live_products'] = empty($input['agent_repair_live_products']) ? 0 : 1;
		$output['agent_auto_autonomy_supervisor'] = empty($input['agent_auto_autonomy_supervisor']) ? 0 : 1;
		$output['agent_autonomy_audit_limit'] = max(1, min(500, absint($input['agent_autonomy_audit_limit'] ?? $defaults['agent_autonomy_audit_limit'])));
		$output['agent_autonomy_safe_repairs'] = empty($input['agent_autonomy_safe_repairs']) ? 0 : 1;
		$output['agent_autonomy_law_watch']   = empty($input['agent_autonomy_law_watch']) ? 0 : 1;
		$output['agent_autonomy_price_watch'] = empty($input['agent_autonomy_price_watch']) ? 0 : 1;
		$strategy = sanitize_key($input['duplicate_strategy'] ?? $defaults['duplicate_strategy']);
		$output['duplicate_strategy']         = in_array($strategy, array('reuse_review', 'create_anyway'), true) ? $strategy : 'reuse_review';
		$output['auto_apply_attributes']      = empty($input['auto_apply_attributes']) ? 0 : 1;
		$output['supplier_fetch_timeout']     = max(3, min(30, absint($input['supplier_fetch_timeout'] ?? $defaults['supplier_fetch_timeout'])));
		$output['supplier_import_limit']      = max(1, min(100, absint($input['supplier_import_limit'] ?? $defaults['supplier_import_limit'])));
		$output['queue_batch_size']           = max(1, min(25, absint($input['queue_batch_size'] ?? $defaults['queue_batch_size'])));
		$output['home_assistant_url']         = esc_url_raw($input['home_assistant_url'] ?? $defaults['home_assistant_url']);
		$output['esphome_url']                = esc_url_raw($input['esphome_url'] ?? $defaults['esphome_url']);

		$urls = isset($input['trusted_source_urls']) ? (string) $input['trusted_source_urls'] : '';
		$clean_urls = array();
		foreach (preg_split('/\r\n|\r|\n/', $urls) as $url) {
			$url = trim($url);
			if ('' !== $url) {
				$clean_urls[] = esc_url_raw($url);
			}
		}
		$output['trusted_source_urls'] = implode("\n", array_filter($clean_urls));

		return $output;
	}
}
