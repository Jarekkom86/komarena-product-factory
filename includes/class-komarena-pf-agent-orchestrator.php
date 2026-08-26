<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Agent_Orchestrator {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function run_scheduled() {
		return $this->run(array('trigger' => 'scheduled'));
	}

	public function run($args = array()) {
		if (!$this->plugin->settings->get('agent_autopilot', 1)) {
			return array('status' => 'disabled');
		}

		if (get_transient('komarena_pf_agent_lock')) {
			$this->plugin->logger->log('info', 'Beh agenta bol preskočený, pretože je aktívny zámok.', 'agent');
			return array('status' => 'locked');
		}

		set_transient('komarena_pf_agent_lock', 1, 8 * MINUTE_IN_SECONDS);

		$this->plugin->logger->new_run_id('agent');
		$trigger = sanitize_key($args['trigger'] ?? 'manual');
		$summary = array(
			'trigger'          => $trigger,
			'sources_seeded'   => false,
			'preflight'        => array(),
			'plugin_audit'     => null,
			'plugin_cleanup'   => null,
			'site_layout'      => null,
			'autonomy'         => null,
			'retried_failed'   => 0,
			'processed_queue'  => 0,
			'processed_tasks'  => 0,
			'audit'            => null,
			'audit_repairs'    => array('rebuilt' => 0, 'planned' => 0, 'skipped' => 0),
			'rebuilt_products' => 0,
			'skipped_rebuilds' => 0,
			'warnings'         => array(),
			'started_at'       => current_time('mysql'),
		);

		$this->plugin->logger->log('info', 'Agent orchestrátor spustený.', 'agent', array('trigger' => $trigger));

		try {
			$summary['sources_seeded'] = $this->ensure_recommended_sources();
			$summary['preflight'] = $this->preflight();
			foreach ($summary['preflight'] as $check => $result) {
				if (empty($result['pass']) && !empty($result['critical'])) {
					$summary['warnings'][] = $result['message'];
				}
			}

			if (!$this->preflight_allows_work($summary['preflight'])) {
				$this->plugin->logger->log('error', 'Agent bol zastavený pre kritickú chybu predbežnej kontroly.', 'agent', $summary);
				$summary['finished_at'] = current_time('mysql');
				update_option('komarena_pf_last_agent_summary', $summary, false);
				delete_transient('komarena_pf_agent_lock');
				return $summary;
			}

			if ($this->plugin->settings->get('agent_full_site_autopilot', 1)) {
				$summary['plugin_audit'] = $this->run_plugin_housekeeper();
				$summary['site_layout'] = $this->run_site_layout_autopilot();
				if ($this->plugin->settings->get('agent_auto_autonomy_supervisor', 1) && !empty($this->plugin->autonomy)) {
					$summary['autonomy'] = $this->plugin->autonomy->run_cycle(array(
						'trigger' => 'agent',
						'limit' => (int) $this->plugin->settings->get('agent_autonomy_audit_limit', 100),
						'run_product_audit' => false,
					));
				}
			}

			if ($this->plugin->settings->get('agent_retry_failed', 1)) {
				$summary['retried_failed'] = $this->retry_failed_queue();
			}

			$queue_limit = (int) $this->plugin->settings->get('queue_batch_size', 3);
			$queue_results = $this->plugin->queue->run_next($queue_limit);
			$summary['processed_queue'] = is_array($queue_results) ? count($queue_results) : 0;

			if (!empty($this->plugin->tasks) && $this->plugin->settings->get('agent_task_autopilot', 1)) {
				$task_limit = (int) $this->plugin->settings->get('agent_task_batch_size', 3);
				$task_results = $this->plugin->tasks->run_next($task_limit);
				$summary['processed_tasks'] = is_array($task_results) ? count($task_results) : 0;
			}

			if ($this->should_run_audit($trigger)) {
				$summary['audit'] = $this->plugin->audit->audit_products((int) $this->plugin->settings->get('agent_full_audit_limit', 100));
				update_option('komarena_pf_last_agent_audit', current_time('timestamp'));
				$summary['audit_repairs'] = $this->repair_audited_products();
			}

			if ($this->plugin->settings->get('agent_rebuild_own_products', 1)) {
				$rebuilt = $this->rebuild_own_needs_review_products();
				$summary['rebuilt_products'] = $rebuilt['rebuilt'];
				$summary['skipped_rebuilds'] = $rebuilt['skipped'];
			}
		} catch (Exception $e) {
			$summary['warnings'][] = $e->getMessage();
			$this->plugin->logger->log('error', $e->getMessage(), 'agent', $summary);
		}

		$summary['finished_at'] = current_time('mysql');
		update_option('komarena_pf_last_agent_summary', $summary, false);
		$this->plugin->logger->log('info', 'Agent orchestrátor dokončený.', 'agent', $summary);

		delete_transient('komarena_pf_agent_lock');
		return $summary;
	}

	public function preflight() {
		$upload = wp_upload_dir();
		$upload_ok = empty($upload['error']) && !empty($upload['path']) && wp_mkdir_p($upload['path']);
		if ($upload_ok && function_exists('wp_is_writable')) {
			$upload_ok = wp_is_writable($upload['path']);
		}

		$checks = array(
			'woocommerce' => array(
				'pass'     => $this->plugin->has_woocommerce(),
				'critical' => true,
				'message'  => $this->plugin->has_woocommerce() ? 'WooCommerce je dostupný.' : 'WooCommerce nie je aktívny.',
			),
			'uploads' => array(
				'pass'     => (bool) $upload_ok,
				'critical' => true,
				'message'  => $upload_ok ? 'Nahrávací adresár je zapisovateľný.' : 'Nahrávací adresár nie je dostupný alebo zapisovateľný.',
			),
			'gd' => array(
				'pass'     => function_exists('imagecreatetruecolor'),
				'critical' => false,
				'message'  => function_exists('imagecreatetruecolor') ? 'Grafická knižnica PHP je dostupná pre lokálnu štandardizáciu obrázkov. Finálny produkt stále vyžaduje originálne alebo reálne produktové fotky.' : 'Grafická knižnica PHP chýba; lokálna štandardizácia obrázkov sa nevytvorí.',
			),
			'zip' => array(
				'pass'     => class_exists('ZipArchive'),
				'critical' => false,
				'message'  => class_exists('ZipArchive') ? 'ZIP rozšírenie PHP je dostupné pre CSV a obrázkové balíky.' : 'ZIP rozšírenie PHP chýba; CSV a obrázkové balíky sa nevytvoria.',
			),
			'wp_cron' => array(
				'pass'     => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
				'critical' => false,
				'message'  => (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'Plánovač WordPressu je vypnutý, hosting musí spúšťať plánované úlohy externe.' : 'Plánovač WordPressu je dostupný.',
			),
			'sources' => array(
				'pass'     => '' !== trim((string) $this->plugin->settings->get('trusted_source_urls', '')),
				'critical' => false,
				'message'  => '' !== trim((string) $this->plugin->settings->get('trusted_source_urls', '')) ? 'Globálne overené zdroje sú nastavené.' : 'Globálne overené zdroje nie sú nastavené; produkty môžu skončiť v stave na kontrolu.',
			),
		);

		$this->plugin->logger->log('info', 'Predbežná kontrola agenta dokončená.', 'agent', $checks);
		return $checks;
	}

	public function schedule_soon() {
		if (!wp_next_scheduled('komarena_pf_agent_tick')) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'komarena_pf_every_five_minutes', 'komarena_pf_agent_tick');
		}

		if (!wp_next_scheduled('komarena_pf_agent_tick_once')) {
			wp_schedule_single_event(time() + 20, 'komarena_pf_agent_tick_once');
		}
	}

	private function preflight_allows_work($checks) {
		foreach ($checks as $check) {
			if (!empty($check['critical']) && empty($check['pass'])) {
				return false;
			}
		}

		return true;
	}

	private function ensure_recommended_sources() {
		if (!$this->plugin->settings->get('agent_auto_seed_sources', 1)) {
			return false;
		}

		$settings = $this->plugin->settings->all();
		if ('' !== trim((string) ($settings['trusted_source_urls'] ?? ''))) {
			return false;
		}

		$settings['trusted_source_urls'] = implode("\n", KomArena_PF_Settings::recommended_source_urls());
		update_option(KomArena_PF_Settings::OPTION, $settings, false);
		$this->plugin->logger->log('info', 'Agent doplnil odporúčané overené zdroje.', 'agent', array(
			'sources' => KomArena_PF_Settings::recommended_source_urls(),
		));

		return true;
	}

	private function run_plugin_housekeeper() {
		if (!$this->plugin->settings->get('agent_auto_plugin_audit', 1) || empty($this->plugin->housekeeper)) {
			return null;
		}

		$audit = $this->plugin->housekeeper->audit_installed_plugins();
		if (is_wp_error($audit)) {
			$this->plugin->logger->log('warning', 'Audit doplnkov zlyhal: ' . $audit->get_error_message(), 'agent');
			return array('status' => 'failed', 'message' => $audit->get_error_message());
		}

		$cleanup = null;
		if ($this->plugin->settings->get('agent_auto_cleanup_plan', 1)) {
			$cleanup = $this->plugin->housekeeper->cleanup_plan();
			if (is_wp_error($cleanup)) {
				$cleanup = array('status' => 'failed', 'message' => $cleanup->get_error_message());
			}
		}

		return array(
			'status'       => $audit['status'] ?? 'done',
			'summary'      => $audit['summary'] ?? array(),
			'cleanup_plan' => is_array($cleanup) ? array(
				'deactivate_review' => count((array) ($cleanup['deactivate_review'] ?? array())),
				'manual_check'      => count((array) ($cleanup['manual_check'] ?? array())),
				'protected'         => count((array) ($cleanup['protected'] ?? array())),
			) : null,
		);
	}

	private function run_site_layout_autopilot() {
		if (!$this->plugin->settings->get('agent_auto_homepage_layout', 1) || empty($this->plugin->layout)) {
			return null;
		}

		$result = $this->plugin->layout->apply_homepage_sidebar_layout(array());
		if (is_wp_error($result)) {
			$this->plugin->logger->log('warning', 'Automatická úprava domovskej stránky zlyhala: ' . $result->get_error_message(), 'agent');
			return array('status' => 'failed', 'message' => $result->get_error_message());
		}

		return $result;
	}

	private function retry_failed_queue() {
		global $wpdb;

		$limit = (int) $this->plugin->settings->get('agent_retry_limit', 3);
		if ($limit <= 0) {
			return 0;
		}

		$table = $wpdb->prefix . 'komarena_pf_queue';
		$items = $wpdb->get_results($wpdb->prepare(
			"SELECT id, attempts FROM {$table} WHERE status = 'failed' AND attempts < 3 ORDER BY updated_at ASC LIMIT %d",
			$limit
		), ARRAY_A);

		foreach ($items as $item) {
			$this->plugin->queue->mark_status((int) $item['id'], 'waiting', array(
				'last_error' => '',
			));
		}

		if (!empty($items)) {
			$this->plugin->logger->log('info', 'Agent zopakoval zlyhané položky fronty.', 'agent', array('count' => count($items)));
		}

		return count($items);
	}

	private function should_run_audit($trigger = 'scheduled') {
		if (!$this->plugin->settings->get('agent_scheduled_audit', 1)) {
			return false;
		}

		if ('manual' === $trigger && $this->plugin->settings->get('agent_audit_on_manual', 1)) {
			return true;
		}

		$last = (int) get_option('komarena_pf_last_agent_audit', 0);
		$interval = max(1, (int) $this->plugin->settings->get('agent_audit_interval_hours', 24)) * HOUR_IN_SECONDS;

		return !$last || (current_time('timestamp') - $last) >= $interval;
	}

	private function repair_audited_products() {
		if (!$this->plugin->settings->get('agent_auto_repair_audit', 1)) {
			return array('rebuilt' => 0, 'planned' => 0, 'skipped' => 0);
		}
		if ($this->plugin->settings->get('agent_bulk_rebuild_lock', 1)) {
			$this->plugin->logger->log('warning', 'Oprava po audite bola preskočená pre STOP-SHIP zámok hromadného prebudovania.', 'agent');
			return array('rebuilt' => 0, 'planned' => 0, 'skipped' => 0, 'locked' => true);
		}

		$limit = (int) $this->plugin->settings->get('agent_audit_repair_limit', 3);
		if ($limit <= 0) {
			return array('rebuilt' => 0, 'planned' => 0, 'skipped' => 0);
		}

		$allow_live = (bool) $this->plugin->settings->get('agent_repair_live_products', 0);
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => $limit * 5,
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_komarena_pf_audit_issues',
					'compare' => 'EXISTS',
				),
			),
		));

		$summary = array(
			'rebuilt' => 0,
			'planned' => 0,
			'skipped' => 0,
			'live_skipped' => 0,
			'failed' => 0,
		);
		$repair_plan = array();

		foreach ($query->posts as $product_id) {
			if ($summary['rebuilt'] >= $limit) {
				break;
			}

			$product_id = (int) $product_id;
			$issues = json_decode((string) get_post_meta($product_id, '_komarena_pf_audit_issues', true), true);
			if (empty($issues) || !is_array($issues)) {
				$summary['skipped']++;
				continue;
			}

			$last = (int) get_post_meta($product_id, '_komarena_pf_last_audit_repair', true);
			if ($last && (current_time('timestamp') - $last) < 12 * HOUR_IN_SECONDS) {
				$summary['skipped']++;
				continue;
			}

			$status = get_post_status($product_id);
			if ('publish' === $status && !$allow_live) {
				$summary['planned']++;
				$summary['live_skipped']++;
				$repair_plan[] = array(
					'product_id' => $product_id,
					'title'      => get_the_title($product_id),
					'issues'     => array_values($issues),
					'reason'     => 'Produkt je publikovany; automaticka live oprava je vypnuta.',
				);
				continue;
			}

			$result = $this->plugin->rebuild->rebuild($product_id, array(
				'overwrite_price' => false,
				'force_images'    => false,
				'preserve_status' => true,
			));

			update_post_meta($product_id, '_komarena_pf_last_audit_repair', current_time('timestamp'));

			if (is_wp_error($result)) {
				$summary['failed']++;
				$repair_plan[] = array(
					'product_id' => $product_id,
					'title'      => get_the_title($product_id),
					'issues'     => array_values($issues),
					'reason'     => $result->get_error_message(),
				);
				$this->plugin->logger->log('warning', 'Prebudovanie pri oprave auditu zlyhalo: ' . $result->get_error_message(), 'agent', array('product_id' => $product_id), null, $product_id);
				continue;
			}

			$summary['rebuilt']++;
		}

		update_option('komarena_pf_last_audit_repair_plan', array(
			'generated_at' => current_time('mysql'),
			'summary'      => $summary,
			'items'        => $repair_plan,
		), false);

		$this->plugin->logger->log('info', 'Opravný beh po audite dokončený.', 'agent', $summary);
		return $summary;
	}

	private function issues_need_images($issues) {
		foreach ((array) $issues as $issue) {
			$issue = strtolower(remove_accents((string) $issue));
			if (false !== strpos($issue, 'obraz') || false !== strpos($issue, 'galeria')) {
				return true;
			}
		}

		return false;
	}

	private function rebuild_own_needs_review_products() {
		if ($this->plugin->settings->get('agent_bulk_rebuild_lock', 1)) {
			$this->plugin->logger->log('warning', 'Prebudovanie vlastných produktov bolo preskočené pre STOP-SHIP zámok hromadného prebudovania.', 'agent');
			return array('rebuilt' => 0, 'skipped' => 0, 'locked' => true);
		}

		$limit = (int) $this->plugin->settings->get('agent_rebuild_limit', 2);
		if ($limit <= 0) {
			return array('rebuilt' => 0, 'skipped' => 0);
		}

		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('draft', 'pending', 'private'),
			'posts_per_page' => $limit * 3,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => '_komarena_pf_status',
					'value' => 'needs_review',
				),
				array(
					'key'     => '_komarena_pf_manifest',
					'compare' => 'EXISTS',
				),
			),
		));

		$rebuilt = 0;
		$skipped = 0;

		foreach ($query->posts as $product_id) {
			if ($rebuilt >= $limit) {
				break;
			}

			if (!$this->should_rebuild_product((int) $product_id)) {
				$skipped++;
				continue;
			}

			$result = $this->plugin->rebuild->rebuild((int) $product_id, array(
				'overwrite_price' => false,
				'force_images'    => false,
			));

			update_post_meta((int) $product_id, '_komarena_pf_last_agent_rebuild', current_time('timestamp'));

			if (!is_wp_error($result)) {
				$rebuilt++;
			} else {
				$skipped++;
				$this->plugin->logger->log('warning', 'Prebudovanie agentom zlyhalo: ' . $result->get_error_message(), 'agent', array('product_id' => $product_id), null, $product_id);
			}
		}

		if ($rebuilt || $skipped) {
			$this->plugin->logger->log('info', 'Beh prebudovania vlastných produktov dokončený.', 'agent', array(
				'rebuilt' => $rebuilt,
				'skipped' => $skipped,
			));
		}

		return array('rebuilt' => $rebuilt, 'skipped' => $skipped);
	}

	private function should_rebuild_product($product_id) {
		$last = (int) get_post_meta($product_id, '_komarena_pf_last_agent_rebuild', true);
		if ($last && (current_time('timestamp') - $last) < 12 * HOUR_IN_SECONDS) {
			return false;
		}

		$qa = json_decode((string) get_post_meta($product_id, '_komarena_pf_qa_report', true), true);
		if (empty($qa['missing']) || !is_array($qa['missing'])) {
			return true;
		}

		$only_external_blockers = true;
		foreach ($qa['missing'] as $missing) {
			$missing = strtolower(remove_accents((string) $missing));
			if (false === strpos($missing, 'zdroj') && false === strpos($missing, 'placeholder') && false === strpos($missing, 'obrazky su iba')) {
				$only_external_blockers = false;
				break;
			}
		}

		return !$only_external_blockers;
	}
}
