<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Plugin_Housekeeper_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function audit_installed_plugins($payload = array()) {
		$this->load_plugin_api();

		$plugins = get_plugins();
		$active = (array) get_option('active_plugins', array());
		$network_active = is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', array())) : array();
		$updates = get_site_transient('update_plugins');
		$broken_active = array_values(array_diff(array_merge($active, $network_active), array_keys($plugins)));
		$required = $this->required_plugins();
		$rows = array();

		foreach ($plugins as $basename => $data) {
			$row = $this->inspect_plugin($basename, $data, $active, $network_active, $updates, $required, $plugins);
			$rows[] = $row;
		}

		$rows = $this->mark_group_conflicts($rows);
		$summary = $this->summarize($rows, $broken_active);
		$report = array(
			'status'        => ($summary['high_risk'] || $summary['conflicts'] || $summary['cleanup_candidates']) ? 'needs_review' : 'done',
			'generated_at'  => current_time('mysql'),
			'agent_version' => KOMARENA_PF_VERSION,
			'plugins'       => $rows,
			'broken_active' => $broken_active,
			'summary'       => $summary,
			'notes'         => array(
				'Agent nikdy nemaže doplnky automaticky.',
				'Deaktivácia je dostupná iba po výslovnom potvrdení administrátorom.',
				'Kritické KomArena, WooCommerce a SEO doplnky sú chránené pred hromadným vypnutím.',
			),
		);

		update_option('komarena_pf_last_plugin_audit', $report, false);
		$this->plugin->logger->log('info', 'Audit doplnkov dokončený', 'audit-doplnkov', $summary);

		return $report;
	}

	public function cleanup_plan($payload = array()) {
		$report = get_option('komarena_pf_last_plugin_audit', array());
		if (!is_array($report) || empty($report['plugins'])) {
			$report = $this->audit_installed_plugins($payload);
		}

		$plan = array(
			'status'             => 'needs_review',
			'deactivate_review'  => array(),
			'keep'               => array(),
			'manual_check'       => array(),
			'protected'          => array(),
			'broken_active_refs' => $report['broken_active'] ?? array(),
			'generated_at'       => current_time('mysql'),
		);

		foreach ((array) ($report['plugins'] ?? array()) as $row) {
			if (!empty($row['protected'])) {
				$plan['protected'][] = $row['basename'];
				continue;
			}
			if (!empty($row['cleanup_candidate']) && !empty($row['active'])) {
				$plan['deactivate_review'][] = $row['basename'];
				continue;
			}
			if ('high' === ($row['risk'] ?? 'low') || !empty($row['flags'])) {
				$plan['manual_check'][] = $row['basename'];
				continue;
			}
			$plan['keep'][] = $row['basename'];
		}

		update_option('komarena_pf_last_plugin_cleanup_plan', $plan, false);
		$this->plugin->logger->log('info', 'Plán upratania doplnkov pripravený', 'audit-doplnkov', $plan);

		return $plan;
	}

	public function deactivate_selected($payload = array()) {
		$this->load_plugin_api();

		if (empty($payload['admin_confirmed'])) {
			return new WP_Error('komarena_pf_plugin_cleanup_confirmation', 'Deaktivácia doplnkov vyžaduje výslovné potvrdenie administrátorom.');
		}

		$selected = array_filter(array_map('sanitize_text_field', (array) ($payload['plugins'] ?? array())));
		if (empty($selected)) {
			return new WP_Error('komarena_pf_plugin_cleanup_empty', 'Nie sú vybrané žiadne doplnky na deaktiváciu.');
		}

		$plugins = get_plugins();
		$audit = $this->audit_installed_plugins();
		$rows = array();
		foreach ((array) ($audit['plugins'] ?? array()) as $row) {
			$rows[$row['basename']] = $row;
		}

		$deactivated = array();
		$skipped = array();
		foreach ($selected as $basename) {
			if (!isset($plugins[$basename])) {
				$skipped[$basename] = 'Doplnok neexistuje.';
				continue;
			}
			if (!empty($rows[$basename]['protected'])) {
				$skipped[$basename] = 'Doplnok je chránený pred hromadnou deaktiváciou.';
				continue;
			}
			if (!empty($rows[$basename]['network_active'])) {
				$skipped[$basename] = 'Doplnok je aktívny sieťovo a vyžaduje kontrolu správcu siete.';
				continue;
			}
			if (!is_plugin_active($basename)) {
				$skipped[$basename] = 'Doplnok už nie je aktívny.';
				continue;
			}

			deactivate_plugins($basename, false, false);
			$deactivated[] = $basename;
		}

		$result = array(
			'status'      => empty($skipped) ? 'done' : 'needs_review',
			'deactivated' => $deactivated,
			'skipped'     => $skipped,
			'finished_at' => current_time('mysql'),
		);

		update_option('komarena_pf_last_plugin_cleanup_result', $result, false);
		$this->plugin->logger->log('warning', 'Deaktivácia doplnkov dokončená', 'audit-doplnkov', $result);

		$this->audit_installed_plugins();
		return $result;
	}

	public function required_plugins() {
		$required = array(
			'woocommerce/woocommerce.php' => array(
				'role'   => 'core_ecommerce',
				'reason' => 'WooCommerce je jadro e-shopu a produktového postupu.',
			),
			'wordpress-seo/wp-seo.php' => array(
				'role'   => 'seo',
				'reason' => 'Yoast SEO meta údaje sú súčasťou KomArena produktového štandardu.',
			),
		);

		return apply_filters('komarena_pf_required_plugins', $required);
	}

	private function inspect_plugin($basename, $data, $active, $network_active, $updates, $required, $all_plugins) {
		$name = $data['Name'] ?? $basename;
		$lower = strtolower(remove_accents($name . ' ' . $basename));
		$is_active = in_array($basename, $active, true) || in_array($basename, $network_active, true);
		$flags = array();
		$category = 'review';
		$risk = 'low';
		$recommendation = 'ponechať a sledovať pri ďalšom audite';
		$protected = false;
		$cleanup_candidate = false;
		$group = $this->group_for_plugin($lower);

		if (isset($required[$basename])) {
			$category = 'required';
			$recommendation = 'ponechať aktívny';
			$protected = true;
		}

		if (false !== strpos($lower, 'komarena')) {
			$category = 'komarena';
			$recommendation = 'ponechať, ak patrí k aktuálnemu dizajnu alebo produktovému postupu';
			$protected = (false !== strpos($lower, 'product factory') || false !== strpos($lower, 'ui system') || false !== strpos($lower, 'full e-shop'));
		}

		if ($basename === plugin_basename(KOMARENA_PF_FILE)) {
			$category = 'required';
			$recommendation = 'tento agent nesmie vypnúť sám seba';
			$protected = true;
		}

		if (!$is_active) {
			$cleanup_candidate = true;
			$recommendation = 'neaktívny doplnok; po kontrole závislostí kandidát na odstránenie mimo automatiky';
		}

		if ($is_active && preg_match('/maintenance|coming soon|lightstart|under construction/i', $lower)) {
			$flags[] = 'aktívny režim údržby alebo pripravovanej stránky môže blokovať predaj alebo indexáciu';
			$risk = 'medium';
			$cleanup_candidate = true;
			$recommendation = 'skontrolovať, či už nemá byť vypnutý';
		}

		if ($this->has_update($basename, $updates)) {
			$flags[] = 'dostupná aktualizácia';
			$risk = $this->max_risk($risk, 'medium');
		}

		if (false !== strpos($lower, 'komarena product factory') || false !== strpos($basename, 'komarena-product-factory')) {
			$count = 0;
			foreach (array_keys($all_plugins) as $plugin_file) {
				if (false !== strpos($plugin_file, 'komarena-product-factory')) {
					$count++;
				}
			}
			if ($count > 1) {
				$flags[] = 'viac inštalácií KomArena produktového agenta môže spôsobiť chybu, že súbor doplnku neexistuje';
				$risk = 'high';
			}
		}

		if (empty($flags) && 'review' === $category && $is_active) {
			$category = 'optional_active';
		}

		return array(
			'basename'          => $basename,
			'name'              => sanitize_text_field($name),
			'version'           => sanitize_text_field($data['Version'] ?? ''),
			'author'            => wp_strip_all_tags($data['AuthorName'] ?? $data['Author'] ?? ''),
			'active'            => $is_active,
			'network_active'    => in_array($basename, $network_active, true),
			'category'          => $category,
			'group'             => $group,
			'risk'              => $risk,
			'flags'             => $flags,
			'protected'         => $protected,
			'cleanup_candidate' => $cleanup_candidate,
			'recommendation'    => $recommendation,
		);
	}

	private function mark_group_conflicts($rows) {
		$active_groups = array();
		foreach ($rows as $index => $row) {
			if (!empty($row['active']) && !empty($row['group'])) {
				$active_groups[$row['group']][] = $index;
			}
		}

		foreach ($active_groups as $group => $indexes) {
			if (count($indexes) < 2 || !in_array($group, array('seo', 'cache', 'security', 'builder'), true)) {
				continue;
			}
			foreach ($indexes as $index) {
				$rows[$index]['flags'][] = 'viac aktívnych doplnkov v rovnakej skupine môže robiť konflikty';
				$rows[$index]['risk'] = $this->max_risk($rows[$index]['risk'], 'medium');
				if ('seo' === $group || 'cache' === $group) {
					$rows[$index]['cleanup_candidate'] = empty($rows[$index]['protected']);
				}
			}
		}

		return $rows;
	}

	private function summarize($rows, $broken_active) {
		$summary = array(
			'total'              => count($rows),
			'active'             => 0,
			'inactive'           => 0,
			'protected'          => 0,
			'cleanup_candidates' => 0,
			'high_risk'          => 0,
			'conflicts'          => 0,
			'updates'            => 0,
			'broken_active_refs' => count($broken_active),
		);

		foreach ($rows as $row) {
			$summary[empty($row['active']) ? 'inactive' : 'active']++;
			if (!empty($row['protected'])) {
				$summary['protected']++;
			}
			if (!empty($row['cleanup_candidate'])) {
				$summary['cleanup_candidates']++;
			}
			if ('high' === ($row['risk'] ?? 'low')) {
				$summary['high_risk']++;
			}
			if (!empty($row['flags'])) {
				$summary['conflicts']++;
			}
			foreach ((array) ($row['flags'] ?? array()) as $flag) {
				if (false !== strpos($flag, 'aktualizacia')) {
					$summary['updates']++;
					break;
				}
			}
		}

		return $summary;
	}

	private function group_for_plugin($lower) {
		$groups = array(
			'seo'      => array('yoast', 'rank math', 'aioseo', 'all in one seo', 'seopress'),
			'cache'    => array('cache', 'litespeed', 'wp rocket', 'w3 total', 'autoptimize', 'sg optimizer'),
			'security' => array('wordfence', 'solid security', 'ithemes security', 'sucuri', 'all-in-one security'),
			'builder'  => array('elementor', 'divi', 'wpbakery', 'beaver builder', 'bricks'),
			'backup'   => array('updraft', 'backup', 'duplicator', 'wpvivid'),
		);

		foreach ($groups as $group => $needles) {
			foreach ($needles as $needle) {
				if (false !== strpos($lower, $needle)) {
					return $group;
				}
			}
		}

		return '';
	}

	private function has_update($basename, $updates) {
		return is_object($updates) && !empty($updates->response) && isset($updates->response[$basename]);
	}

	private function max_risk($current, $candidate) {
		$order = array('low' => 1, 'medium' => 2, 'high' => 3);
		return ($order[$candidate] ?? 1) > ($order[$current] ?? 1) ? $candidate : $current;
	}

	private function load_plugin_api() {
		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
