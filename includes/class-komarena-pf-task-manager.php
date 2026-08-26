<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Task_Manager {
	private $plugin;
	private $statuses = array(
		'waiting',
		'running',
		'done',
		'needs_review',
		'failed',
		'skipped',
	);

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function create($task_type, $title, $payload = array(), $priority = 10, $scheduled_at = '') {
		global $wpdb;

		$task_type = sanitize_key($task_type);
		$types = $this->registered_task_types();
		if (!$task_type || !isset($types[$task_type])) {
			return new WP_Error('komarena_pf_invalid_task_type', 'Neznámy typ agentnej úlohy.');
		}

		if (!is_array($payload)) {
			$payload = array('value' => $payload);
		}

		$title = sanitize_text_field($title);
		if ('' === $title) {
			$title = $types[$task_type]['label'];
		}

		$now = current_time('mysql');
		if (!$scheduled_at) {
			$scheduled_at = $now;
		}

		$table = $wpdb->prefix . 'komarena_pf_tasks';
		$inserted = $wpdb->insert(
			$table,
			array(
				'task_type'    => $task_type,
				'title'        => $title,
				'status'       => 'waiting',
				'priority'     => max(1, min(100, absint($priority))),
				'payload'      => wp_json_encode($payload),
				'result'       => '',
				'last_error'   => '',
				'attempts'     => 0,
				'created_at'   => $now,
				'updated_at'   => $now,
				'scheduled_at' => $scheduled_at,
			),
			array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
		);

		if (!$inserted) {
			return new WP_Error('komarena_pf_task_insert_failed', 'Úlohu sa nepodarilo uložiť.');
		}

		$task_id = (int) $wpdb->insert_id;
		$this->plugin->logger->log('info', 'Agentná úloha vytvorená', 'úlohy', array(
			'task_id'   => $task_id,
			'task_type' => $task_type,
			'title'     => $title,
		));

		if ($this->plugin->settings->get('agent_task_autopilot', 1)) {
			$this->schedule_soon();
		}

		return $task_id;
	}

	public function registered_task_types() {
		$types = array(
			'process_queue' => array(
				'label'       => 'Spracovať produktovú frontu',
				'description' => 'Spustí ďalšiu dávku produktov z hromadnej fronty.',
				'future'      => false,
			),
			'supplier_import' => array(
				'label'       => 'Import zo zdrojových odkazov',
				'description' => 'Načíta dodávateľské odkazy a založí produktové úlohy vo fronte.',
				'future'      => false,
			),
			'csv_builder_image_package' => array(
				'label'       => 'CSV tvorca - obrázkový ZIP',
				'description' => 'Podľa KomArena CSV štandardu vytvorí prvý výstup: ZIP so 4 pomenovanými obrázkami.',
				'future'      => false,
			),
			'csv_builder_csv_package' => array(
				'label'       => 'CSV tvorca - WooCommerce CSV',
				'description' => 'Po odkaze jedného nahratého obrázka vytvorí WooCommerce CSV + manifest ZIP.',
				'future'      => false,
			),
			'audit' => array(
				'label'       => 'Audit existujúcich produktov',
				'description' => 'Prejde existujúce WooCommerce produkty a uloží auditné problémy.',
				'future'      => false,
			),
			'rebuild_product' => array(
				'label'       => 'Prebudovanie produktu',
				'description' => 'Prebuduje jeden WooCommerce produkt podľa KomArena štandardu.',
				'future'      => false,
			),
			'review_product' => array(
				'label'       => 'Kontrola kvality produktu',
				'description' => 'Znova spustí bránu kontroly kvality a aktualizuje stav pripravenosti alebo ručnej kontroly.',
				'future'      => false,
			),
			'cleanup_bad_product_images' => array(
				'label'       => 'Upratanie nevhodných produktových obrázkov',
				'description' => 'Odpojí dočasné, neoverené a nepresné obrázky z produktov bez mazania knižnice médií.',
				'future'      => false,
			),
			'find_product_images' => array(
				'label'       => 'Nájdenie produktových obrázkov',
				'description' => 'Vyhľadá a posúdi kandidátov obrázkov pre jeden produkt bez automatického publikovania.',
				'future'      => false,
			),
			'fetch_product_images' => array(
				'label'       => 'Načítanie kandidátov obrázkov',
				'description' => 'Načíta kandidátov obrázkov a uloží report pôvodu, zhody a rizík.',
				'future'      => false,
			),
			'qa_product_images' => array(
				'label'       => 'Kontrola produktových obrázkov',
				'description' => 'Skontroluje obrázkovú časť publikačnej brány produktu.',
				'future'      => false,
			),
			'standardize_product_images' => array(
				'label'       => 'Štandardizácia produktových obrázkov',
				'description' => 'Pripraví finálne obrázky iba z overených reálnych zdrojov alebo produkt zablokuje s dôvodom.',
				'future'      => false,
			),
			'upload_product_images' => array(
				'label'       => 'Nahratie produktových obrázkov',
				'description' => 'Nahrá a priradí 4 obrázky do WooCommerce iba po presnej zhode a kontrole pôvodu.',
				'future'      => false,
			),
			'repair_product_images' => array(
				'label'       => 'Oprava produktových obrázkov',
				'description' => 'Prejde celý obrázkový workflow pre jeden produkt: nájdenie, kontrola, nahratie a QA.',
				'future'      => false,
			),
			'image_recovery_run' => array(
				'label'       => 'Dávková obrázková obnova',
				'description' => 'Prejde produkty bez 4 obrázkov a opraví iba tie, ktoré majú overiteľné reálne obrázky.',
				'future'      => false,
			),
			'generate_blog_draft' => array(
				'label'       => 'Koncept blogového článku',
				'description' => 'Vytvorí koncept návodu alebo článku pre ďalšiu redakčnú úpravu.',
				'future'      => false,
			),
			'homepage_sidebar_layout' => array(
				'label'       => 'Rozloženie bočného panelu domovskej stránky',
				'description' => 'Obalí domovskú stránku do rozloženia ľavý obsah + pravý KomArena bočný panel.',
				'future'      => false,
			),
			'homepage_sidebar_audit' => array(
				'label'       => 'Audit bočného panelu domovskej stránky',
				'description' => 'Skontroluje, či domovská stránka obsahuje rozloženie, skrátený kód a zálohu.',
				'future'      => false,
			),
			'homepage_sidebar_restore' => array(
				'label'       => 'Obnova bočného panelu domovskej stránky',
				'description' => 'Vráti domovskú stránku zo zálohy pred úpravou bočného panelu.',
				'future'      => false,
			),
			'plugin_inventory_audit' => array(
				'label'       => 'Audit zoznamu doplnkov',
				'description' => 'Skontroluje nainštalované doplnky, riziká, duplicity a konflikty.',
				'future'      => false,
			),
			'plugin_cleanup_plan' => array(
				'label'       => 'Plán upratania doplnkov',
				'description' => 'Pripraví bezpečný plán, čo ponechať, čo skontrolovať a čo vypnúť po potvrdení.',
				'future'      => false,
			),
			'plugin_deactivate_selected' => array(
				'label'       => 'Deaktivácia vybraných doplnkov',
				'description' => 'Deaktivuje vybrané doplnky iba po výslovnom administrátorskom potvrdení.',
				'future'      => false,
			),
			'autonomous_supervisor' => array(
				'label'       => 'Autonómny dozor webu',
				'description' => 'Skontroluje produkty, ceny, doplnky, rozloženie webu, plánované úlohy a informačné stránky. Rizikové zásahy iba navrhne.',
				'future'      => false,
			),
			'legal_page_draft' => array(
				'label'       => 'Koncept informačnej stránky',
				'description' => 'Vytvorí koncept informačnej stránky, napríklad Cookies. Stránka ostane ako koncept na kontrolu.',
				'future'      => false,
			),
			'production_cycle' => array(
				'label'       => 'Ostrý produkčný cyklus',
				'description' => 'Spustí ostrú prevádzku agenta, bezpečné opravy a publikovanie produktov pripravených podľa KomArena kontroly kvality.',
				'future'      => false,
			),
			'generate_project_bundle' => array(
				'label'       => 'Tvorca projektových balíčkov',
				'description' => 'Pripravené rozhranie pre budúce generovanie projektových balíčkov.',
				'future'      => true,
			),
			'ean_scan_review' => array(
				'label'       => 'Kontrola EAN skenera',
				'description' => 'Pripravené rozhranie pre budúci import alebo kontrolu EAN skenov.',
				'future'      => true,
			),
			'price_monitor' => array(
				'label'       => 'Sledovanie konkurenčných cien',
				'description' => 'Pripravené rozhranie pre budúce sledovanie konkurenčných cien.',
				'future'      => true,
			),
			'collection_builder' => array(
				'label'       => 'Automatické produktové kolekcie',
				'description' => 'Pripravené rozhranie pre budúce automatické kolekcie produktov.',
				'future'      => true,
			),
			'home_assistant_project_page' => array(
				'label'       => 'Projektová stránka Home Assistant',
				'description' => 'Pripravené rozhranie pre budúce projektové stránky Home Assistant.',
				'future'      => true,
			),
		);

		return apply_filters('komarena_pf_task_types', $types);
	}

	public function counts() {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_tasks';
		$rows = $wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A);
		$out = array_fill_keys($this->statuses, 0);

		foreach ((array) $rows as $row) {
			$out[$row['status']] = (int) $row['total'];
		}

		return $out;
	}

	public function items($limit = 50, $status = '') {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_tasks';
		$limit = max(1, min(200, absint($limit)));
		$status = sanitize_key($status);

		if ($status && in_array($status, $this->statuses, true)) {
			return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit), ARRAY_A);
		}

		return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
	}

	public function get($id) {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_tasks';
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($id)), ARRAY_A);
	}

	public function next() {
		global $wpdb;

		$table = $wpdb->prefix . 'komarena_pf_tasks';
		$now = current_time('mysql');
		return $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'waiting' AND scheduled_at <= %s ORDER BY priority ASC, scheduled_at ASC, id ASC LIMIT 1",
			$now
		), ARRAY_A);
	}

	public function run_next($limit = 3) {
		$results = array();
		$limit = max(1, min(25, absint($limit)));

		for ($i = 0; $i < $limit; $i++) {
			$task = $this->next();
			if (!$task) {
				break;
			}

			$results[] = $this->run_task($task);
		}

		return $results;
	}

	public function run_task($task) {
		if (empty($task['id'])) {
			return new WP_Error('komarena_pf_task_missing', 'Úloha neexistuje.');
		}

		$task_id = (int) $task['id'];
		$type = sanitize_key($task['task_type']);
		$attempt = ((int) $task['attempts']) + 1;

		$this->plugin->logger->new_run_id('task-' . $task_id);
		$this->mark_status($task_id, 'running', array(
			'attempts'   => $attempt,
			'last_error' => '',
		));

		$payload = json_decode((string) $task['payload'], true);
		if (!is_array($payload)) {
			$payload = array();
		}

		try {
			$result = $this->dispatch($type, $payload, $task);
			if (is_wp_error($result)) {
				return $this->handle_task_error($task_id, $attempt, $result);
			}

			if (!is_array($result)) {
				$result = array('message' => (string) $result);
			}

			$status = sanitize_key($result['status'] ?? 'done');
			if (!in_array($status, array('done', 'needs_review', 'skipped'), true)) {
				$status = 'done';
			}

			$this->mark_status($task_id, $status, array(
				'result'     => wp_json_encode($result),
				'last_error' => '',
			));

			$this->plugin->logger->log('info', 'Agentná úloha dokončená', 'úlohy', array(
				'task_id' => $task_id,
				'type'    => $type,
				'status'  => $status,
				'result'  => $result,
			));

			return $result;
		} catch (Exception $e) {
			return $this->handle_task_error($task_id, $attempt, new WP_Error('komarena_pf_task_exception', $e->getMessage()));
		}
	}

	public function run_scheduled() {
		if (!$this->plugin->settings->get('agent_task_autopilot', 1)) {
			return array();
		}

		if (get_transient('komarena_pf_task_lock')) {
			$this->plugin->logger->log('info', 'Spracovanie úloh preskočené, zámok je aktívny', 'úlohy');
			return array();
		}

		set_transient('komarena_pf_task_lock', 1, 4 * MINUTE_IN_SECONDS);

		try {
			$limit = (int) $this->plugin->settings->get('agent_task_batch_size', 3);
			$results = $this->run_next($limit);
		} catch (Exception $e) {
			$this->plugin->logger->log('error', $e->getMessage(), 'tasks');
			$results = array();
		}

		delete_transient('komarena_pf_task_lock');
		return $results;
	}

	public function schedule_soon() {
		if (!wp_next_scheduled('komarena_pf_task_tick')) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'komarena_pf_every_five_minutes', 'komarena_pf_task_tick');
		}

		if (!wp_next_scheduled('komarena_pf_task_tick_once')) {
			wp_schedule_single_event(time() + 20, 'komarena_pf_task_tick_once');
		}
	}

	private function dispatch($type, $payload, $task) {
		$external = apply_filters('komarena_pf_task_dispatch_' . $type, null, $payload, $this->plugin, $this, $task);
		if (null !== $external) {
			return $external;
		}

		switch ($type) {
			case 'process_queue':
				$limit = isset($payload['limit']) ? absint($payload['limit']) : (int) $this->plugin->settings->get('queue_batch_size', 3);
				$results = $this->plugin->queue->run_next($limit);
				return array(
					'status'    => 'done',
					'message'   => 'Produktova fronta spracovana.',
					'processed' => is_array($results) ? count($results) : 0,
				);

			case 'supplier_import':
				$text = $this->supplier_payload_text($payload);
				if ('' === trim($text)) {
				return new WP_Error('komarena_pf_task_supplier_empty', 'Import zo zdrojových odkazov nemá žiadne odkazy.');
				}
				$result = $this->plugin->supplier->import_from_text($text);
				$result['status'] = !empty($result['failed']) ? 'needs_review' : 'done';
				return $result;

			case 'csv_builder_image_package':
				return $this->csv_builder_image_package($payload);

			case 'csv_builder_csv_package':
				return $this->csv_builder_csv_package($payload);

			case 'audit':
				$limit = isset($payload['limit']) ? absint($payload['limit']) : 100;
				$result = $this->plugin->audit->audit_products($limit);
				if (is_wp_error($result)) {
					return $result;
				}
				$result['status'] = !empty($result['with_issues']) ? 'needs_review' : 'done';
				return $result;

			case 'rebuild_product':
				$product_id = absint($payload['product_id'] ?? 0);
				if (!$product_id) {
					return new WP_Error('komarena_pf_task_product_missing', 'Chýba ID produktu pre prebudovanie.');
				}
				$result = $this->plugin->rebuild->rebuild($product_id, array(
					'overwrite_price' => !empty($payload['overwrite_price']),
					'force_images'    => !empty($payload['allow_image_update']) && !empty($payload['force_images']),
					'allow_title_update' => !empty($payload['allow_title_update']),
					'allow_image_update' => !empty($payload['allow_image_update']),
					'allow_price_update' => !empty($payload['allow_price_update']),
					'allow_category_update' => !empty($payload['allow_category_update']),
				));
				if (is_wp_error($result)) {
					return $result;
				}
				$result['status'] = !empty($result['ready_to_publish']) ? 'done' : 'needs_review';
				return $result;

			case 'review_product':
				return $this->review_product($payload);

			case 'cleanup_bad_product_images':
				if ('CLEANUP_BAD_IMAGES' !== (string) ($payload['confirm'] ?? '')) {
					return new WP_Error('komarena_pf_task_cleanup_confirm', 'Upratanie obrázkov vyžaduje potvrdenie confirm=CLEANUP_BAD_IMAGES.');
				}
				return $this->plugin->rebuild->cleanup_bad_product_images(array(
					'limit' => absint($payload['limit'] ?? 100),
					'product_ids' => (array) ($payload['product_ids'] ?? array()),
				));

			case 'find_product_images':
			case 'fetch_product_images':
			case 'qa_product_images':
			case 'standardize_product_images':
			case 'upload_product_images':
			case 'repair_product_images':
				$product_id = absint($payload['product_id'] ?? 0);
				if (!$product_id) {
					return new WP_Error('komarena_pf_task_product_missing', 'Chýba ID produktu pre obrázkovú úlohu.');
				}
				return $this->plugin->image_recovery->{$type}($product_id, $payload);

			case 'image_recovery_run':
				return $this->plugin->image_recovery->image_recovery_run(absint($payload['limit'] ?? 10), $payload);

			case 'generate_blog_draft':
				return $this->generate_blog_draft($payload);

			case 'homepage_sidebar_layout':
				return $this->plugin->layout->apply_homepage_sidebar_layout($payload);

			case 'homepage_sidebar_audit':
				return $this->plugin->layout->audit_homepage_sidebar_layout($payload);

			case 'homepage_sidebar_restore':
				if (empty($payload['admin_confirmed'])) {
					return new WP_Error('komarena_pf_layout_restore_confirmation', 'Obnova domovskej stránky zo zálohy vyžaduje výslovné potvrdenie administrátorom.');
				}
				return $this->plugin->layout->restore_homepage_sidebar_backup($payload);

			case 'plugin_inventory_audit':
				return $this->plugin->housekeeper->audit_installed_plugins($payload);

			case 'plugin_cleanup_plan':
				return $this->plugin->housekeeper->cleanup_plan($payload);

			case 'plugin_deactivate_selected':
				return $this->plugin->housekeeper->deactivate_selected($payload);

			case 'autonomous_supervisor':
				return $this->plugin->autonomy->run_cycle($payload);

			case 'legal_page_draft':
				$type = sanitize_key($payload['type'] ?? $payload['legal_page_type'] ?? 'cookies');
				return $this->plugin->legal_pages->create_draft($type, array(
					'overwrite' => !empty($payload['overwrite']),
				));

			case 'production_cycle':
				return $this->plugin->production->run_cycle(array(
					'limit' => absint($payload['limit'] ?? 20),
				));

			default:
				return $this->future_task($type, $payload);
		}
	}

	private function csv_builder_image_package($payload) {
		$name = sanitize_text_field($payload['product_name'] ?? $payload['name'] ?? '');
		if ('' === $name) {
			return new WP_Error('komarena_pf_task_builder_name_missing', 'Úloha obrázkového ZIP balíka nemá názov produktu.');
		}

		$result = $this->plugin->csv_builder->create_image_package($name, array(
			'source_urls' => (array) ($payload['source_urls'] ?? array()),
			'image_sources' => (array) ($payload['image_sources'] ?? array()),
			'image_mode'  => $payload['image_mode'] ?? '',
		));
		if (is_wp_error($result)) {
			return $result;
		}

		$result['status'] = 'needs_review';
		return $result;
	}

	private function csv_builder_csv_package($payload) {
		$name = sanitize_text_field($payload['product_name'] ?? $payload['name'] ?? '');
		$url = esc_url_raw($payload['one_image_url'] ?? $payload['image_url'] ?? '');
		if ('' === $name || '' === $url) {
			return new WP_Error('komarena_pf_task_builder_csv_missing', 'Úloha CSV balíka potrebuje názov produktu a odkaz jedného obrázka.');
		}

		return $this->plugin->csv_builder->create_csv_package($name, $url, array(
			'source_urls' => (array) ($payload['source_urls'] ?? array()),
			'sku'         => $payload['sku'] ?? '',
			'ean'         => $payload['ean'] ?? '',
		));
	}

	private function review_product($payload) {
		$product_id = absint($payload['product_id'] ?? 0);
		if (!$product_id) {
			return new WP_Error('komarena_pf_task_product_missing', 'Chýba ID produktu pre kontrolu kvality.');
		}

		$manifest = json_decode((string) get_post_meta($product_id, '_komarena_pf_manifest', true), true);
		if (!is_array($manifest)) {
			$manifest = array();
		}

		$qa = $this->plugin->qa->run($product_id, $manifest);
		$manifest['qa_status'] = $qa['status'];
		$manifest['ready_to_publish'] = $qa['ready_to_publish'];
		update_post_meta($product_id, '_komarena_pf_manifest', wp_json_encode($manifest));
		update_post_meta($product_id, '_komarena_pf_status', $qa['ready_to_publish'] ? 'ready_to_publish' : 'needs_review');
		update_post_meta($product_id, '_komarena_pf_ready_to_publish', $qa['ready_to_publish'] ? '1' : '0');

		return array(
			'status'           => $qa['ready_to_publish'] ? 'done' : 'needs_review',
			'product_id'       => $product_id,
			'ready_to_publish' => $qa['ready_to_publish'],
			'qa'               => $qa,
		);
	}

	private function generate_blog_draft($payload) {
		$title = sanitize_text_field($payload['title'] ?? $payload['topic'] ?? '');
		$product_id = absint($payload['product_id'] ?? 0);

		if (!$title && $product_id) {
			$title = get_the_title($product_id);
		}
		if (!$title) {
			return new WP_Error('komarena_pf_blog_title_missing', 'Chyba tema alebo nazov blogoveho draftu.');
		}

		$post_title = 'KomArena navod: ' . $title;
		$content = '<h2>Prehlad</h2><p>Tento draft pripravil KomArena agent ako redakcny zaklad. Pred publikovanim doplnit realne fotky, overene zdroje a finalnu kontrolu.</p>';
		$content .= '<h2>Co budeme stavat</h2><p>Popisat ciel projektu, komponenty a ocakavany vysledok.</p>';
		$content .= '<h2>Komponenty</h2><ul><li>Produkt alebo modul: ' . esc_html($title) . '</li></ul>';
		$content .= '<h2>Zapojenie a bezpecnost</h2><p>Technicke parametre a zapojenie overit podla datasheetu konkretnej verzie.</p>';
		$content .= '<h2>Home Assistant / ESPHome</h2><p>Doplnit konfiguraciu az po overeni realneho hardveru.</p>';

		$post_id = wp_insert_post(array(
			'post_type'    => 'post',
			'post_status'  => 'draft',
			'post_title'   => $post_title,
			'post_content' => wp_kses_post($content),
		), true);

		if (is_wp_error($post_id)) {
			return $post_id;
		}

		update_post_meta($post_id, '_komarena_pf_task_origin', 'generate_blog_draft');
		if ($product_id) {
			update_post_meta($post_id, '_komarena_pf_source_product_id', $product_id);
		}

		return array(
			'status'  => 'needs_review',
			'message' => 'Blogovy draft bol vytvoreny a caka na redakcnu kontrolu.',
			'post_id' => (int) $post_id,
		);
	}

	private function future_task($type, $payload) {
		return array(
			'status'  => 'needs_review',
			'message' => 'Typ úlohy je pripravený ako rozširujúci bod. Pripojte obsluhu cez filter komarena_pf_task_dispatch_' . sanitize_key($type) . '.',
			'type'    => sanitize_key($type),
			'payload' => $payload,
		);
	}

	private function handle_task_error($task_id, $attempt, $error) {
		$max_attempts = max(1, (int) $this->plugin->settings->get('agent_task_max_attempts', 3));
		$message = is_wp_error($error) ? $error->get_error_message() : 'Neznama chyba ulohy.';
		$status = $attempt >= $max_attempts ? 'failed' : 'waiting';
		$extra = array(
			'last_error' => $message,
			'result'     => wp_json_encode(array(
				'status'  => $status,
				'error'   => $message,
				'attempt' => $attempt,
			)),
		);

		if ('waiting' === $status) {
			$extra['scheduled_at'] = date('Y-m-d H:i:s', current_time('timestamp') + (5 * MINUTE_IN_SECONDS));
		}

		$this->mark_status($task_id, $status, $extra);
		$this->plugin->logger->log('error', 'Agentná úloha zlyhala: ' . $message, 'úlohy', array(
			'task_id'      => $task_id,
			'attempt'      => $attempt,
			'max_attempts' => $max_attempts,
			'next_status'  => $status,
		));

		return $error;
	}

	private function mark_status($task_id, $status, $extra = array()) {
		global $wpdb;

		if (!in_array($status, $this->statuses, true)) {
			$status = 'failed';
		}

		$table = $wpdb->prefix . 'komarena_pf_tasks';
		$fields = array_merge(array(
			'status'     => $status,
			'updated_at' => current_time('mysql'),
		), $extra);

		return $wpdb->update($table, $fields, array('id' => absint($task_id)));
	}

	private function supplier_payload_text($payload) {
		if (!empty($payload['text'])) {
			return (string) $payload['text'];
		}
		if (!empty($payload['urls']) && is_array($payload['urls'])) {
			return implode("\n", array_map('esc_url_raw', $payload['urls']));
		}
		if (!empty($payload['url'])) {
			return esc_url_raw($payload['url']);
		}

		return '';
	}
}
