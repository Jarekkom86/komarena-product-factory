<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Autonomy_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function last_report() {
		$report = get_option('komarena_pf_last_autonomy_report', array());
		return is_array($report) ? $report : array();
	}

	public function run_cycle($args = array()) {
		if (!is_array($args)) {
			$args = array();
		}

		$limit = max(1, min(500, absint($args['limit'] ?? $this->plugin->settings->get('agent_autonomy_audit_limit', 100))));
		$trigger = sanitize_key($args['trigger'] ?? 'autonomny_dozor');
		$run_product_audit = array_key_exists('run_product_audit', $args) ? !empty($args['run_product_audit']) : true;
		$safe_repairs = array_key_exists('safe_repairs', $args) ? !empty($args['safe_repairs']) : (bool) $this->plugin->settings->get('agent_autonomy_safe_repairs', 1);

		$report = array(
			'status'        => 'done',
			'trigger'       => $trigger ? $trigger : 'autonomny_dozor',
			'started_at'    => current_time('mysql'),
			'finished_at'   => '',
			'agent_version' => KOMARENA_PF_VERSION,
			'mode'          => 'autonomny_dozor_s_bezpecnostnou_brdzou',
			'checks'        => array(),
			'metrics'       => array(),
			'warnings'      => array(),
			'proposals'     => array(),
			'safe_actions'  => array(),
			'guards'        => $this->guardrails(),
			'note'          => $this->mode_note(),
		);

		$preflight = !empty($this->plugin->agent) ? $this->plugin->agent->preflight() : array();
		$report['checks']['predbezna_kontrola'] = $this->summarize_preflight($preflight);
		foreach ((array) $preflight as $check) {
			if (!empty($check['critical']) && empty($check['pass'])) {
				$report['warnings'][] = $check['message'] ?? 'Kritická predbežná kontrola zlyhala.';
			}
		}

		$report['metrics']['fronta'] = !empty($this->plugin->queue) ? $this->plugin->queue->counts() : array();
		$report['metrics']['ulohy'] = !empty($this->plugin->tasks) ? $this->plugin->tasks->counts() : array();

		$plugin_audit = $this->plugin_audit();
		$report['checks']['doplnky'] = $plugin_audit;
		if (!empty($plugin_audit['summary']['high_risk']) || !empty($plugin_audit['summary']['conflicts'])) {
			$report['proposals'][] = array(
				'type'    => 'doplnky',
				'title'   => 'Skontrolovať rizikové alebo konfliktné doplnky',
				'message' => sprintf(
					'Agent našiel rizikové doplnky: %d, konflikty: %d. Vypnutie doplnkov vyžaduje potvrdenie administrátora.',
					(int) ($plugin_audit['summary']['high_risk'] ?? 0),
					(int) ($plugin_audit['summary']['conflicts'] ?? 0)
				),
			);
		}

		$layout_audit = $this->site_layout_audit();
		$report['checks']['rozlozenie_webu'] = $layout_audit;
		if (!empty($layout_audit['missing'])) {
			$report['proposals'][] = array(
				'type'    => 'rozlozenie_webu',
				'title'   => 'Opraviť rozloženie domovskej stránky',
				'message' => 'Domovská stránka nemá všetky prvky KomArena rozloženia. Úprava rozloženia má vlastnú zálohu a samostatné tlačidlo.',
			);
		}

		if ($this->plugin->has_woocommerce()) {
			$report['metrics']['produktovy_prehlad'] = $this->plugin->audit->dashboard_counts();
			if ($run_product_audit) {
				$audit = $this->plugin->audit->audit_products($limit);
				if (is_wp_error($audit)) {
					$report['warnings'][] = $audit->get_error_message();
				} else {
					$report['checks']['produktovy_audit'] = $audit;
					if (!empty($audit['with_issues'])) {
						$report['proposals'][] = array(
							'type'    => 'produkty',
							'title'   => 'Opraviť produkty podľa KomArena štandardu',
							'message' => sprintf('Produktový audit našiel %d produktov s problémami. Hromadný rebuild ostáva zamknutý, opravy treba púšťať kontrolovane.', (int) $audit['with_issues']),
						);
					}
				}
			}

			if ($this->plugin->settings->get('agent_autonomy_price_watch', 1)) {
				$price_watch = $this->price_watch($limit);
				$report['checks']['cenova_kontrola'] = $price_watch;
				if (!empty($price_watch['issues_total'])) {
					$report['proposals'][] = array(
						'type'    => 'ceny',
						'title'   => 'Skontrolovať ceny a daňový stav',
						'message' => sprintf('Cenová kontrola našla %d položiek na preverenie. Agent ceny nemení bez schválenia.', (int) $price_watch['issues_total']),
					);
				}
			} else {
				$report['checks']['cenova_kontrola'] = array('status' => 'skipped', 'message' => 'Cenová kontrola je vypnutá v nastaveniach.');
			}
		} else {
			$report['warnings'][] = 'WooCommerce nie je aktívny, produktová a cenová kontrola bola preskočená.';
		}

		if ($this->plugin->settings->get('agent_autonomy_law_watch', 1)) {
			$legal = $this->legal_presence_audit();
			$report['checks']['legislativne_stranky'] = $legal;
			if (!empty($legal['missing']) || !empty($legal['weak_matches'])) {
				$areas = (array) ($legal['missing'] ?? array());
				foreach ((array) ($legal['weak_matches'] ?? array()) as $weak_area) {
					$areas[] = $weak_area . ' (slabá zhoda)';
				}
				$report['proposals'][] = array(
					'type'    => 'legislativa',
					'title'   => 'Doplniť alebo skontrolovať povinné informačné stránky',
					'message' => 'Chýbajú alebo majú iba slabú obsahovú zhodu tieto informačné oblasti: ' . implode(', ', $areas) . '. Agent kontroluje prítomnosť jasne pomenovaných stránok, nie právnu správnosť textu.',
				);
			}
			if ($safe_repairs && !empty($this->plugin->legal_pages) && $this->legal_needs_cookies_draft($legal)) {
				$draft = $this->plugin->legal_pages->create_draft('cookies', array('overwrite' => false));
				if (is_wp_error($draft)) {
					$report['warnings'][] = $draft->get_error_message();
				} else {
					$report['safe_actions']['koncept_cookies'] = array(
						'status'   => $draft['status'] ?? 'needs_review',
						'page_id'  => (int) ($draft['page_id'] ?? 0),
						'edit_url' => $draft['edit_url'] ?? '',
						'note'     => 'Vytvorený iba koncept. Stránka nie je publikovaná bez schválenia.',
					);
				}
			}
		} else {
			$report['checks']['legislativne_stranky'] = array('status' => 'skipped', 'message' => 'Kontrola informačných stránok je vypnutá v nastaveniach.');
		}

		if ($safe_repairs && !empty($this->plugin->rebuild)) {
			$cleanup = $this->plugin->rebuild->cleanup_bad_product_images(array('limit' => $limit));
			if (is_wp_error($cleanup)) {
				$report['warnings'][] = $cleanup->get_error_message();
			} else {
				$report['safe_actions']['upratanie_obrazkov'] = array(
					'changed'         => (int) ($cleanup['changed'] ?? 0),
					'detached_images' => (int) ($cleanup['detached_images'] ?? 0),
					'title_restores'  => count((array) ($cleanup['title_restores'] ?? array())),
					'note'            => 'Bez mazania médií a bez hromadného prebudovania produktov.',
				);
			}
		}

		if (!empty($report['warnings']) || !empty($report['proposals'])) {
			$report['status'] = 'needs_review';
		}

		$report['finished_at'] = current_time('mysql');
		update_option('komarena_pf_last_autonomy_report', $report, false);
		$this->plugin->logger->log('info', 'Autonómny dozor dokončený.', 'autonomny-dozor', array(
			'status'       => $report['status'],
			'upozornenia'  => count($report['warnings']),
			'navrhy'       => count($report['proposals']),
			'bezpecne_akcie' => count($report['safe_actions']),
		));

		return $report;
	}

	private function guardrails() {
		$production = (bool) $this->plugin->settings->get('production_mode', 0);
		$auto_publish = (bool) $this->plugin->settings->get('auto_publish', 0);
		$bulk_lock = (bool) $this->plugin->settings->get('agent_bulk_rebuild_lock', 1);

		return array(
			'hromadne_prebudovanie' => $bulk_lock ? 'blokované' : 'povolené v malých dávkach so zálohou a kontrolou kvality',
			'publikovanie'          => ($production && $auto_publish) ? 'automaticky iba po overených zdrojoch, reálnych obrázkoch a úspešnej kontrole kvality' : 'iba po schválení a kontrole kvality',
			'mazanie_medii'         => 'blokované',
			'vypinanie_doplnkov'    => 'iba po výslovnom potvrdení',
			'zmena_cien'            => 'iba ako návrh na schválenie',
			'produktove_obrazky'    => 'originálne alebo reálne, bez vodoznakov a bez cudzieho predajného loga',
			'technicke_tvrdenia'    => 'iba podľa overených zdrojov',
		);
	}

	private function mode_note() {
		if ($this->plugin->settings->get('production_mode', 0)) {
			return 'Autonómny dozor beží v ostrej prevádzke. Bezpečné opravy a publikovanie pripravených produktov robí sám, ale len po overených zdrojoch, reálnych obrázkoch, kontrole kvality a bez mazania médií alebo vypínania doplnkov bez potvrdenia.';
		}

		return 'Autonómny dozor kontroluje web a vykonáva iba bezpečné opravy. Hromadné prebudovanie, publikovanie, mazanie médií, vypínanie doplnkov a zmena cien ostávajú blokované bez schválenia.';
	}

	private function summarize_preflight($checks) {
		$total = 0;
		$passed = 0;
		$failed = 0;
		$critical_failed = 0;

		foreach ((array) $checks as $check) {
			$total++;
			if (!empty($check['pass'])) {
				$passed++;
			} else {
				$failed++;
				if (!empty($check['critical'])) {
					$critical_failed++;
				}
			}
		}

		return array(
			'status'          => $critical_failed ? 'failed' : ($failed ? 'needs_review' : 'done'),
			'total'           => $total,
			'passed'          => $passed,
			'failed'          => $failed,
			'critical_failed' => $critical_failed,
			'items'           => $checks,
		);
	}

	private function plugin_audit() {
		if (empty($this->plugin->housekeeper)) {
			return array('status' => 'skipped', 'message' => 'Modul auditu doplnkov nie je dostupný.');
		}

		$audit = $this->plugin->housekeeper->audit_installed_plugins();
		if (is_wp_error($audit)) {
			return array('status' => 'failed', 'message' => $audit->get_error_message());
		}

		return array(
			'status'  => $audit['status'] ?? 'done',
			'summary' => $audit['summary'] ?? array(),
		);
	}

	private function site_layout_audit() {
		if (empty($this->plugin->layout)) {
			return array('status' => 'skipped', 'message' => 'Modul rozloženia webu nie je dostupný.');
		}

		$page_id = $this->plugin->layout->default_homepage_id();
		if (!$page_id) {
			return array('status' => 'needs_review', 'message' => 'Domovská stránka nebola nájdená.', 'missing' => array('domovská stránka'));
		}

		$audit = $this->plugin->layout->audit_homepage_sidebar_layout(array('page_id' => $page_id));
		if (is_wp_error($audit)) {
			return array('status' => 'failed', 'message' => $audit->get_error_message(), 'page_id' => $page_id);
		}

		$audit['page_id'] = $page_id;
		return $audit;
	}

	private function legal_presence_audit() {
		$requirements = array(
			'obchodne_podmienky' => array(
				'label' => 'Obchodné podmienky',
				'terms' => array('obchodne podmienky', 'vseobecne obchodne podmienky', 'vop'),
			),
			'odstupenie' => array(
				'label' => 'Odstúpenie od zmluvy',
				'terms' => array('odstupenie od zmluvy', 'odstupenie', 'vratenie tovaru', 'vratenie'),
			),
			'reklamacie' => array(
				'label' => 'Reklamačný poriadok',
				'terms' => array('reklamacny poriadok', 'reklamacie', 'reklamacia', 'zaruka'),
			),
			'ochrana_udajov' => array(
				'label' => 'Ochrana osobných údajov',
				'terms' => array('ochrana osobnych udajov', 'gdpr', 'zasady ochrany osobnych udajov', 'sukromie'),
			),
			'cookies' => array(
				'label' => 'Cookies',
				'terms' => array('cookies', 'cookie', 'subory cookie'),
			),
			'kontakt' => array(
				'label' => 'Kontakt',
				'terms' => array('kontakt', 'kontakty'),
			),
			'doprava_platba' => array(
				'label' => 'Doprava a platba',
				'terms' => array('doprava a platba', 'doprava', 'platba', 'dorucenie'),
			),
		);

		$pages = get_pages(array(
			'post_status' => 'publish',
			'sort_column' => 'post_title',
		));

		$checks = array();
		$missing = array();
		$weak_matches = array();
		foreach ($requirements as $key => $definition) {
			$match = $this->find_page_match($pages, $definition['terms']);
			$page = is_array($match) && !empty($match['page']) ? $match['page'] : null;
			$strength = is_array($match) ? (string) ($match['strength'] ?? '') : '';
			$pass = $page && 'strong' === $strength;
			if (!$pass) {
				if ($page && 'weak' === $strength) {
					$weak_matches[] = $definition['label'];
				} else {
					$missing[] = $definition['label'];
				}
			}

			$checks[$key] = array(
				'label'    => $definition['label'],
				'pass'     => $pass,
				'confidence' => $strength ? $strength : 'missing',
				'confidence_label' => $pass ? 'silná zhoda' : ($page ? 'slabá zhoda' : 'nenájdené'),
				'page_id'  => $page ? (int) $page->ID : 0,
				'title'    => $page ? get_the_title($page) : '',
				'edit_url' => $page ? get_edit_post_link($page->ID, '') : '',
				'view_url' => $page ? get_permalink($page->ID) : '',
				'message'  => $pass ? 'Jasne pomenovaná stránka bola nájdená.' : ($page ? 'Našiel sa iba slabý obsahový výskyt. Treba skontrolovať, či existuje samostatná a jasne pomenovaná stránka.' : 'Stránka nebola nájdená.'),
			);
		}

		return array(
			'status' => empty($missing) && empty($weak_matches) ? 'done' : 'needs_review',
			'checks' => $checks,
			'missing' => $missing,
			'weak_matches' => $weak_matches,
			'note' => 'Kontrola overuje prítomnosť jasne pomenovaných informačných stránok. Slabá zhoda v obsahu je len upozornenie na kontrolu a nenahrádza právnu kontrolu textu odborníkom.',
		);
	}

	private function find_page_match($pages, $terms) {
		$weak_match = null;

		foreach ((array) $pages as $page) {
			$identity = $this->normalize_text($page->post_title . ' ' . $page->post_name);
			foreach ((array) $terms as $term) {
				if (false !== strpos($identity, $this->normalize_text($term))) {
					return array(
						'page' => $page,
						'strength' => 'strong',
					);
				}
			}
		}

		foreach ((array) $pages as $page) {
			$content = $this->normalize_text(wp_strip_all_tags($page->post_content));
			foreach ((array) $terms as $term) {
				if (false !== strpos($content, $this->normalize_text($term))) {
					$weak_match = array(
						'page' => $page,
						'strength' => 'weak',
					);
					break 2;
				}
			}
		}

		return $weak_match;
	}

	private function legal_needs_cookies_draft($legal) {
		if (!is_array($legal)) {
			return false;
		}
		$labels = array_merge((array) ($legal['missing'] ?? array()), (array) ($legal['weak_matches'] ?? array()));
		foreach ($labels as $label) {
			if ('cookies' === strtolower(remove_accents((string) $label))) {
				return true;
			}
		}

		return false;
	}

	private function price_watch($limit) {
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => max(1, min(500, absint($limit))),
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		));

		$out = array(
			'status'               => 'done',
			'checked'              => 0,
			'bad_price_ending'     => 0,
			'missing_price'        => 0,
			'not_taxable'          => 0,
			'issues_total'         => 0,
			'needs_review_examples'=> array(),
			'note'                 => 'Cenová kontrola iba hlási problémy. Ceny sa automaticky nemenia.',
		);

		foreach ($query->posts as $product_id) {
			$product = wc_get_product($product_id);
			if (!$product) {
				continue;
			}

			$out['checked']++;
			$price = $product->get_regular_price();
			if ('' === (string) $price) {
				$price = $product->get_price();
			}

			$issues = array();
			if ('' === (string) $price || (float) $price <= 0) {
				$out['missing_price']++;
				$issues[] = 'chýba cena';
			} elseif ('.99' !== substr(number_format((float) $price, 2, '.', ''), -3)) {
				$out['bad_price_ending']++;
				$issues[] = 'cena nekončí .99';
			}

			if ('taxable' !== $product->get_tax_status()) {
				$out['not_taxable']++;
				$issues[] = 'daňový stav nie je zdaniteľný';
			}

			if (!empty($issues) && count($out['needs_review_examples']) < 10) {
				$out['needs_review_examples'][] = array(
					'product_id' => (int) $product_id,
					'title'      => get_the_title($product_id),
					'issues'     => $issues,
				);
			}
		}

		$out['issues_total'] = (int) $out['bad_price_ending'] + (int) $out['missing_price'] + (int) $out['not_taxable'];
		if ($out['issues_total'] > 0) {
			$out['status'] = 'needs_review';
		}

		return $out;
	}

	private function normalize_text($text) {
		$text = remove_accents((string) $text);
		$text = strtolower($text);
		$text = preg_replace('/\s+/', ' ', $text);
		return trim($text);
	}
}
