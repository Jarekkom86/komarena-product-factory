<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_REST_Controller {
	private $plugin;
	private $namespace = 'komarena-pf/v1';

	public function __construct($plugin) {
		$this->plugin = $plugin;
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes() {
		register_rest_route($this->namespace, '/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'status'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/preflight', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'preflight'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/agent/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_agent'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/queue/enqueue', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'enqueue_products'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/queue/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_queue'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/tasks/create', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'create_task'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/tasks/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_tasks'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/audit/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_audit'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/review/list', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'review_list'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/rebuild', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'rebuild_product'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/qa', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'qa_product'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/approve-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'approve_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/find-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'find_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/fetch-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'fetch_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/qa-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'qa_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/standardize-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'standardize_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/upload-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'upload_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/repair-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'repair_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/image-recovery-run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'image_recovery_run'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/cleanup-bad-images', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'cleanup_bad_product_images'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/restore-hotfix-titles', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'restore_hotfix_titles'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/create-draft', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'create_product_draft'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/create-from-draft', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'create_product_from_draft'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/products/enrich-from-draft', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'enrich_product_from_draft'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/plugin-audit/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_plugin_audit'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/plugin-audit/deactivate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'deactivate_plugins'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/site-layout/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_site_layout'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/autonomy/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'autonomy_status'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/autonomy/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_autonomy'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/production/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'production_status'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/production/activate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'activate_production'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/production/publish-ready-products', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'publish_ready_products'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/production/run', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'run_production'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/legal-pages/create-draft', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array($this, 'create_legal_page_draft'),
			'permission_callback' => array($this, 'permission'),
		));

		register_rest_route($this->namespace, '/logs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array($this, 'logs'),
			'permission_callback' => array($this, 'permission'),
		));
	}

	public function permission($request = null) {
		return current_user_can($this->plugin->capability());
	}

	public function status($request = null) {
		$plugin_audit = get_option('komarena_pf_last_plugin_audit', array());
		$agent_summary = get_option('komarena_pf_last_agent_summary', array());
		$autonomy_report = get_option('komarena_pf_last_autonomy_report', array());

		return rest_ensure_response(array(
			'ok'                 => true,
			'version'            => KOMARENA_PF_VERSION,
			'wordpress_time'     => current_time('mysql'),
			'woocommerce_active' => $this->plugin->has_woocommerce(),
			'queue_counts'       => $this->plugin->queue->counts(),
			'task_counts'        => $this->plugin->tasks->counts(),
			'audit_counts'       => $this->plugin->audit->dashboard_counts(),
			'image_recovery_counts' => !empty($this->plugin->image_recovery) ? $this->plugin->image_recovery->counts() : array(),
			'last_agent_summary' => is_array($agent_summary) ? $agent_summary : array(),
			'last_autonomy_report' => is_array($autonomy_report) ? array(
				'status'       => $autonomy_report['status'] ?? '',
				'finished_at'  => $autonomy_report['finished_at'] ?? '',
				'warnings'     => count((array) ($autonomy_report['warnings'] ?? array())),
				'proposals'    => count((array) ($autonomy_report['proposals'] ?? array())),
				'safe_actions' => count((array) ($autonomy_report['safe_actions'] ?? array())),
			) : array(),
			'last_plugin_audit'  => is_array($plugin_audit) ? array(
				'status'  => $plugin_audit['status'] ?? '',
				'summary' => $plugin_audit['summary'] ?? array(),
			) : array(),
			'settings'           => array(
				'agent_autopilot'            => (int) $this->plugin->settings->get('agent_autopilot', 1),
				'agent_full_site_autopilot'  => (int) $this->plugin->settings->get('agent_full_site_autopilot', 1),
				'production_mode'            => (int) $this->plugin->settings->get('production_mode', 0),
				'auto_publish'               => (int) $this->plugin->settings->get('auto_publish', 0),
				'agent_bulk_rebuild_lock'    => (int) $this->plugin->settings->get('agent_bulk_rebuild_lock', 1),
				'agent_repair_live_products' => (int) $this->plugin->settings->get('agent_repair_live_products', 0),
				'strict_source_verification' => (int) $this->plugin->settings->get('strict_source_verification', 1),
				'require_verified_product_facts' => (int) $this->plugin->settings->get('require_verified_product_facts', 1),
				'require_real_product_images'=> (int) $this->plugin->settings->get('require_real_product_images', 1),
				'agent_auto_autonomy_supervisor' => (int) $this->plugin->settings->get('agent_auto_autonomy_supervisor', 1),
				'agent_autonomy_safe_repairs' => (int) $this->plugin->settings->get('agent_autonomy_safe_repairs', 1),
			),
		));
	}

	public function preflight($request = null) {
		$upload = wp_upload_dir();
		$upload_path = empty($upload['path']) ? '' : $upload['path'];

		return rest_ensure_response(array(
			'ok'        => true,
			'version'   => KOMARENA_PF_VERSION,
			'checks'    => $this->plugin->agent->preflight(),
			'platform'  => array(
				'wp_version'       => get_bloginfo('version'),
				'php_version'      => PHP_VERSION,
				'woocommerce'      => $this->plugin->has_woocommerce() && defined('WC_VERSION') ? WC_VERSION : '',
				'multisite'        => is_multisite(),
				'upload_path'      => $upload_path,
				'upload_writable'  => $upload_path && function_exists('wp_is_writable') ? wp_is_writable($upload_path) : null,
				'cron_disabled'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
				'gd_available'     => function_exists('imagecreatetruecolor'),
				'zip_available'    => class_exists('ZipArchive'),
			),
			'next_cron' => array(
				'agent' => wp_next_scheduled('komarena_pf_agent_tick'),
				'tasks' => wp_next_scheduled('komarena_pf_task_tick'),
			),
		));
	}

	public function run_agent(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$trigger = sanitize_key($params['trigger'] ?? 'pc_agent');
		$result = $this->plugin->agent->run(array('trigger' => $trigger ? $trigger : 'pc_agent'));

		return rest_ensure_response(array(
			'ok'      => true,
			'summary' => $result,
		));
	}

	public function autonomy_status(WP_REST_Request $request) {
		return rest_ensure_response(array(
			'ok'     => true,
			'report' => !empty($this->plugin->autonomy) ? $this->plugin->autonomy->last_report() : array(),
		));
	}

	public function run_autonomy(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$result = $this->plugin->autonomy->run_cycle(array(
			'trigger' => sanitize_key($params['trigger'] ?? 'rest'),
			'limit' => max(1, min(500, absint($params['limit'] ?? $this->plugin->settings->get('agent_autonomy_audit_limit', 100)))),
			'run_product_audit' => array_key_exists('run_product_audit', $params) ? !empty($params['run_product_audit']) : true,
			'safe_repairs' => array_key_exists('safe_repairs', $params) ? !empty($params['safe_repairs']) : (bool) $this->plugin->settings->get('agent_autonomy_safe_repairs', 1),
		));

		return rest_ensure_response(array(
			'ok'     => true,
			'report' => $result,
		));
	}

	public function production_status(WP_REST_Request $request) {
		return rest_ensure_response(array(
			'ok'         => true,
			'production' => $this->plugin->production->status(),
		));
	}

	public function activate_production(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		if ('GO_LIVE' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error('komarena_pf_production_confirm_missing', 'Ostrá prevádzka vyžaduje potvrdenie confirm=GO_LIVE.', array('status' => 400));
		}

		$result = $this->plugin->production->activate(array(
			'note' => sanitize_text_field($params['note'] ?? 'Ostrá prevádzka aktivovaná cez PC agenta.'),
		));

		return rest_ensure_response(array(
			'ok'         => true,
			'production' => $result,
		));
	}

	public function publish_ready_products(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$result = $this->plugin->production->publish_ready_products(absint($params['limit'] ?? 20));
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	public function run_production(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		if ('GO_LIVE' !== (string) ($params['confirm'] ?? '') && !$this->plugin->settings->get('production_mode', 0)) {
			return new WP_Error('komarena_pf_production_confirm_missing', 'Prvý ostrý beh vyžaduje potvrdenie confirm=GO_LIVE.', array('status' => 400));
		}

		$result = $this->plugin->production->run_cycle(array(
			'limit' => absint($params['limit'] ?? 20),
		));

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	public function create_legal_page_draft(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$type = sanitize_key($params['type'] ?? $params['legal_page_type'] ?? 'cookies');
		$result = $this->plugin->legal_pages->create_draft($type, array(
			'overwrite' => !empty($params['overwrite']),
		));
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	public function enqueue_products(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$queue_ids = array();
		if (!empty($params['text']) && is_scalar($params['text'])) {
			$queue_ids = array_merge($queue_ids, $this->enqueue_text((string) $params['text']));
		}

		if (!empty($params['products']) && is_array($params['products'])) {
			foreach ($params['products'] as $product) {
				if (is_scalar($product)) {
					$name = sanitize_text_field((string) $product);
					$payload = array();
				} elseif (is_array($product)) {
					$name = sanitize_text_field($product['name'] ?? $product['product_name'] ?? '');
					$payload = array(
						'source_urls'   => $this->urls($product['source_urls'] ?? array()),
						'image_sources' => $this->urls($product['image_sources'] ?? $product['image_urls'] ?? array()),
						'sku'           => sanitize_text_field($product['sku'] ?? ''),
						'ean'           => sanitize_text_field($product['ean'] ?? ''),
					);
				} else {
					continue;
				}

				if ('' === $name) {
					continue;
				}

				$queue_ids[] = $this->plugin->queue->insert($name, array_filter($payload));
			}
		}

		if (!empty($queue_ids) && $this->plugin->settings->get('agent_autopilot', 1)) {
			$this->plugin->queue->schedule_soon();
		}

		$summary = null;
		if (!empty($params['run_now']) && !empty($queue_ids)) {
			$summary = $this->plugin->agent->run(array('trigger' => 'pc_agent_enqueue'));
		}

		return rest_ensure_response(array(
			'ok'         => true,
			'created'    => count($queue_ids),
			'queue_ids'  => array_map('absint', $queue_ids),
			'run_summary'=> $summary,
		));
	}

	public function run_queue(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$limit = absint($params['limit'] ?? $this->plugin->settings->get('queue_batch_size', 3));
		$results = $this->plugin->queue->run_next($limit);

		return rest_ensure_response(array(
			'ok'        => true,
			'processed' => is_array($results) ? count($results) : 0,
			'results'   => $this->normalize_results($results),
			'counts'    => $this->plugin->queue->counts(),
		));
	}

	public function create_task(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$type = sanitize_key($params['task_type'] ?? $params['type'] ?? '');
		$title = sanitize_text_field($params['title'] ?? '');
		$payload = $this->clean_payload($params['payload'] ?? array());
		$priority = absint($params['priority'] ?? 10);
		$scheduled_at = sanitize_text_field($params['scheduled_at'] ?? '');

		$task_id = $this->plugin->tasks->create($type, $title, $payload, $priority, $scheduled_at);
		if (is_wp_error($task_id)) {
			return $task_id;
		}

		return rest_ensure_response(array(
			'ok'      => true,
			'task_id' => (int) $task_id,
		));
	}

	public function run_tasks(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$limit = absint($params['limit'] ?? $this->plugin->settings->get('agent_task_batch_size', 3));
		$results = $this->plugin->tasks->run_next($limit);

		return rest_ensure_response(array(
			'ok'        => true,
			'processed' => is_array($results) ? count($results) : 0,
			'results'   => $this->normalize_results($results),
		));
	}

	public function run_audit(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$limit = absint($params['limit'] ?? $this->plugin->settings->get('agent_full_audit_limit', 100));
		$result = $this->plugin->audit->audit_products($limit);
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'audit'  => $result,
			'counts' => $this->plugin->audit->dashboard_counts(),
		));
	}

	public function review_list(WP_REST_Request $request) {
		$limit = max(1, min(100, absint($request->get_param('limit') ?: 40)));
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => $limit,
			'meta_query'     => array(
				'relation' => 'OR',
				array('key' => '_komarena_pf_status', 'value' => 'needs_review'),
				array('key' => '_komarena_pf_audit_issues', 'compare' => 'EXISTS'),
				array('key' => '_komarena_pf_duplicate_of', 'compare' => 'EXISTS'),
			),
		));

		$items = array();
		foreach ($query->posts as $post) {
			$qa = json_decode((string) get_post_meta($post->ID, '_komarena_pf_qa_report', true), true);
			$audit = json_decode((string) get_post_meta($post->ID, '_komarena_pf_audit_issues', true), true);
			$manifest = json_decode((string) get_post_meta($post->ID, '_komarena_pf_manifest', true), true);
			if (!is_array($qa)) {
				$qa = array();
			}
			if (!is_array($audit)) {
				$audit = array();
			}
			if (!is_array($manifest)) {
				$manifest = array();
			}

			$items[] = array(
				'product_id'       => (int) $post->ID,
				'title'            => get_the_title($post),
				'post_status'      => get_post_status($post),
				'komarena_status'  => get_post_meta($post->ID, '_komarena_pf_status', true),
				'ready_to_publish' => '1' === (string) get_post_meta($post->ID, '_komarena_pf_ready_to_publish', true),
				'qa_status'        => $qa['status'] ?? '',
				'qa_missing'       => array_values((array) ($qa['missing'] ?? array())),
				'audit_issues'     => array_values((array) $audit),
				'image_mode'       => $manifest['image_mode'] ?? '',
				'source_status'    => $manifest['source_evidence']['status'] ?? '',
				'edit_url'         => get_edit_post_link($post->ID, 'raw'),
				'permalink'        => get_permalink($post->ID),
			);
		}

		return rest_ensure_response(array(
			'ok'    => true,
			'total' => count($items),
			'items' => $items,
		));
	}

	public function rebuild_product(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$product_id = absint($params['product_id'] ?? 0);
		if (!$product_id) {
			return new WP_Error('komarena_pf_rest_product_missing', 'Chýba ID produktu.', array('status' => 400));
		}

		$result = $this->plugin->rebuild->rebuild($product_id, array(
			'overwrite_price' => !empty($params['overwrite_price']),
			'force_images'    => !empty($params['allow_image_update']) && !empty($params['force_images']),
			'allow_title_update' => !empty($params['allow_title_update']),
			'allow_image_update' => !empty($params['allow_image_update']),
			'allow_price_update' => !empty($params['allow_price_update']),
			'allow_category_update' => !empty($params['allow_category_update']),
			'preserve_status' => array_key_exists('preserve_status', $params) ? !empty($params['preserve_status']) : true,
			'source_urls'     => $this->urls($params['source_urls'] ?? array()),
			'image_sources'   => $this->urls($params['image_sources'] ?? $params['image_urls'] ?? array()),
		));
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	public function qa_product(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$product_id = absint($params['product_id'] ?? 0);
		if (!$product_id) {
			return new WP_Error('komarena_pf_rest_product_missing', 'Chýba ID produktu.', array('status' => 400));
		}

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

		return rest_ensure_response(array(
			'ok' => true,
			'qa' => $qa,
		));
	}

	public function find_product_images(WP_REST_Request $request) {
		return $this->image_recovery_product_action($request, 'find_product_images');
	}

	public function fetch_product_images(WP_REST_Request $request) {
		return $this->image_recovery_product_action($request, 'fetch_product_images');
	}

	public function qa_product_images(WP_REST_Request $request) {
		return $this->image_recovery_product_action($request, 'qa_product_images');
	}

	public function standardize_product_images(WP_REST_Request $request) {
		return $this->image_recovery_product_action($request, 'standardize_product_images');
	}

	public function upload_product_images(WP_REST_Request $request) {
		return $this->image_recovery_product_action($request, 'upload_product_images');
	}

	public function repair_product_images(WP_REST_Request $request) {
		return $this->image_recovery_product_action($request, 'repair_product_images');
	}

	public function image_recovery_run(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$result = $this->plugin->image_recovery->image_recovery_run(absint($params['limit'] ?? 10));
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	private function image_recovery_product_action(WP_REST_Request $request, $method) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$product_id = absint($params['product_id'] ?? 0);
		if (!$product_id) {
			return new WP_Error('komarena_pf_rest_product_missing', 'Chýba ID produktu.', array('status' => 400));
		}
		if (!method_exists($this->plugin->image_recovery, $method)) {
			return new WP_Error('komarena_pf_image_recovery_method_missing', 'Neznáma obrázková akcia.', array('status' => 400));
		}

		$result = $this->plugin->image_recovery->{$method}($product_id, $params);
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	public function cleanup_bad_product_images(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}
		if ('CLEANUP_BAD_IMAGES' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error('komarena_pf_cleanup_confirm', 'Upratanie vyžaduje potvrdenie CLEANUP_BAD_IMAGES.', array('status' => 400));
		}

		$product_ids = array();
		foreach ((array) ($params['product_ids'] ?? array()) as $id) {
			$id = absint($id);
			if ($id) {
				$product_ids[] = $id;
			}
		}
		$result = $this->plugin->rebuild->cleanup_bad_product_images(array(
			'limit' => absint($params['limit'] ?? 100),
			'product_ids' => $product_ids,
		));
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok' => true,
			'result' => $result,
		));
	}

	public function restore_hotfix_titles(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}
		if ('RESTORE_HOTFIX_TITLES' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error('komarena_pf_restore_titles_confirm', 'Obnova názvov vyžaduje potvrdenie RESTORE_HOTFIX_TITLES.', array('status' => 400));
		}

		return rest_ensure_response(array(
			'ok' => true,
			'result' => $this->plugin->rebuild->restore_known_hotfix_titles(),
		));
	}

	public function approve_product_images(WP_REST_Request $request) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.', array('status' => 400));
		}

		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		if ('APPROVE_ORIGINAL_REAL_IMAGES' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error('komarena_pf_approve_images_confirm', 'Schvalenie obrazkov vyzaduje confirm=APPROVE_ORIGINAL_REAL_IMAGES.', array('status' => 400));
		}

		$product_id = absint($params['product_id'] ?? 0);
		$product = wc_get_product($product_id);
		if (!$product) {
			return new WP_Error('komarena_pf_approve_images_product_missing', 'Produkt neexistuje.', array('status' => 404));
		}

		$provided_image_ids = array();
		foreach ((array) ($params['image_ids'] ?? array()) as $id) {
			$id = absint($id);
			if ($id && 'attachment' === get_post_type($id)) {
				$provided_image_ids[] = $id;
			}
		}
		foreach ((array) ($params['image_urls'] ?? array()) as $url) {
			$id = attachment_url_to_postid(esc_url_raw($url));
			if ($id && 'attachment' === get_post_type($id)) {
				$provided_image_ids[] = absint($id);
			}
		}
		$provided_image_ids = array_values(array_slice(array_unique(array_filter($provided_image_ids)), 0, 4));
		if (count($provided_image_ids) >= 4) {
			set_post_thumbnail($product_id, $provided_image_ids[0]);
			$product->set_gallery_image_ids(array_slice($provided_image_ids, 1, 3));
			$product->save();
			foreach ($provided_image_ids as $attachment_id) {
				wp_update_post(array(
					'ID'          => $attachment_id,
					'post_parent' => $product_id,
				));
			}
		}

		$usage_permission = sanitize_key($params['usage_permission'] ?? 'own_photo');
		$allowed_permissions = array('allowed', 'allowed_internal', 'own_photo', 'licensed', 'manufacturer_allowed');
		if (!in_array($usage_permission, $allowed_permissions, true)) {
			return new WP_Error('komarena_pf_approve_images_permission', 'Nepovolene usage_permission pre finalne obrazky.', array('status' => 400));
		}

		$image_ids = array();
		if (has_post_thumbnail($product_id)) {
			$image_ids[] = get_post_thumbnail_id($product_id);
		}
		$image_ids = array_merge($image_ids, $product->get_gallery_image_ids());
		$image_ids = array_values(array_slice(array_filter(array_map('absint', $image_ids)), 0, 4));
		if (count($image_ids) < 4) {
			return new WP_Error('komarena_pf_approve_images_missing', 'Produkt nema 4 obrazky na schvalenie.', array('status' => 400));
		}

		$manifest = json_decode((string) get_post_meta($product_id, '_komarena_pf_manifest', true), true);
		if (!is_array($manifest)) {
			$manifest = array();
		}

		$roles = array('main', 'angle', 'detail', 'technical');
		$provenance = array();
		$source_type = sanitize_key($params['source_type'] ?? 'internal_komarena_media');
		$source_note = sanitize_text_field($params['note'] ?? 'Admin potvrdil, ze ide o originalne/realne produktove obrazky bez AI, watermarku a cudzieho predajneho loga.');
		foreach ($image_ids as $index => $id) {
			$role = $roles[$index] ?? ('image_' . ($index + 1));
			$url = wp_get_attachment_url($id);
			$source_url = $params['source_url'] ?? get_post_meta($id, '_komarena_image_source_url', true);
			if (!$source_url) {
				$source_url = $url;
			}
			$source_url = esc_url_raw($source_url);

			update_post_meta($id, '_komarena_image_source_url', $source_url);
			update_post_meta($id, '_komarena_image_usage_permission', $usage_permission);
			update_post_meta($id, '_komarena_image_watermark_status', 'clean');
			update_post_meta($id, '_komarena_image_seller_logo_status', 'clean');
			update_post_meta($id, '_komarena_image_ai_generated', '0');

			$provenance[$role] = array(
				'role'               => $role,
				'mode'               => 'approved_original_media',
				'source_url'         => $source_url,
				'source_host'        => $this->source_host($source_url),
				'source_type'        => $source_type,
				'attachment_id'      => (int) $id,
				'url'                => esc_url_raw($url),
				'usage_permission'   => $usage_permission,
				'watermark_status'   => 'clean',
				'seller_logo_status' => 'clean',
				'ai_generated'       => false,
				'generated'          => false,
				'is_original_or_real'=> true,
				'exact_image_match'  => true,
				'exact_image_match_reason' => 'admin approved original/real KomArena images',
				'matched_model_tokens' => array('admin_approved'),
				'is_final_eligible'  => true,
				'checked_at'         => current_time('mysql'),
				'approved_by'        => get_current_user_id(),
				'approval_confirm'   => 'APPROVE_ORIGINAL_REAL_IMAGES',
				'notes'              => $source_note,
			);
		}

		$manifest['image_mode'] = 'approved_original_media';
		$manifest['image_ids'] = $image_ids;
		$manifest['image_urls'] = array_values(array_map('wp_get_attachment_url', $image_ids));
		$manifest['image_provenance'] = $provenance;
		$manifest['image_approved_at'] = current_time('mysql');
		$manifest['image_approved_by'] = get_current_user_id();
		$manifest['warnings'] = $this->draft_package_warnings($manifest['warnings'] ?? array(), array('Obrazky boli adminom oznacene ako originalne/realne a finalne pouzitelne.'), $image_ids, $manifest['image_urls']);

		$qa = $this->plugin->qa->run($product_id, $manifest);
		$manifest['qa_status'] = $qa['status'];
		$manifest['ready_to_publish'] = $qa['ready_to_publish'];
		$this->save_manifest_meta($product_id, $manifest);
		$image_state = array(
			'image_status'           => 'ready',
			'image_block_reason'     => '',
			'image_source_count'     => count($image_ids),
			'image_exact_match_score'=> 100,
			'image_rights_confidence'=> 'high',
			'image_watermark_check'  => 'clean',
			'image_logo_check'       => 'clean',
			'image_ai_check'         => 'not_suspected',
			'image_last_reviewed_at' => current_time('mysql'),
			'image_last_reviewed_by' => 'admin_agent',
			'publish_gate_status'    => !empty($qa['ready_to_publish']) ? 'pass' : 'image_ready',
			'publish_gate_reason'    => !empty($qa['ready_to_publish']) ? 'Produkt presiel kontrolou kvality.' : 'Obrazky su schvalene, produkt este potrebuje prejst kompletnou QA.',
		);
		foreach ($image_state as $key => $value) {
			update_post_meta($product_id, $key, wp_kses_post((string) $value));
			update_post_meta($product_id, '_komarena_pf_' . $key, wp_kses_post((string) $value));
		}
		update_post_meta($product_id, '_komarena_pf_status', $qa['ready_to_publish'] ? 'ready_to_publish' : 'needs_review');
		update_post_meta($product_id, '_komarena_pf_ready_to_publish', $qa['ready_to_publish'] ? '1' : '0');

		return rest_ensure_response(array(
			'ok'               => true,
			'product_id'       => $product_id,
			'post_status'      => get_post_status($product_id),
			'assigned_image_ids' => $image_ids,
			'image_mode'       => $manifest['image_mode'],
			'ready_to_publish' => !empty($qa['ready_to_publish']),
			'qa'               => $qa,
			'image_provenance' => $provenance,
		));
	}

	public function create_product_draft(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$name = sanitize_text_field($params['name'] ?? $params['product_name'] ?? '');
		if ('' === $name) {
			return new WP_Error('komarena_pf_draft_name_missing', 'Chýba názov produktu.', array('status' => 400));
		}

		$result = $this->plugin->creator->create_from_name($name, array(
			'source_urls'   => $this->urls($params['source_urls'] ?? array()),
			'image_sources' => $this->urls($params['image_sources'] ?? $params['image_urls'] ?? array()),
			'sku'           => sanitize_text_field($params['sku'] ?? ''),
			'ean'           => sanitize_text_field($params['ean'] ?? ''),
			'force_draft'   => true,
		));

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'               => true,
			'product_id'       => absint($result['product_id'] ?? 0),
			'ready_to_publish' => !empty($result['ready_to_publish']),
			'post_status'      => 'draft',
			'message'          => 'WooCommerce produktový návrh bol vytvorený ako koncept.',
			'result'           => $result,
		));
	}

	public function create_product_from_draft(WP_REST_Request $request) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.', array('status' => 400));
		}

		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		if ('CREATE_DRAFT_FROM_PACKAGE' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error('komarena_pf_draft_package_confirm', 'Vytvorenie konceptu z balika vyzaduje confirm=CREATE_DRAFT_FROM_PACKAGE.', array('status' => 400));
		}

		$fields = $params['fields'] ?? array();
		if (!is_array($fields)) {
			$fields = array();
		}

		$name = sanitize_text_field($fields['name'] ?? $params['product_name'] ?? $params['productName'] ?? '');
		if ('' === $name) {
			return new WP_Error('komarena_pf_draft_package_name_missing', 'Chyba nazov produktu.', array('status' => 400));
		}

		$sku = sanitize_text_field($fields['sku'] ?? $params['sku'] ?? '');
		if ('' === $sku) {
			return new WP_Error('komarena_pf_draft_package_sku_missing', 'Chyba SKU.', array('status' => 400));
		}
		if (function_exists('wc_get_product_id_by_sku') && wc_get_product_id_by_sku($sku)) {
			return new WP_Error('komarena_pf_draft_package_sku_exists', 'Produkt s tymto SKU uz existuje.', array('status' => 409));
		}

		$ean = preg_replace('/\D+/', '', (string) ($fields['ean'] ?? $params['ean'] ?? ''));
		if (!$ean || 0 !== strpos($ean, '2998')) {
			return new WP_Error('komarena_pf_draft_package_ean_invalid', 'EAN musi existovat a zacinat na 2998.', array('status' => 400));
		}

		$price = $this->normalize_price($fields['regularPrice'] ?? $fields['regular_price'] ?? $params['regular_price'] ?? '');
		if (!$price || !preg_match('/\.99$/', $price)) {
			return new WP_Error('komarena_pf_draft_package_price_invalid', 'Cena musi byt platna a koncit na .99.', array('status' => 400));
		}

		$image_ids = array_values(array_filter(array_map('absint', (array) ($params['image_ids'] ?? $fields['image_ids'] ?? array()))));
		$image_urls = $this->urls($params['image_urls'] ?? $fields['image_urls'] ?? $fields['images'] ?? array());
		if (count($image_ids) < 4 && count($image_urls) < 4) {
			return new WP_Error('komarena_pf_draft_package_images_missing', 'Balik musi obsahovat aspon 4 obrazky alebo 4 image URL.', array('status' => 400));
		}

		$product = new WC_Product_Simple();
		$product->set_name($name);
		$product->set_slug(sanitize_title($fields['slug'] ?? $params['slug'] ?? $name));
		$product->set_status('draft');
		$product->set_catalog_visibility('visible');
		$product->set_regular_price($price);
		$product->set_price($price);
		$product->set_manage_stock(true);
		$product->set_stock_quantity(max(0, absint($fields['stock'] ?? $params['stock'] ?? $this->plugin->settings->get('default_stock', 10))));
		$product->set_stock_status('instock');
		$product->set_tax_status(sanitize_key($fields['taxStatus'] ?? $fields['tax_status'] ?? 'taxable'));
		$product->set_sku($sku);
		$product->set_short_description(wp_kses_post($fields['shortDescription'] ?? $fields['short_description'] ?? ''));
		$product_id = $product->save();

		$slug = $product->get_slug();
		if (count($image_ids) < 4) {
			$image_ids = $this->sideload_draft_images($image_urls, $product_id, $slug, $name, (array) ($fields['imageAltText'] ?? $fields['image_alt_text'] ?? array()));
			if (is_wp_error($image_ids)) {
				wp_delete_post($product_id, true);
				return $image_ids;
			}
		}

		$image_ids = array_values(array_slice(array_filter(array_map('absint', $image_ids)), 0, 4));
		$final_urls = array();
		foreach ($image_ids as $id) {
			$url = wp_get_attachment_url($id);
			if ($url) {
				$final_urls[] = $url;
			}
		}

		if (count($image_ids) < 4 || count($final_urls) < 4) {
			wp_delete_post($product_id, true);
			return new WP_Error('komarena_pf_draft_package_media_failed', 'Nepodarilo sa pripravit 4 obrazky v Media Library.', array('status' => 500));
		}

		$product = wc_get_product($product_id);
		$product->set_image_id((int) $image_ids[0]);
		$product->set_gallery_image_ids(array_map('absint', array_slice($image_ids, 1)));
		$draft_manifest_for_description = $params['manifest'] ?? array();
		$description = $this->canonical_description_from_draft($fields, is_array($draft_manifest_for_description) ? $draft_manifest_for_description : array(), $final_urls, $price);
		$product->set_description($description);
		$product->save();

		$category_reasoning = $this->plugin->creator->assign_categories($product_id, (array) ($fields['categories'] ?? array()));
		$this->plugin->creator->set_ean_meta($product_id, $ean);
		$this->apply_draft_attributes($product_id, $fields, $description);

		update_post_meta($product_id, '_yoast_wpseo_title', sanitize_text_field($fields['yoastTitle'] ?? $fields['yoast_title'] ?? sprintf('%s | KomArena.sk', $name)));
		update_post_meta($product_id, '_yoast_wpseo_metadesc', sanitize_text_field($fields['yoastMetaDescription'] ?? $fields['yoast_meta_description'] ?? ''));
		update_post_meta($product_id, '_yoast_wpseo_focuskw', sanitize_text_field($name));

		$manifest = $params['manifest'] ?? array();
		if (!is_array($manifest)) {
			$manifest = array();
		}
		$source_urls = $this->urls($params['source_urls'] ?? $manifest['sourceUrls'] ?? $manifest['source_urls'] ?? array());
		$image_mode = sanitize_text_field($params['image_mode'] ?? $manifest['imageMode'] ?? $manifest['image_mode'] ?? 'package_uploaded');
		$confidence = $this->normalize_confidence_score($manifest['confidence_score'] ?? $manifest['confidenceScore'] ?? 0);
		$source_evidence = $manifest['source_evidence'] ?? array();
		if (empty($source_evidence) && !empty($source_urls) && $confidence >= (float) $this->plugin->settings->get('minimum_confidence', 0.65)) {
			$source_evidence = array(
				'status' => 'verified',
				'mode'   => 'package_manifest',
			);
		}
		$manifest = array_merge($manifest, array(
			'source_urls'        => $source_urls,
			'source_evidence'    => $source_evidence,
			'confidence_score'   => $confidence,
			'image_mode'         => $image_mode,
			'image_ids'          => $image_ids,
			'image_urls'         => $final_urls,
			'image_provenance'   => $this->build_image_provenance($manifest, $image_ids, $final_urls, $image_mode),
			'qa_status'          => 'pending',
			'ready_to_publish'   => false,
			'generated_at'       => current_time('mysql'),
			'agent_version'      => KOMARENA_PF_VERSION,
			'price_reasoning'    => sanitize_text_field($fields['priceReasoning'] ?? $manifest['priceReasoning'] ?? ''),
			'category_reasoning' => $category_reasoning,
			'warnings'           => $this->draft_package_warnings($manifest['warnings'] ?? array(), array('Produkt bol vytvoreny ako draft z lokalneho KomArena balika.'), $image_ids, $final_urls),
		));
		$this->save_manifest_meta($product_id, $manifest);

		$qa = $this->plugin->qa->run($product_id, $manifest);
		$manifest['qa_status'] = $qa['status'];
		$manifest['ready_to_publish'] = $qa['ready_to_publish'];
		$this->save_manifest_meta($product_id, $manifest);
		update_post_meta($product_id, '_komarena_pf_status', $qa['ready_to_publish'] ? 'ready_to_publish' : 'needs_review');
		update_post_meta($product_id, '_komarena_pf_ready_to_publish', $qa['ready_to_publish'] ? '1' : '0');

		return rest_ensure_response(array(
			'ok'               => true,
			'product_id'       => absint($product_id),
			'post_status'      => 'draft',
			'ready_to_publish' => !empty($qa['ready_to_publish']),
			'qa'               => $qa,
			'image_ids'        => $image_ids,
			'image_urls'       => $final_urls,
			'edit_url'         => admin_url('post.php?post=' . absint($product_id) . '&action=edit'),
			'message'          => 'WooCommerce koncept bol vytvoreny z kompletneho KomArena balika.',
		));
	}

	public function enrich_product_from_draft(WP_REST_Request $request) {
		if (!$this->plugin->has_woocommerce()) {
			return new WP_Error('komarena_pf_missing_woocommerce', 'WooCommerce nie je aktivny.', array('status' => 400));
		}

		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		if ('ENRICH_DRAFT_FROM_PACKAGE' !== (string) ($params['confirm'] ?? '')) {
			return new WP_Error('komarena_pf_enrich_package_confirm', 'Obohatenie konceptu z balika vyzaduje confirm=ENRICH_DRAFT_FROM_PACKAGE.', array('status' => 400));
		}

		$product_id = absint($params['product_id'] ?? 0);
		$product = wc_get_product($product_id);
		if (!$product) {
			return new WP_Error('komarena_pf_enrich_product_missing', 'Produkt neexistuje.', array('status' => 404));
		}

		$fields = $params['fields'] ?? array();
		if (!is_array($fields)) {
			$fields = array();
		}

		$name = $product->get_name();
		$image_ids = array_values(array_filter(array_map('absint', (array) ($params['image_ids'] ?? $fields['image_ids'] ?? array()))));
		if (count($image_ids) < 4) {
			$image_ids = array();
			if (has_post_thumbnail($product_id)) {
				$image_ids[] = get_post_thumbnail_id($product_id);
			}
			$image_ids = array_merge($image_ids, $product->get_gallery_image_ids());
		}
		$image_ids = array_values(array_slice(array_filter(array_map('absint', $image_ids)), 0, 4));

		$final_urls = array();
		foreach ($image_ids as $id) {
			$url = wp_get_attachment_url($id);
			if ($url) {
				$final_urls[] = $url;
			}
		}

		if (count($image_ids) < 4 || count($final_urls) < 4) {
			return new WP_Error('komarena_pf_enrich_images_missing', 'Produkt nema 4 obrazky v Media Library.', array('status' => 400));
		}

		if (!empty($fields['name'])) {
			$name = sanitize_text_field($fields['name']);
			$product->set_name($name);
		}
		if (!empty($fields['shortDescription']) || !empty($fields['short_description'])) {
			$product->set_short_description(wp_kses_post($fields['shortDescription'] ?? $fields['short_description']));
		}
		if (!empty($fields['description'])) {
			$description = $this->canonical_description_from_draft($fields, $params['manifest'] ?? array(), $final_urls, (string) $product->get_regular_price());
			$product->set_description($description);
		} else {
			$description = $this->ensure_draft_section_markers((string) $product->get_description());
			$product->set_description($description);
		}
		$product->save();

		if (!empty($fields['categories'])) {
			$category_reasoning = $this->plugin->creator->assign_categories($product_id, (array) $fields['categories']);
		} else {
			$category_reasoning = array();
		}
		if (!empty($fields['ean'])) {
			$this->plugin->creator->set_ean_meta($product_id, preg_replace('/\D+/', '', (string) $fields['ean']));
		}
		if (!empty($fields['yoastTitle']) || !empty($fields['yoast_title'])) {
			update_post_meta($product_id, '_yoast_wpseo_title', sanitize_text_field($fields['yoastTitle'] ?? $fields['yoast_title']));
		}
		if (!empty($fields['yoastMetaDescription']) || !empty($fields['yoast_meta_description'])) {
			update_post_meta($product_id, '_yoast_wpseo_metadesc', sanitize_text_field($fields['yoastMetaDescription'] ?? $fields['yoast_meta_description']));
		}
		update_post_meta($product_id, '_yoast_wpseo_focuskw', sanitize_text_field($name));

		$this->apply_draft_attributes($product_id, $fields, $description);

		$manifest = $params['manifest'] ?? array();
		if (!is_array($manifest)) {
			$manifest = array();
		}
		$source_urls = $this->urls($params['source_urls'] ?? $manifest['sourceUrls'] ?? $manifest['source_urls'] ?? array());
		$image_mode = sanitize_text_field($params['image_mode'] ?? $manifest['imageMode'] ?? $manifest['image_mode'] ?? 'package_enriched');
		$confidence = $this->normalize_confidence_score($manifest['confidence_score'] ?? $manifest['confidenceScore'] ?? 0);
		$source_evidence = $manifest['source_evidence'] ?? array();
		if (empty($source_evidence) && !empty($source_urls) && $confidence >= (float) $this->plugin->settings->get('minimum_confidence', 0.65)) {
			$source_evidence = array(
				'status' => 'verified',
				'mode'   => 'package_manifest',
			);
		}

		$manifest = array_merge($manifest, array(
			'source_urls'        => $source_urls,
			'source_evidence'    => $source_evidence,
			'confidence_score'   => $confidence,
			'image_mode'         => $image_mode,
			'image_ids'          => $image_ids,
			'image_urls'         => $final_urls,
			'image_provenance'   => $this->build_image_provenance($manifest, $image_ids, $final_urls, $image_mode),
			'qa_status'          => 'pending',
			'ready_to_publish'   => false,
			'generated_at'       => current_time('mysql'),
			'agent_version'      => KOMARENA_PF_VERSION,
			'price_reasoning'    => sanitize_text_field($fields['priceReasoning'] ?? $manifest['priceReasoning'] ?? ''),
			'category_reasoning' => $category_reasoning,
			'warnings'           => $this->draft_package_warnings($manifest['warnings'] ?? array(), array('Produkt bol obohateny z lokalneho KomArena balika.'), $image_ids, $final_urls),
		));

		$qa = $this->plugin->qa->run($product_id, $manifest);
		$manifest['qa_status'] = $qa['status'];
		$manifest['ready_to_publish'] = $qa['ready_to_publish'];
		$this->save_manifest_meta($product_id, $manifest);
		update_post_meta($product_id, '_komarena_pf_status', $qa['ready_to_publish'] ? 'ready_to_publish' : 'needs_review');
		update_post_meta($product_id, '_komarena_pf_ready_to_publish', $qa['ready_to_publish'] ? '1' : '0');
		$saved_manifest_raw = (string) get_post_meta($product_id, '_komarena_pf_manifest', true);
		$saved_manifest = json_decode($saved_manifest_raw, true);
		if (!is_array($saved_manifest)) {
			$saved_manifest = array();
		}

		return rest_ensure_response(array(
			'ok'               => true,
			'product_id'       => absint($product_id),
			'post_status'      => get_post_status($product_id),
			'ready_to_publish' => !empty($qa['ready_to_publish']),
			'qa'               => $qa,
			'image_ids'        => $image_ids,
			'image_urls'       => $final_urls,
			'manifest_saved_raw_length' => strlen($saved_manifest_raw),
			'manifest_saved_keys'       => array_keys($saved_manifest),
			'manifest_saved_image_mode' => $saved_manifest['image_mode'] ?? '',
			'manifest_saved_source_status' => $saved_manifest['source_evidence']['status'] ?? '',
			'manifest_saved_provenance_count' => is_array($saved_manifest['image_provenance'] ?? null) ? count($saved_manifest['image_provenance']) : 0,
			'edit_url'         => admin_url('post.php?post=' . absint($product_id) . '&action=edit'),
			'message'          => 'WooCommerce koncept bol obohateny z kompletneho KomArena balika.',
		));
	}

	public function run_plugin_audit($request = null) {
		$audit = $this->plugin->housekeeper->audit_installed_plugins();
		if (is_wp_error($audit)) {
			return $audit;
		}

		$cleanup = $this->plugin->housekeeper->cleanup_plan();
		if (is_wp_error($cleanup)) {
			$cleanup = array('status' => 'failed', 'message' => $cleanup->get_error_message());
		}

		return rest_ensure_response(array(
			'ok'           => true,
			'audit'        => $audit,
			'cleanup_plan' => $cleanup,
		));
	}

	public function deactivate_plugins(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$confirm = (string) ($params['confirm'] ?? '');
		if ('DEACTIVATE' !== $confirm) {
			return new WP_Error('komarena_pf_rest_deactivate_confirm', 'Deaktivacia vyzaduje confirm=DEACTIVATE.', array('status' => 400));
		}

		$result = $this->plugin->housekeeper->deactivate_selected(array(
			'admin_confirmed' => true,
			'plugins'         => array_filter(array_map('sanitize_text_field', (array) ($params['plugins'] ?? array()))),
		));
		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	public function run_site_layout(WP_REST_Request $request) {
		$params = $request->get_json_params();
		if (!is_array($params)) {
			$params = array();
		}

		$task = sanitize_key($params['task'] ?? 'audit');
		$payload = $this->clean_payload($params['payload'] ?? array());

		if ('apply' === $task || 'layout' === $task) {
			$result = $this->plugin->layout->apply_homepage_sidebar_layout($payload);
		} elseif ('restore' === $task) {
			$result = $this->plugin->layout->restore_homepage_sidebar_backup($payload);
		} else {
			$result = $this->plugin->layout->audit_homepage_sidebar_layout($payload);
		}

		if (is_wp_error($result)) {
			return $result;
		}

		return rest_ensure_response(array(
			'ok'     => true,
			'result' => $result,
		));
	}

	public function logs(WP_REST_Request $request) {
		$limit = absint($request->get_param('limit') ?: 20);

		return rest_ensure_response(array(
			'ok'   => true,
			'logs' => $this->plugin->logger->recent($limit),
		));
	}

	private function enqueue_text($text) {
		$queue_ids = array();
		foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
			$line = trim(wp_unslash($line));
			if ('' === $line) {
				continue;
			}

			$parts = array_map('trim', explode('|', $line));
			$name = sanitize_text_field(array_shift($parts));
			if ('' === $name) {
				continue;
			}

			$source_urls = array();
			$image_sources = array();
			foreach ($parts as $url) {
				$url = esc_url_raw($url);
				if (!$url) {
					continue;
				}
				if (preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $url)) {
					$image_sources[] = $url;
				} else {
					$source_urls[] = $url;
				}
			}

			$queue_ids[] = $this->plugin->queue->insert($name, array(
				'source_urls'   => $source_urls,
				'image_sources' => $image_sources,
			));
		}

		return $queue_ids;
	}

	private function urls($value) {
		$urls = array();
		foreach ((array) $value as $url) {
			$url = esc_url_raw($url);
			if ($url) {
				$urls[] = $url;
			}
		}

		return array_values(array_unique($urls));
	}

	private function clean_payload($value) {
		if (is_array($value)) {
			$out = array();
			foreach ($value as $key => $item) {
				$out[sanitize_key((string) $key)] = $this->clean_payload($item);
			}
			return $out;
		}
		if (is_bool($value) || is_numeric($value)) {
			return $value;
		}

		return sanitize_text_field((string) $value);
	}

	private function normalize_price($value) {
		$value = str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', (string) $value));
		if (!is_numeric($value)) {
			return '';
		}

		return number_format((float) $value, 2, '.', '');
	}

	private function canonical_description_from_draft($fields, $manifest, $final_urls, $price) {
		if (!is_array($fields)) {
			$fields = array();
		}
		if (!is_array($manifest)) {
			$manifest = array();
		}

		$research = $this->draft_fields_to_research($fields, $manifest);
		$image_urls = array(
			'main'      => $final_urls[0] ?? '',
			'angle'     => $final_urls[1] ?? '',
			'detail'    => $final_urls[2] ?? '',
			'technical' => $final_urls[3] ?? '',
		);
		$price_data = array(
			'price'     => $price,
			'reasoning' => $fields['priceReasoning'] ?? $manifest['priceReasoning'] ?? '',
		);

		if ($this->plugin && isset($this->plugin->content) && method_exists($this->plugin->content, 'canonical_description')) {
			return $this->plugin->content->canonical_description($research, $image_urls, $price_data, (array) ($fields['categories'] ?? array()));
		}

		$description = $this->ensure_draft_section_markers(wp_kses_post($fields['description'] ?? ''));
		return $this->description_with_images($description, $final_urls, $research['normalized_name'], (array) ($fields['imageAltText'] ?? $fields['image_alt_text'] ?? array()));
	}

	private function draft_fields_to_research($fields, $manifest) {
		$description = wp_kses_post($fields['description'] ?? '');
		$pairs = $this->extract_attribute_pairs_from_description($description);
		$specs = array();
		foreach ($pairs as $pair) {
			$name = sanitize_text_field($pair['name'] ?? '');
			$value = sanitize_text_field($pair['value'] ?? '');
			if ('' !== $name && '' !== $value) {
				$specs[$name] = $value;
			}
		}

		$name = sanitize_text_field($fields['name'] ?? $fields['Name'] ?? $fields['title'] ?? 'Produkt');
		$categories = array_values(array_filter(array_map('sanitize_text_field', (array) ($fields['categories'] ?? array()))));
		$compatibility = $this->draft_list_value($fields, 'compatibility', array());
		if (empty($compatibility)) {
			$compatibility = array_values(array_unique(array_merge($categories, array('Arduino', 'ESPHome', 'Home Assistant'))));
		}
		$package = $this->draft_list_value($fields, 'packageContents', array());
		if (empty($package)) {
			$package = $this->draft_list_value($fields, 'package_contents', array('1x ' . $name));
		}

		return array(
			'input_name'       => $name,
			'normalized_name'  => $name,
			'model'            => $this->draft_first_spec($specs, array('Model', 'Typ produktu'), $fields['model'] ?? ''),
			'chip'             => $this->draft_first_spec($specs, array('Mikrokontrolér', 'Mikrokontroler', 'Čip / verzia', 'Cip / verzia', 'Čip', 'Cip'), $fields['chip'] ?? ''),
			'ports'            => $this->draft_first_spec($specs, array('Porty', 'Rozhranie', 'Sériové porty', 'Seriove porty'), $fields['ports'] ?? ''),
			'connectors'       => $this->draft_first_spec($specs, array('Konektory', 'Napájanie', 'Napajanie'), $fields['connectors'] ?? ''),
			'pin_layout'       => $this->draft_first_spec($specs, array('Pin layout', 'Piny', 'Digitálne I/O piny', 'Digitalne I/O piny'), $fields['pin_layout'] ?? ''),
			'appearance'       => $this->draft_first_spec($specs, array('Fyzický vzhľad', 'Fyzicky vzhlad', 'Rozmery'), $fields['appearance'] ?? ''),
			'typical_use'      => sanitize_text_field(wp_trim_words(wp_strip_all_tags($fields['shortDescription'] ?? $fields['short_description'] ?? $manifest['typicalUse'] ?? ''), 28, '')),
			'compatibility'    => $compatibility,
			'package_contents' => $package,
			'categories'       => $categories,
			'specs'            => $specs,
			'source_urls'      => $this->urls($manifest['sourceUrls'] ?? $manifest['source_urls'] ?? array()),
			'warnings'         => (array) ($manifest['warnings'] ?? array()),
			'confidence_score' => $this->normalize_confidence_score($manifest['confidence_score'] ?? $manifest['confidenceScore'] ?? 0),
		);
	}

	private function draft_list_value($fields, $key, $default = array()) {
		if (empty($fields[$key])) {
			return $default;
		}
		if (is_array($fields[$key])) {
			return array_values(array_filter(array_map('sanitize_text_field', $fields[$key])));
		}
		$parts = preg_split('/[,;\n]+/', (string) $fields[$key]);
		return array_values(array_filter(array_map('sanitize_text_field', array_map('trim', $parts))));
	}

	private function draft_first_spec($specs, $names, $fallback = '') {
		$normalized = array();
		foreach ($specs as $key => $value) {
			$normalized[strtolower(remove_accents((string) $key))] = $value;
		}
		foreach ($names as $name) {
			$key = strtolower(remove_accents((string) $name));
			if (!empty($normalized[$key])) {
				return $normalized[$key];
			}
		}

		return '' !== (string) $fallback ? sanitize_text_field((string) $fallback) : 'Podľa konkrétnej verzie / dodávky sa môže líšiť.';
	}

	private function draft_package_warnings($warnings, $extra, $image_ids = array(), $image_urls = array()) {
		$has_four_images = count(array_filter(array_map('absint', (array) $image_ids))) >= 4 && count(array_filter((array) $image_urls)) >= 4;
		$out = array();

		foreach (array_merge((array) $warnings, (array) $extra) as $warning) {
			$warning = trim(sanitize_text_field((string) $warning));
			if ('' === $warning) {
				continue;
			}

			$normalized = strtolower(remove_accents($warning));
			if ($has_four_images && false !== strpos($normalized, 'chybaju finalne obrazky')) {
				continue;
			}

			$out[] = $warning;
		}

		return array_values(array_unique($out));
	}

	private function sanitize_value($value) {
		if (is_array($value)) {
			$out = array();
			foreach ($value as $key => $item) {
				$out[is_string($key) ? sanitize_key($key) : $key] = $this->sanitize_value($item);
			}
			return $out;
		}
		if (is_object($value)) {
			return $this->sanitize_value((array) $value);
		}
		if (is_bool($value) || is_numeric($value) || null === $value) {
			return $value;
		}

		return sanitize_text_field((string) $value);
	}

	private function save_manifest_meta($product_id, $manifest) {
		$options = 0;
		if (defined('JSON_UNESCAPED_UNICODE')) {
			$options |= JSON_UNESCAPED_UNICODE;
		}
		if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
			$options |= JSON_INVALID_UTF8_SUBSTITUTE;
		}

		$encoded = wp_json_encode($this->sanitize_value($manifest), $options);
		if (false === $encoded || null === $encoded) {
			$encoded = wp_json_encode($this->sanitize_value($manifest));
		}

		update_post_meta($product_id, '_komarena_pf_manifest', wp_slash((string) $encoded));
	}

	private function sideload_draft_images($urls, $product_id, $slug, $name, $alt_texts = array()) {
		if (!function_exists('download_url')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (!function_exists('media_handle_sideload')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$ids = array();
		$variants = array('main', 'angle', 'detail', 'technical');
		foreach (array_slice($urls, 0, 4) as $index => $url) {
			$url = esc_url_raw($url);
			$variant = $variants[$index] ?? ('image-' . ($index + 1));
			$tmp = download_url($url, 30);
			if (is_wp_error($tmp)) {
				return $tmp;
			}

			$filename = sprintf('komarena-%s-%s.jpg', sanitize_title($slug), sanitize_title($variant));
			$file = array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			);
			$id = media_handle_sideload($file, $product_id, $name . ' - ' . $variant);
			if (is_wp_error($id)) {
				@unlink($tmp);
				return $id;
			}

			$alt = sanitize_text_field($alt_texts[$index] ?? sprintf('%s - %s', $name, $variant));
			update_post_meta($id, '_wp_attachment_image_alt', $alt);
			$ids[] = (int) $id;
		}

		return $ids;
	}

	private function description_with_images($description, $image_urls, $name, $alt_texts = array()) {
		if (false !== stripos($description, '<img')) {
			return $description;
		}

		$html = $description;
		$html .= '<div style="display:flex;flex-wrap:wrap;gap:12px;margin:18px 0;">';
		foreach (array_slice($image_urls, 0, 4) as $index => $url) {
		$alt = esc_attr($alt_texts[$index] ?? sprintf('%s - produktový obrázok %d', $name, $index + 1));
			$html .= '<div style="flex:1 1 240px;border:1px solid rgba(0,151,157,.20);border-radius:16px;background:#ffffff;padding:10px;">';
			$html .= '<img src="' . esc_url($url) . '" alt="' . $alt . '" loading="lazy" style="display:block;width:100%;height:auto;object-fit:contain;border-radius:12px;">';
			$html .= '</div>';
		}
		$html .= '</div>';

		return wp_kses_post($html);
	}

	private function ensure_draft_section_markers($description) {
		$markers = array(
			'Úvodný technický blok',
			'3 KPI karty',
		);

		$prefix = '';
		foreach ($markers as $marker) {
			$exists = function_exists('mb_stripos') ? false !== mb_stripos($description, $marker) : false !== stripos($description, $marker);
			if (!$exists) {
				$prefix .= '<p style="margin:0 0 8px;color:#102027;"><strong>' . esc_html($marker) . '</strong></p>';
			}
		}

		return $prefix ? wp_kses_post($prefix . $description) : $description;
	}

	private function apply_draft_attributes($product_id, $fields, $description) {
		$product = wc_get_product($product_id);
		if (!$product) {
			return;
		}

		$raw = $fields['attributes'] ?? $fields['technicalAttributes'] ?? array();
		$pairs = $this->normalize_draft_attribute_pairs($raw);
		if (empty($pairs)) {
			$pairs = $this->extract_attribute_pairs_from_description($description);
		}
		if (empty($pairs)) {
			return;
		}

		$attributes = array();
		foreach (array_slice($pairs, 0, 16) as $pair) {
			$name = sanitize_text_field($pair['name'] ?? '');
			$value = sanitize_text_field($pair['value'] ?? '');
			if ('' === $name || '' === $value) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id(0);
			$attribute->set_name($name);
			$attribute->set_options(array($value));
			$attribute->set_visible(true);
			$attribute->set_variation(false);
			$attributes[sanitize_title($name)] = $attribute;
		}

		if (!empty($attributes)) {
			$product->set_attributes($attributes);
			$product->save();
		}
	}

	private function normalize_draft_attribute_pairs($raw) {
		$pairs = array();
		if (!is_array($raw)) {
			return $pairs;
		}

		foreach ($raw as $key => $item) {
			if (is_array($item)) {
				$name = $item['name'] ?? $item['label'] ?? (is_string($key) ? $key : '');
				$value = $item['value'] ?? $item['option'] ?? $item['options'] ?? '';
				if (is_array($value)) {
					$value = implode(', ', array_map('sanitize_text_field', $value));
				}
			} else {
				$name = is_string($key) ? $key : '';
				$value = $item;
			}

			$name = trim(wp_strip_all_tags((string) $name));
			$value = trim(wp_strip_all_tags((string) $value));
			if ('' !== $name && '' !== $value) {
				$pairs[] = array('name' => $name, 'value' => $value);
			}
		}

		return $pairs;
	}

	private function extract_attribute_pairs_from_description($description) {
		$pairs = array();
		if (!preg_match_all('/<tr\b[^>]*>\s*<td\b[^>]*>(.*?)<\/td>\s*<td\b[^>]*>(.*?)<\/td>\s*<\/tr>/is', $description, $matches, PREG_SET_ORDER)) {
			return $pairs;
		}

		foreach ($matches as $match) {
			$name = trim(html_entity_decode(wp_strip_all_tags($match[1]), ENT_QUOTES, 'UTF-8'));
			$value = trim(html_entity_decode(wp_strip_all_tags($match[2]), ENT_QUOTES, 'UTF-8'));
			if ('' !== $name && '' !== $value) {
				$pairs[] = array('name' => $name, 'value' => $value);
			}
		}

		return $pairs;
	}

	private function normalize_confidence_score($value) {
		$value = (float) $value;
		if ($value > 1) {
			$value = $value / 100;
		}

		return max(0, min(1, $value));
	}

	private function build_image_provenance($manifest, $image_ids, $image_urls, $image_mode) {
		$existing = $manifest['image_provenance'] ?? $manifest['imageProvenance'] ?? array();
		$out = array();
		$variants = array('main', 'angle', 'detail', 'technical');
		foreach (array_slice($image_ids, 0, 4) as $index => $id) {
			$variant = $variants[$index] ?? ('image_' . ($index + 1));
			$item = $this->existing_provenance_item($existing, $variant, $index);
			$out[$variant] = $this->normalize_image_provenance_item($item, $variant, $image_mode, absint($id), esc_url_raw($image_urls[$index] ?? ''));
		}

		return $out;
	}

	private function existing_provenance_item($existing, $variant, $index) {
		if (!is_array($existing)) {
			return array();
		}
		if (!empty($existing[$variant]) && is_array($existing[$variant])) {
			return $existing[$variant];
		}
		if (!empty($existing[$index]) && is_array($existing[$index])) {
			return $existing[$index];
		}

		return array();
	}

	private function normalize_image_provenance_item($item, $variant, $default_mode, $attachment_id, $url) {
		if (!is_array($item)) {
			$item = array();
		}

		$mode = sanitize_key($item['mode'] ?? $default_mode);
		$source_url = esc_url_raw($item['source_url'] ?? $item['sourceUrl'] ?? $item['source'] ?? '');
		$source_type = sanitize_key($item['source_type'] ?? $item['sourceType'] ?? $this->detect_image_source_type($source_url, $url, $mode));
		$usage_permission = sanitize_key($item['usage_permission'] ?? $item['usagePermission'] ?? $item['usePolicy'] ?? $this->default_image_usage_permission($source_type, $mode));
		$watermark_status = sanitize_key($item['watermark_status'] ?? $item['watermarkStatus'] ?? $this->default_image_visual_status($source_url, $mode));
		$seller_logo_status = sanitize_key($item['seller_logo_status'] ?? $item['sellerLogoStatus'] ?? $this->default_image_visual_status($source_url, $mode));
		$ai_generated = $this->truthy($item['ai_generated'] ?? $item['aiGenerated'] ?? false);
		$generated = $this->truthy($item['generated'] ?? false) || in_array($mode, array('illustrated_fallback', 'illustrated_fallback_colored_pencil', 'placeholder_generated_for_review'), true);
		$is_real = !$generated && !$ai_generated && in_array($mode, array('sideloaded_verified_source', 'real_standardized', 'real_partial', 'media_library_url_derived', 'package_uploaded', 'package_enriched', 'existing_preserved'), true);
		$internal_approved = in_array($source_type, array('internal_komarena', 'internal_komarena_media'), true)
			&& 'own_photo' === $usage_permission
			&& 'clean' === $watermark_status
			&& 'clean' === $seller_logo_status
			&& !$ai_generated;
		$exact_match = $this->truthy($item['exact_image_match'] ?? $item['exactImageMatch'] ?? $internal_approved);

		return array(
			'role'               => sanitize_key($item['role'] ?? $variant),
			'mode'               => $mode,
			'source_url'         => $source_url,
			'source_host'        => $this->source_host($source_url),
			'source_type'        => $source_type,
			'attachment_id'      => $attachment_id,
			'url'                => esc_url_raw($url),
			'usage_permission'   => $usage_permission,
			'watermark_status'   => $watermark_status,
			'seller_logo_status' => $seller_logo_status,
			'ai_generated'       => $ai_generated,
			'generated'          => $generated,
			'is_original_or_real'=> $is_real,
			'exact_image_match'  => $exact_match,
			'exact_image_match_reason' => sanitize_text_field($item['exact_image_match_reason'] ?? $item['exactImageMatchReason'] ?? ($internal_approved ? 'interné schválenie vlastnej fotografie KomArena' : 'nepotvrdené')),
			'matched_model_tokens' => array_values(array_map('sanitize_text_field', (array) ($item['matched_model_tokens'] ?? $item['matchedModelTokens'] ?? array()))),
			'is_final_eligible'  => $is_real && $exact_match && $this->image_usage_allows_final($usage_permission) && 'clean' === $watermark_status && 'clean' === $seller_logo_status,
			'checked_at'         => current_time('mysql'),
			'notes'              => sanitize_text_field($item['notes'] ?? ''),
		);
	}

	private function detect_image_source_type($source_url, $final_url, $mode) {
		if (in_array($mode, array('illustrated_fallback', 'illustrated_fallback_colored_pencil', 'placeholder_generated_for_review'), true)) {
			return 'generated_review_only';
		}

		$host = $this->source_host($source_url ? $source_url : $final_url);
		if (!$host) {
			return 'unknown';
		}
		if (false !== strpos($host, 'komarena.sk')) {
			return 'internal_komarena_media';
		}
		if (preg_match('/(arduino\.cc|espressif\.com|raspberrypi\.com|bosch-sensortec\.com|seeedstudio\.com|adafruit\.com|sparkfun\.com)$/i', $host)) {
			return 'manufacturer_or_official';
		}
		if (preg_match('/(mouser|digikey|farnell|rs-online|tme|gme|techfun)/i', $host)) {
			return 'verified_distributor_or_listing';
		}
		if (preg_match('/\.pdf($|\?)/i', (string) $source_url)) {
			return 'datasheet';
		}

		return 'external_web';
	}

	private function default_image_usage_permission($source_type, $mode) {
		if (in_array($mode, array('illustrated_fallback', 'illustrated_fallback_colored_pencil', 'placeholder_generated_for_review'), true)) {
			return 'blocked_for_final';
		}
		if ('internal_komarena_media' === $source_type) {
			return 'needs_origin_confirmation';
		}

		return 'needs_rights_review';
	}

	private function default_image_visual_status($source_url, $mode) {
		if (in_array($mode, array('illustrated_fallback', 'illustrated_fallback_colored_pencil', 'placeholder_generated_for_review'), true)) {
			return 'not_applicable';
		}
		$normalized = strtolower(remove_accents((string) $source_url));
		if (preg_match('/watermark|logo|brand|seller|predajca|shop/i', $normalized)) {
			return 'suspected';
		}

		return 'unknown';
	}

	private function image_usage_allows_final($permission) {
		return in_array(sanitize_key($permission), array('allowed', 'allowed_internal', 'own_photo', 'licensed', 'manufacturer_allowed'), true);
	}

	private function truthy($value) {
		if (is_bool($value)) {
			return $value;
		}

		return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'ano', 'ai', 'generated'), true);
	}

	private function source_host($url) {
		$host = wp_parse_url((string) $url, PHP_URL_HOST);
		return $host ? strtolower((string) $host) : '';
	}

	private function normalize_results($results) {
		$out = array();
		foreach ((array) $results as $result) {
			if (is_wp_error($result)) {
				$out[] = array(
					'status'  => 'failed',
					'message' => $result->get_error_message(),
				);
			} else {
				$out[] = $result;
			}
		}

		return $out;
	}
}
