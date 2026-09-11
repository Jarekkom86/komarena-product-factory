<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-settings.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-logger.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-memory-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-supplier-import-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-duplicate-detector.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-attribute-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-safety-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-queue-manager.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-research-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-pricing-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-sku-ean-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-link-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-image-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-image-recovery-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-content-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-csv-builder-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-qa-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-product-creator.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-audit-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-rebuild-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-site-layout-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-plugin-housekeeper-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-autonomy-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-legal-page-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-production-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-task-manager.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-agent-orchestrator.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-variation-migration-engine.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-rest-controller.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-admin-ui.php';

final class KomArena_Product_Factory {
	private static $instance = null;

	public $settings;
	public $logger;
	public $memory;
	public $supplier;
	public $duplicates;
	public $attributes;
	public $safety;
	public $queue;
	public $research;
	public $pricing;
	public $sku_ean;
	public $links;
	public $images;
	public $image_recovery;
	public $content;
	public $csv_builder;
	public $qa;
	public $creator;
	public $audit;
	public $rebuild;
	public $layout;
	public $housekeeper;
	public $autonomy;
	public $legal_pages;
	public $production;
	public $tasks;
	public $agent;
	public $variation_migration;
	public $rest;
	public $admin;

	public static function instance() {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings = new KomArena_PF_Settings();
		$this->logger   = new KomArena_PF_Logger();
		$this->memory   = new KomArena_PF_Memory_Engine($this);
		$this->supplier = new KomArena_PF_Supplier_Import_Engine($this);
		$this->duplicates = new KomArena_PF_Duplicate_Detector($this);
		$this->attributes = new KomArena_PF_Attribute_Engine($this);
		$this->safety   = new KomArena_PF_Safety_Engine($this);
		$this->queue    = new KomArena_PF_Queue_Manager($this);
		$this->research = new KomArena_PF_Research_Engine($this);
		$this->pricing  = new KomArena_PF_Pricing_Engine($this);
		$this->sku_ean  = new KomArena_PF_SKU_EAN_Engine($this);
		$this->links    = new KomArena_PF_Link_Engine($this);
		$this->images   = new KomArena_PF_Image_Engine($this);
		$this->image_recovery = new KomArena_PF_Image_Recovery_Engine($this);
		$this->content  = new KomArena_PF_Content_Engine($this);
		$this->csv_builder = new KomArena_PF_CSV_Builder_Engine($this);
		$this->qa       = new KomArena_PF_QA_Engine($this);
		$this->creator  = new KomArena_PF_Product_Creator($this);
		$this->audit    = new KomArena_PF_Audit_Engine($this);
		$this->rebuild  = new KomArena_PF_Rebuild_Engine($this);
		$this->layout   = new KomArena_PF_Site_Layout_Engine($this);
		$this->housekeeper = new KomArena_PF_Plugin_Housekeeper_Engine($this);
		$this->autonomy = new KomArena_PF_Autonomy_Engine($this);
		$this->legal_pages = new KomArena_PF_Legal_Page_Engine($this);
		$this->production = new KomArena_PF_Production_Engine($this);
		$this->tasks    = new KomArena_PF_Task_Manager($this);
		$this->agent    = new KomArena_PF_Agent_Orchestrator($this);
		$this->variation_migration = new KomArena_PF_Variation_Migration_Engine($this);
		$this->rest     = new KomArena_PF_REST_Controller($this);

		add_action('init', array($this, 'register_meta'));
		add_action('init', array($this->layout, 'register_shortcodes'), 20);
		add_action('init', array($this, 'maybe_upgrade'));
		add_action('init', array($this, 'ensure_autopilot_cron'));
		add_action('admin_init', array($this->settings, 'register'));
		add_action('wp_enqueue_scripts', array($this->layout, 'enqueue_assets'));
		add_filter('cron_schedules', array($this, 'cron_schedules'));
		add_action('komarena_pf_process_queue', array($this->queue, 'run_scheduled'));
		add_action('komarena_pf_process_queue_once', array($this->queue, 'run_scheduled'));
		add_action('komarena_pf_agent_tick', array($this->agent, 'run_scheduled'));
		add_action('komarena_pf_agent_tick_once', array($this->agent, 'run_scheduled'));
		add_action('komarena_pf_task_tick', array($this->tasks, 'run_scheduled'));
		add_action('komarena_pf_task_tick_once', array($this->tasks, 'run_scheduled'));

		if (is_admin()) {
			$this->admin = new KomArena_PF_Admin_UI($this);
		}
	}

	public function register_meta() {
		$meta_keys = array(
			'_komarena_pf_status',
			'_komarena_pf_ready_to_publish',
			'_komarena_pf_manifest',
			'_komarena_pf_audit_issues',
			'_komarena_pf_qa_report',
			'_komarena_pf_review_notes',
			'_komarena_pf_supplier_data',
			'_komarena_pf_duplicate_of',
			'_komarena_pf_image_status',
			'_komarena_pf_image_block_reason',
			'_komarena_pf_image_source_count',
			'_komarena_pf_image_exact_match_score',
			'_komarena_pf_image_rights_confidence',
			'_komarena_pf_image_watermark_check',
			'_komarena_pf_image_logo_check',
			'_komarena_pf_image_ai_check',
			'_komarena_pf_image_last_reviewed_at',
			'_komarena_pf_image_last_reviewed_by',
			'_komarena_pf_publish_gate_status',
			'_komarena_pf_publish_gate_reason',
			'image_status',
			'image_block_reason',
			'image_source_count',
			'image_exact_match_score',
			'image_rights_confidence',
			'image_watermark_check',
			'image_logo_check',
			'image_ai_check',
			'image_last_reviewed_at',
			'image_last_reviewed_by',
			'publish_gate_status',
			'publish_gate_reason',
			'_komarena_ean',
		);

		foreach ($meta_keys as $key) {
			register_post_meta('product', $key, array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'auth_callback'     => function() {
					return current_user_can('manage_woocommerce') || current_user_can('manage_options');
				},
				'sanitize_callback' => 'wp_kses_post',
			));
		}
	}

	public function has_woocommerce() {
		return class_exists('WooCommerce') && class_exists('WC_Product_Simple');
	}

	public function maybe_upgrade() {
		$installed = get_option('komarena_pf_version', '');
		if (KOMARENA_PF_VERSION !== $installed) {
			KomArena_Product_Factory_Activator::activate();
		}
	}

	public function capability() {
		return current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
	}

	public function cron_schedules($schedules) {
		if (!isset($schedules['komarena_pf_every_five_minutes'])) {
			$schedules['komarena_pf_every_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __('Každých päť minút - KomArena produktový agent', 'komarena-product-factory'),
			);
		}

		return $schedules;
	}

	public function ensure_autopilot_cron() {
		if (!$this->settings->get('agent_autopilot', 1)) {
			wp_clear_scheduled_hook('komarena_pf_process_queue');
			wp_clear_scheduled_hook('komarena_pf_agent_tick');
			wp_clear_scheduled_hook('komarena_pf_task_tick');
			wp_clear_scheduled_hook('komarena_pf_task_tick_once');
			return;
		}

		wp_clear_scheduled_hook('komarena_pf_process_queue');
		if (!wp_next_scheduled('komarena_pf_agent_tick')) {
			wp_schedule_event(time() + (2 * MINUTE_IN_SECONDS), 'komarena_pf_every_five_minutes', 'komarena_pf_agent_tick');
		}
		if (!$this->settings->get('agent_task_autopilot', 1)) {
			wp_clear_scheduled_hook('komarena_pf_task_tick');
			wp_clear_scheduled_hook('komarena_pf_task_tick_once');
			return;
		}
		if (!wp_next_scheduled('komarena_pf_task_tick')) {
			wp_schedule_event(time() + (3 * MINUTE_IN_SECONDS), 'komarena_pf_every_five_minutes', 'komarena_pf_task_tick');
		}
	}
}
