<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Admin_UI {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;

		add_action('admin_menu', array($this, 'menu'));
		add_action('admin_enqueue_scripts', array($this, 'assets'));
		add_action('admin_notices', array($this, 'notices'));
		add_action('admin_post_komarena_pf_create_single', array($this, 'handle_create_single'));
		add_action('admin_post_komarena_pf_enqueue_bulk', array($this, 'handle_enqueue_bulk'));
		add_action('admin_post_komarena_pf_supplier_import', array($this, 'handle_supplier_import'));
		add_action('admin_post_komarena_pf_builder_images', array($this, 'handle_builder_images'));
		add_action('admin_post_komarena_pf_builder_csv', array($this, 'handle_builder_csv'));
		add_action('admin_post_komarena_pf_run_queue', array($this, 'handle_run_queue'));
		add_action('admin_post_komarena_pf_create_task', array($this, 'handle_create_task'));
		add_action('admin_post_komarena_pf_run_tasks', array($this, 'handle_run_tasks'));
		add_action('admin_post_komarena_pf_run_site_layout_task', array($this, 'handle_run_site_layout_task'));
		add_action('admin_post_komarena_pf_run_plugin_audit', array($this, 'handle_run_plugin_audit'));
		add_action('admin_post_komarena_pf_deactivate_plugins', array($this, 'handle_deactivate_plugins'));
		add_action('admin_post_komarena_pf_run_agent', array($this, 'handle_run_agent'));
		add_action('admin_post_komarena_pf_run_autonomy', array($this, 'handle_run_autonomy'));
		add_action('admin_post_komarena_pf_activate_production', array($this, 'handle_activate_production'));
		add_action('admin_post_komarena_pf_run_production', array($this, 'handle_run_production'));
		add_action('admin_post_komarena_pf_publish_ready_products', array($this, 'handle_publish_ready_products'));
		add_action('admin_post_komarena_pf_run_image_recovery', array($this, 'handle_run_image_recovery'));
		add_action('admin_post_komarena_pf_repair_product_images', array($this, 'handle_repair_product_images'));
		add_action('admin_post_komarena_pf_create_legal_draft', array($this, 'handle_create_legal_draft'));
		add_action('admin_post_komarena_pf_run_safe_repairs', array($this, 'handle_run_safe_repairs'));
		add_action('admin_post_komarena_pf_run_audit', array($this, 'handle_run_audit'));
		add_action('admin_post_komarena_pf_rebuild_product', array($this, 'handle_rebuild_product'));
		add_action('admin_post_komarena_pf_run_product_qa', array($this, 'handle_run_product_qa'));
		add_filter('post_row_actions', array($this, 'product_row_action'), 10, 2);
		add_filter('page_row_actions', array($this, 'product_row_action'), 10, 2);
		add_action('add_meta_boxes', array($this, 'meta_boxes'));
	}

	public function menu() {
		$cap = $this->plugin->capability();

		add_menu_page(
			__('KomArena produktový agent', 'komarena-product-factory'),
			__('KomArena produkty', 'komarena-product-factory'),
			$cap,
			'komarena-product-factory',
			array($this, 'render_dashboard'),
			'dashicons-products',
			56
		);

		add_submenu_page('komarena-product-factory', __('Tvorba jedného produktu', 'komarena-product-factory'), __('Jeden produkt', 'komarena-product-factory'), $cap, 'komarena-pf-single', array($this, 'render_single'));
		add_submenu_page('komarena-product-factory', __('Hromadná fronta produktov', 'komarena-product-factory'), __('Hromadná fronta', 'komarena-product-factory'), $cap, 'komarena-pf-queue', array($this, 'render_queue'));
		add_submenu_page('komarena-product-factory', __('Import zo zdrojových odkazov', 'komarena-product-factory'), __('Import zdrojov', 'komarena-product-factory'), $cap, 'komarena-pf-supplier', array($this, 'render_supplier_import'));
		add_submenu_page('komarena-product-factory', __('Tvorba WooCommerce CSV', 'komarena-product-factory'), __('CSV tvorca', 'komarena-product-factory'), $cap, 'komarena-pf-csv-builder', array($this, 'render_csv_builder'));
		add_submenu_page('komarena-product-factory', __('Kontrola produktov', 'komarena-product-factory'), __('Kontrola produktov', 'komarena-product-factory'), $cap, 'komarena-pf-review', array($this, 'render_review_center'));
		add_submenu_page('komarena-product-factory', __('Agent rozloženia webu', 'komarena-product-factory'), __('Rozloženie webu', 'komarena-product-factory'), $cap, 'komarena-pf-site-layout', array($this, 'render_site_layout'));
		add_submenu_page('komarena-product-factory', __('Upratovanie doplnkov', 'komarena-product-factory'), __('Audit doplnkov', 'komarena-product-factory'), $cap, 'komarena-pf-plugin-housekeeper', array($this, 'render_plugin_housekeeper'));
		add_submenu_page('komarena-product-factory', __('Úlohy agenta', 'komarena-product-factory'), __('Úlohy agenta', 'komarena-product-factory'), $cap, 'komarena-pf-tasks', array($this, 'render_tasks'));
		add_submenu_page('komarena-product-factory', __('Autopilot agenta', 'komarena-product-factory'), __('Agent', 'komarena-product-factory'), $cap, 'komarena-pf-agent', array($this, 'render_agent'));
		add_submenu_page('komarena-product-factory', __('Audit produktov', 'komarena-product-factory'), __('Audit', 'komarena-product-factory'), $cap, 'komarena-pf-audit', array($this, 'render_audit'));
		add_submenu_page('komarena-product-factory', __('Logy agenta', 'komarena-product-factory'), __('Logy', 'komarena-product-factory'), $cap, 'komarena-pf-logs', array($this, 'render_logs'));
		add_submenu_page('komarena-product-factory', __('Nastavenia', 'komarena-product-factory'), __('Nastavenia', 'komarena-product-factory'), $cap, 'komarena-pf-settings', array($this, 'render_settings'));
	}

	public function assets($hook) {
		if (false === strpos($hook, 'komarena')) {
			return;
		}

		wp_enqueue_style('komarena-pf-admin', KOMARENA_PF_URL . 'assets/admin.css', array(), KOMARENA_PF_VERSION);
	}

	public function notices() {
		if (!current_user_can($this->plugin->capability())) {
			return;
		}

		if (!empty($_GET['kpf_message'])) {
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(wp_unslash($_GET['kpf_message'])));
		}
		if (!empty($_GET['kpf_error'])) {
			printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html(wp_unslash($_GET['kpf_error'])));
		}
	}

	public function render_dashboard() {
		$this->guard();
		$queue_counts = $this->plugin->queue->counts();
		$task_counts = $this->plugin->tasks->counts();
		$audit_counts = $this->plugin->audit->dashboard_counts();
		$plugin_audit = get_option('komarena_pf_last_plugin_audit', array());
		$plugin_summary = is_array($plugin_audit) && !empty($plugin_audit['summary']) ? $plugin_audit['summary'] : array();
		$logs = $this->plugin->logger->recent(8);

		$this->header('KomArena produktový agent v' . KOMARENA_PF_VERSION);
		$this->woocommerce_warning();
		echo '<div class="kpf-grid">';
		$this->metric('Produkty vo fronte', array_sum(array_intersect_key($queue_counts, array_flip(array('waiting', 'researching', 'images', 'content', 'uploading', 'creating_product', 'qa')))));
		$this->metric('Automatický agent', $this->plugin->settings->get('agent_autopilot', 1) ? 1 : 0);
		$this->metric('Pripravené produkty', $queue_counts['ready'] ?? 0);
		$this->metric('Na kontrolu', $queue_counts['needs_review'] ?? 0);
		$this->metric('Zlyhané', $queue_counts['failed'] ?? 0);
		$this->metric('Úlohy agenta', (int) ($task_counts['waiting'] ?? 0) + (int) ($task_counts['running'] ?? 0));
		$this->metric('Zlyhané úlohy', $task_counts['failed'] ?? 0);
		$this->metric('Riziká doplnkov', (int) ($plugin_summary['high_risk'] ?? 0) + (int) ($plugin_summary['conflicts'] ?? 0));
		$this->metric('Produkty bez SEO', $audit_counts['products_without_seo']);
		$this->metric('Bez 4 obrázkov', $audit_counts['products_without_4_images']);
		$this->metric('Starý dizajn', $audit_counts['products_with_old_design']);
		echo '</div>';

		$this->production_panel();
		$this->image_recovery_panel();
		$this->autonomy_panel();
		$this->legal_pages_panel();
		$this->safe_repairs_panel();

		echo '<div class="kpf-panel"><h2>Posledné behy agenta</h2>';
		$this->logs_table($logs);
		echo '</div>';
		$this->agent_summary_panel();
		$this->footer();
	}

	public function render_single() {
		$this->guard();
		$this->header('Tvorba jedného produktu');
		$this->woocommerce_warning();
		?>
		<div class="kpf-panel">
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="komarena_pf_create_single" />
				<?php wp_nonce_field('komarena_pf_create_single'); ?>
				<label for="kpf-product-name"><strong>Názov produktu</strong></label>
				<input id="kpf-product-name" class="regular-text kpf-wide" type="text" name="product_name" placeholder="napr. ESP vývojová doska USB-C alebo konkrétne ESP32 DevKit" required />
				<label for="kpf-source-urls"><strong>Overené zdroje</strong> <span class="description">voliteľné, jeden URL na riadok</span></label>
				<textarea id="kpf-source-urls" name="source_urls" rows="5" class="large-text" placeholder="výrobca, technický list, distribútor alebo interná referencia KomArena"></textarea>
				<label for="kpf-image-urls"><strong>Reálne odkazy obrázkov</strong> <span class="description">voliteľné, jeden obrázok na riadok; ak chýbajú 4 originálne/reálne produktové obrázky, produkt zostane na ručnú kontrolu</span></label>
				<textarea id="kpf-image-urls" name="image_urls" rows="5" class="large-text" placeholder="https://.../produkt-main.jpg&#10;https://.../produkt-angle.jpg"></textarea>
				<p><button class="button button-primary button-hero">Vytvoriť WooCommerce produkt</button></p>
			</form>
		</div>
		<?php
		$this->footer();
	}

	public function render_queue() {
		$this->guard();
		$items = $this->plugin->queue->items(80);
		$counts = $this->plugin->queue->counts();

		$this->header('Hromadná fronta produktov');
		$this->woocommerce_warning();
		?>
		<div class="kpf-two">
			<div class="kpf-panel">
				<h2>Vložiť produkty do fronty</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_enqueue_bulk" />
					<?php wp_nonce_field('komarena_pf_enqueue_bulk'); ?>
					<textarea name="bulk_products" rows="12" class="large-text" placeholder="Každý produkt na nový riadok. Voliteľne: Názov produktu | https://technicky-list... | https://produktova-fotka.jpg"></textarea>
					<p><button class="button button-primary">Pridať do fronty</button></p>
				</form>
			</div>
			<div class="kpf-panel">
				<h2>Spracovanie</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_run_queue" />
					<?php wp_nonce_field('komarena_pf_run_queue'); ?>
					<label>Počet úloh</label>
					<input type="number" min="1" max="25" name="limit" value="<?php echo esc_attr($this->plugin->settings->get('queue_batch_size', 3)); ?>" />
					<p><button class="button button-primary">Spustiť ďalšiu dávku</button></p>
				</form>
				<div class="kpf-status-list">
					<?php foreach ($counts as $status => $count) : ?>
						<span><strong><?php echo esc_html($this->visible_status($status)); ?></strong> <?php echo esc_html($count); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<div class="kpf-panel">
			<h2>Fronta</h2>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>Produkt</th><th>Stav</th><th>Produkt vo WooCommerce</th><th>Pokusy</th><th>Aktualizované</th><th>Chyba</th></tr></thead>
				<tbody>
				<?php foreach ($items as $item) : ?>
					<tr>
						<td><?php echo esc_html($item['id']); ?></td>
						<td><?php echo esc_html($item['product_name']); ?></td>
						<td><span class="kpf-badge"><?php echo esc_html($this->visible_status($item['status'])); ?></span></td>
						<td><?php echo $item['product_id'] ? '<a href="' . esc_url(get_edit_post_link($item['product_id'])) . '">#' . esc_html($item['product_id']) . '</a>' : ''; ?></td>
						<td><?php echo esc_html($item['attempts']); ?></td>
						<td><?php echo esc_html($item['updated_at']); ?></td>
						<td><?php echo esc_html($item['last_error']); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		$this->footer();
	}

	public function render_supplier_import() {
		$this->guard();
		$this->header('Import zo zdrojových odkazov');
		$this->woocommerce_warning();
		?>
		<div class="kpf-panel">
			<h2>Import z dodávateľských odkazov</h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="komarena_pf_supplier_import" />
				<?php wp_nonce_field('komarena_pf_supplier_import'); ?>
				<p>Vložte odkazy produktov. Voliteľný formát: <code>Názov produktu | https://dodavatel.sk/produkt</code>.</p>
				<textarea name="supplier_urls" rows="12" class="large-text" placeholder="https://dodavatel.sk/produkt-1&#10;ESP vývojová doska | https://dodavatel.sk/esp-modul"></textarea>
				<p><button class="button button-primary button-hero">Nech to spracuje agent</button></p>
			</form>
		</div>
		<div class="kpf-panel">
			<h2>Čo agent vytiahne</h2>
			<ul class="kpf-missing">
				<li>názov, meta popis a štruktúrované produktové dáta, ak sú dostupné</li>
				<li>cenu, SKU, EAN/GTIN a obrázky zo štruktúrovaných dát</li>
				<li>zdrojový odkaz do manifestu a upozornení kontroly kvality</li>
				<li>frontu úloh, ktorú následne spracuje autopilot</li>
			</ul>
		</div>
		<?php
		$this->footer();
	}

	public function render_csv_builder() {
		$this->guard();
		$image_result = get_option('komarena_pf_last_builder_image_package', array());
		$csv_result = get_option('komarena_pf_last_builder_csv_package', array());
		$standard = $this->plugin->csv_builder->standard();

		if (!is_array($image_result)) {
			$image_result = array();
		}
		if (!is_array($csv_result)) {
			$csv_result = array();
		}

		$this->header('KomArena CSV tvorca produktov');
		?>
		<div class="kpf-two">
			<div class="kpf-panel">
				<h2>1. Vytvoriť ZIP so 4 obrázkami</h2>
				<p>Agent najprv pripraví iba obrázkový ZIP: hlavný pohľad, pohľad z uhla, detail a technický pohľad. CSV sa generuje až po nahratí obrázkov do knižnice médií a vložení jedného odkazu.</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_builder_images" />
					<?php wp_nonce_field('komarena_pf_builder_images'); ?>
					<label>Názov produktu</label>
					<input class="regular-text kpf-wide" type="text" name="product_name" placeholder="napr. Arduino Mega 2560 R3 klon" required />
					<label>Overené zdroje <span class="description">voliteľné, jeden odkaz na riadok</span></label>
					<textarea class="large-text" rows="5" name="source_urls" placeholder="výrobca, technický list, distribútor alebo interná referencia KomArena"></textarea>
					<label>Reálne odkazy obrázkov <span class="description">voliteľné, ideálne 4 odkazy: hlavný, uhol, detail, technický</span></label>
					<textarea class="large-text" rows="5" name="image_urls" placeholder="https://.../produkt-main.jpg&#10;https://.../produkt-angle.jpg&#10;https://.../produkt-detail.jpg&#10;https://.../produkt-technical.jpg"></textarea>
					<p><button class="button button-primary button-hero">Vytvoriť obrázkový ZIP</button></p>
				</form>
			</div>
			<div class="kpf-panel">
				<h2>2. Vytvoriť finálny WooCommerce CSV</h2>
				<p>Po nahratí obrázkov do knižnice médií vložte odkaz jedného obrázka. Agent z neho odvodí všetky 4 finálne odkazy.</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_builder_csv" />
					<?php wp_nonce_field('komarena_pf_builder_csv'); ?>
					<label>Názov produktu</label>
					<input class="regular-text kpf-wide" type="text" name="product_name" value="<?php echo esc_attr($image_result['product_name'] ?? ''); ?>" required />
					<label>Odkaz jedného nahratého obrázka</label>
					<input class="regular-text kpf-wide" type="url" name="one_image_url" placeholder="https://komarena.sk/wp-content/uploads/2026/05/komarena-produkt-angle.jpg" required />
					<label>Overené zdroje <span class="description">voliteľné, jeden odkaz na riadok</span></label>
					<textarea class="large-text" rows="5" name="source_urls"><?php echo esc_textarea(implode("\n", (array) ($image_result['source_urls'] ?? array()))); ?></textarea>
					<p><button class="button button-primary button-hero">Vytvoriť CSV + manifest ZIP</button></p>
				</form>
			</div>
		</div>
		<div class="kpf-two">
			<div class="kpf-panel">
				<h2>Posledný obrázkový ZIP</h2>
				<?php $this->builder_image_result($image_result); ?>
			</div>
			<div class="kpf-panel">
				<h2>Posledný CSV balík</h2>
				<?php $this->builder_csv_result($csv_result); ?>
			</div>
		</div>
		<div class="kpf-panel">
			<h2>Záväzný štandard tvorby produktov</h2>
			<p><strong>Verzia:</strong> <?php echo esc_html($standard['version'] ?? ''); ?></p>
			<ul class="kpf-missing">
				<?php foreach ((array) ($standard['workflow'] ?? array()) as $rule) : ?>
					<li><?php echo esc_html($rule); ?></li>
				<?php endforeach; ?>
			</ul>
			<p><strong>CSV stĺpce:</strong> <code><?php echo esc_html(implode(', ', (array) ($standard['csv_columns'] ?? array()))); ?></code></p>
		</div>
		<?php
		$this->footer();
	}

	public function render_review_center() {
		$this->guard();
		$this->header('Kontrola produktov');
		$this->woocommerce_warning();
		echo '<div class="kpf-panel"><h2>Produkty, ktoré potrebujú rozhodnutie</h2>';
		$this->review_products_table();
		echo '</div>';
		echo '<div class="kpf-panel"><h2>Zlyhaná fronta</h2>';
		$this->failed_queue_table();
		echo '</div>';
		echo '<div class="kpf-panel"><h2>Pamäť agenta</h2>';
		$this->memory_table();
		echo '</div>';
		$this->footer();
	}

	public function render_site_layout() {
		$this->guard();
		$page_id = $this->plugin->layout->default_homepage_id();
		$audit = array();
		if ($page_id) {
			$audit = json_decode((string) get_post_meta($page_id, '_komarena_pf_homepage_sidebar_audit', true), true);
			if (!is_array($audit) || empty($audit)) {
				$audit = $this->plugin->layout->audit_homepage_sidebar_layout(array('page_id' => $page_id));
			}
		}

		$this->header('Agent rozloženia webu');
		?>
		<div class="kpf-two">
			<div class="kpf-panel">
				<h2>Rozloženie bočného panelu na domovskej stránke</h2>
				<p>Agent vie vložiť skrátený kód <code>[komarena_sidebar_panel]</code> do pravého bočného priestoru a zachovať hlavný obsah domovskej stránky v ľavom stĺpci.</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_run_site_layout_task" />
					<input type="hidden" name="layout_task" value="homepage_sidebar_layout" />
					<?php wp_nonce_field('komarena_pf_run_site_layout_task'); ?>
					<label>ID domovskej stránky</label>
					<input type="number" min="1" name="page_id" value="<?php echo esc_attr($page_id); ?>" />
					<p><button class="button button-primary button-hero">Nech agent upraví domovskú stránku</button></p>
				</form>
			</div>
			<div class="kpf-panel">
				<h2>Kontrola a obnova</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_run_site_layout_task" />
					<input type="hidden" name="layout_task" value="homepage_sidebar_audit" />
					<?php wp_nonce_field('komarena_pf_run_site_layout_task'); ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>" />
					<p><button class="button">Skontrolovať rozloženie</button></p>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_run_site_layout_task" />
					<input type="hidden" name="layout_task" value="homepage_sidebar_restore" />
					<?php wp_nonce_field('komarena_pf_run_site_layout_task'); ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr($page_id); ?>" />
					<label>Potvrdenie obnovy</label>
					<input type="text" name="confirm_restore" placeholder="OBNOVIT" />
					<p><button class="button">Obnoviť zo zálohy</button></p>
				</form>
			</div>
		</div>
		<div class="kpf-panel">
			<h2>Aktuálny stav</h2>
			<?php $this->site_layout_audit_table($audit, $page_id); ?>
		</div>
		<?php
		$this->footer();
	}

	public function render_plugin_housekeeper() {
		$this->guard();
		$report = get_option('komarena_pf_last_plugin_audit', array());
		if (!is_array($report)) {
			$report = array();
		}
		$summary = $report['summary'] ?? array();

		$this->header('Upratovanie doplnkov');
		?>
		<div class="kpf-two">
			<div class="kpf-panel">
				<h2>Audit doplnkov</h2>
				<p>Agent skontroluje aktívne, neaktívne, duplicitné a potenciálne konfliktné doplnky. Automatické mazanie je zámerne vypnuté.</p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_run_plugin_audit" />
					<?php wp_nonce_field('komarena_pf_run_plugin_audit'); ?>
					<p><button class="button button-primary button-hero">Skontrolovať doplnky</button></p>
				</form>
			</div>
			<div class="kpf-panel">
				<h2>Suhrn</h2>
				<div class="kpf-status-list">
					<span><strong>spolu</strong> <?php echo esc_html($summary['total'] ?? 0); ?></span>
					<span><strong>aktívne</strong> <?php echo esc_html($summary['active'] ?? 0); ?></span>
					<span><strong>neaktívne</strong> <?php echo esc_html($summary['inactive'] ?? 0); ?></span>
					<span><strong>kandidáti</strong> <?php echo esc_html($summary['cleanup_candidates'] ?? 0); ?></span>
					<span><strong>riziká</strong> <?php echo esc_html($summary['high_risk'] ?? 0); ?></span>
				</div>
			</div>
		</div>
		<div class="kpf-panel">
			<h2>Bezpečná deaktivácia</h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="komarena_pf_deactivate_plugins" />
				<?php wp_nonce_field('komarena_pf_deactivate_plugins'); ?>
				<?php $this->plugin_audit_table($report, true); ?>
				<label>Potvrdenie</label>
				<input type="text" name="confirm_deactivate" placeholder="DEAKTIVOVAT" />
				<p><button class="button">Deaktivovať vybrané doplnky</button></p>
			</form>
		</div>
		<?php
		$this->footer();
	}

	public function render_tasks() {
		$this->guard();
		$counts = $this->plugin->tasks->counts();
		$items = $this->plugin->tasks->items(100);
		$types = $this->plugin->tasks->registered_task_types();

		$this->header('Úlohy agenta');
		$this->woocommerce_warning();
		echo '<div class="kpf-grid">';
		foreach ($counts as $status => $count) {
			$this->metric($this->visible_status($status), $count);
		}
		echo '</div>';
		?>
		<div class="kpf-two">
			<div class="kpf-panel">
				<h2>Rýchle úlohy</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_create_task" />
					<input type="hidden" name="task_type" value="process_queue" />
					<input type="hidden" name="task_title" value="Spracovať produktovú frontu" />
					<?php wp_nonce_field('komarena_pf_create_task'); ?>
					<label>Limit fronty</label>
					<input type="number" min="1" max="25" name="task_limit" value="<?php echo esc_attr($this->plugin->settings->get('queue_batch_size', 3)); ?>" />
					<p><button class="button button-primary">Naplánovať spracovanie fronty</button></p>
				</form>
				<hr />
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_create_task" />
					<input type="hidden" name="task_type" value="audit" />
					<input type="hidden" name="task_title" value="Audit existujúcich produktov" />
					<?php wp_nonce_field('komarena_pf_create_task'); ?>
					<label>Limit auditu</label>
					<input type="number" min="1" max="500" name="task_limit" value="100" />
					<p><button class="button">Naplánovať audit</button></p>
				</form>
			</div>
			<div class="kpf-panel">
				<h2>Spustenie spracovania úloh</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_run_tasks" />
					<?php wp_nonce_field('komarena_pf_run_tasks'); ?>
					<label>Počet úloh</label>
					<input type="number" min="1" max="25" name="limit" value="<?php echo esc_attr($this->plugin->settings->get('agent_task_batch_size', 3)); ?>" />
					<p><button class="button button-primary button-hero">Spustiť úlohy teraz</button></p>
				</form>
				<p><strong>Najbližšie:</strong> <?php echo esc_html($this->next_cron_label('komarena_pf_task_tick')); ?></p>
			</div>
		</div>
		<div class="kpf-panel">
			<h2>Import zo zdrojových odkazov ako úloha</h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="komarena_pf_create_task" />
				<input type="hidden" name="task_type" value="supplier_import" />
				<input type="hidden" name="task_title" value="Import zo zdrojových odkazov" />
				<?php wp_nonce_field('komarena_pf_create_task'); ?>
				<textarea name="supplier_urls" rows="6" class="large-text" placeholder="https://dodavatel.sk/produkt&#10;Názov produktu | https://dodavatel.sk/produkt"></textarea>
				<p><button class="button">Naplánovať import zdrojov</button></p>
			</form>
		</div>
		<div class="kpf-panel">
			<h2>CSV tvorca produktu ako úloha</h2>
			<div class="kpf-two">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_create_task" />
					<input type="hidden" name="task_type" value="csv_builder_image_package" />
					<input type="hidden" name="task_title" value="CSV tvorca - obrázkový ZIP" />
					<?php wp_nonce_field('komarena_pf_create_task'); ?>
					<label>Názov produktu</label>
					<input class="regular-text" type="text" name="product_name" placeholder="Arduino Mega 2560 R3 klon" />
					<label>Overené zdroje</label>
					<textarea name="source_urls" rows="4" class="large-text"></textarea>
					<label>Reálne odkazy obrázkov</label>
					<textarea name="image_urls" rows="4" class="large-text"></textarea>
					<p><button class="button">Naplánovať obrázkový ZIP</button></p>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_create_task" />
					<input type="hidden" name="task_type" value="csv_builder_csv_package" />
					<input type="hidden" name="task_title" value="CSV tvorca - WooCommerce import" />
					<?php wp_nonce_field('komarena_pf_create_task'); ?>
					<label>Názov produktu</label>
					<input class="regular-text" type="text" name="product_name" placeholder="Arduino Mega 2560 R3 klon" />
					<label>Odkaz jedného obrázka</label>
					<input class="regular-text" type="url" name="one_image_url" placeholder="https://komarena.sk/wp-content/uploads/..." />
					<p><button class="button">Naplánovať CSV balík</button></p>
				</form>
			</div>
		</div>
		<div class="kpf-panel">
			<h2>Web a doplnky ako úloha</h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpf-inline-form">
				<input type="hidden" name="action" value="komarena_pf_create_task" />
				<input type="hidden" name="task_type" value="homepage_sidebar_layout" />
				<input type="hidden" name="task_title" value="Rozloženie bočného panelu domovskej stránky" />
				<?php wp_nonce_field('komarena_pf_create_task'); ?>
				<button class="button">Naplánovať bočný panel domovskej stránky</button>
			</form>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpf-inline-form">
				<input type="hidden" name="action" value="komarena_pf_create_task" />
				<input type="hidden" name="task_type" value="plugin_inventory_audit" />
				<input type="hidden" name="task_title" value="Audit zoznamu doplnkov" />
				<?php wp_nonce_field('komarena_pf_create_task'); ?>
				<button class="button">Naplánovať audit doplnkov</button>
			</form>
		</div>
		<div class="kpf-panel">
			<h2>Vlastná agentná úloha</h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="komarena_pf_create_task" />
				<?php wp_nonce_field('komarena_pf_create_task'); ?>
				<label>Typ úlohy</label>
				<select name="task_type">
					<?php foreach ($types as $type => $definition) : ?>
						<option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($definition['label']); ?></option>
					<?php endforeach; ?>
				</select>
				<label>Názov</label>
				<input class="regular-text" type="text" name="task_title" placeholder="Voliteľný názov úlohy" />
				<label>Dáta úlohy vo formáte JSON</label>
				<textarea name="payload_json" rows="6" class="large-text" placeholder='{"product_id":123,"limit":10}'></textarea>
				<label>Priorita</label>
				<input type="number" min="1" max="100" name="priority" value="10" />
				<p><button class="button">Vytvoriť vlastnú úlohu</button></p>
			</form>
		</div>
		<div class="kpf-panel">
			<h2>Typy úloh a rozširujúci bod</h2>
			<?php $this->task_types_table($types); ?>
		</div>
		<div class="kpf-panel">
			<h2>Posledné úlohy</h2>
			<?php $this->tasks_table($items); ?>
		</div>
		<?php
		$this->footer();
	}

	public function render_audit() {
		$this->guard();
		$this->header('Audit existujúcich produktov');
		$this->woocommerce_warning();
		?>
		<div class="kpf-panel">
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="komarena_pf_run_audit" />
				<?php wp_nonce_field('komarena_pf_run_audit'); ?>
				<label>Limit produktov</label>
				<input type="number" min="1" max="500" name="limit" value="100" />
				<button class="button button-primary">Spustiť audit</button>
			</form>
		</div>
		<div class="kpf-panel">
			<h2>Produkty s poslednými audit problémami</h2>
			<?php $this->audit_table(); ?>
		</div>
		<?php $this->safe_repairs_panel(); ?>
		<?php
		$this->footer();
	}

	public function render_agent() {
		$this->guard();
		$preflight = $this->plugin->agent->preflight();
		$summary = get_option('komarena_pf_last_agent_summary', array());
		if (!is_array($summary)) {
			$summary = array();
		}

		$this->header('Autopilot agenta');
		$this->woocommerce_warning();
		?>
		<div class="kpf-two">
			<div class="kpf-panel">
				<h2>Spustiť agenta</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="komarena_pf_run_agent" />
					<?php wp_nonce_field('komarena_pf_run_agent'); ?>
					<p>Agent vykoná kontrolu celého webu: doplní základné overené zdroje, spraví audit doplnkov a plán upratania, skontroluje alebo upraví rozloženie bočného panelu na domovskej stránke, spustí produktový audit, spracuje frontu a bezpečne opraví iba povolené vlastné KomArena produkty v stave na kontrolu.</p>
					<p><button class="button button-primary button-hero">Spustiť agenta teraz</button></p>
				</form>
			</div>
			<div class="kpf-panel">
				<h2>Najbližšie spustenie</h2>
				<p><strong>Fronta:</strong> <?php echo esc_html($this->next_cron_label('komarena_pf_process_queue')); ?></p>
				<p><strong>Agent:</strong> <?php echo esc_html($this->next_cron_label('komarena_pf_agent_tick')); ?></p>
				<p><strong>Úlohy:</strong> <?php echo esc_html($this->next_cron_label('komarena_pf_task_tick')); ?></p>
			</div>
		</div>
		<div class="kpf-panel">
			<h2>Predbežná kontrola</h2>
			<table class="widefat striped"><thead><tr><th>Kontrola</th><th>Stav</th><th>Správa</th></tr></thead><tbody>
			<?php foreach ($preflight as $key => $check) : ?>
				<tr>
					<td><?php echo esc_html($key); ?></td>
					<td><span class="kpf-badge"><?php echo !empty($check['pass']) ? 'OK' : 'Pozor'; ?></span></td>
					<td><?php echo esc_html($check['message']); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
		$this->health_panel();
		$this->production_panel();
		$this->autonomy_panel();
		$this->legal_pages_panel();
		$this->agent_summary_panel($summary);
		$this->footer();
	}

	public function render_logs() {
		$this->guard();
		$this->header('Logy agenta');
		echo '<div class="kpf-panel">';
		$this->logs_table($this->plugin->logger->recent(100));
		echo '</div>';
		$this->footer();
	}

	public function render_settings() {
		$this->guard();
		$settings = $this->plugin->settings->all();
		$this->header('Nastavenia');
		?>
		<div class="kpf-panel">
			<form method="post" action="options.php">
				<?php settings_fields('komarena_pf_settings'); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label>Predvolený sklad</label></th><td><input type="number" min="0" name="komarena_pf_settings[default_stock]" value="<?php echo esc_attr($settings['default_stock']); ?>" /></td></tr>
					<tr><th scope="row"><label>Marža / prirážka %</label></th><td><input type="number" min="0" max="500" name="komarena_pf_settings[price_markup_percent]" value="<?php echo esc_attr($settings['price_markup_percent']); ?>" /></td></tr>
					<tr><th scope="row"><label>Minimálna istota</label></th><td><input type="number" min="0" max="1" step="0.01" name="komarena_pf_settings[minimum_confidence]" value="<?php echo esc_attr($settings['minimum_confidence']); ?>" /></td></tr>
					<tr><th scope="row"><label>Veľkosť dávky fronty</label></th><td><input type="number" min="1" max="25" name="komarena_pf_settings[queue_batch_size]" value="<?php echo esc_attr($settings['queue_batch_size']); ?>" /></td></tr>
					<tr><th scope="row">Ostrá prevádzka</th><td><label><input type="checkbox" name="komarena_pf_settings[production_mode]" value="1" <?php checked($settings['production_mode']); ?> /> Produkčný režim je zapnutý. Agent smie vykonávať ostré opravy a publikovať iba produkty, ktoré prejdú úplnou kontrolou kvality, overenými zdrojmi a reálnymi obrázkami.</label><input type="hidden" name="komarena_pf_settings[production_activated_at]" value="<?php echo esc_attr($settings['production_activated_at']); ?>" /><input type="hidden" name="komarena_pf_settings[production_note]" value="<?php echo esc_attr($settings['production_note']); ?>" /></td></tr>
					<tr><th scope="row">Spracovanie úloh agenta</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_task_autopilot]" value="1" <?php checked($settings['agent_task_autopilot']); ?> /> Automaticky spracovať generické agentné úlohy cez WP-Cron.</label></td></tr>
					<tr><th scope="row">Obrázková obnova</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_auto_image_recovery]" value="1" <?php checked($settings['agent_auto_image_recovery']); ?> /> V produkčnom behu automaticky hľadať, overovať, nahrávať a priraďovať iba originálne alebo reálne produktové obrázky, ktoré prejdú presnou zhodou a právami použitia.</label></td></tr>
					<tr><th scope="row"><label>Veľkosť dávky úloh</label></th><td><input type="number" min="1" max="25" name="komarena_pf_settings[agent_task_batch_size]" value="<?php echo esc_attr($settings['agent_task_batch_size']); ?>" /></td></tr>
					<tr><th scope="row"><label>Maximum pokusov úlohy</label></th><td><input type="number" min="1" max="10" name="komarena_pf_settings[agent_task_max_attempts]" value="<?php echo esc_attr($settings['agent_task_max_attempts']); ?>" /></td></tr>
					<tr><th scope="row">Autopilot celého webu</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_full_site_autopilot]" value="1" <?php checked($settings['agent_full_site_autopilot']); ?> /> Tlačidlo Spustiť agenta teraz má skontrolovať celý webový zoznam kontrol.</label></td></tr>
					<tr><th scope="row">Auto zdroje</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_auto_seed_sources]" value="1" <?php checked($settings['agent_auto_seed_sources']); ?> /> Ak chýbajú overené zdroje, agent doplní základné KomArena referencie.</label></td></tr>
					<tr><th scope="row">Automatický audit doplnkov</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_auto_plugin_audit]" value="1" <?php checked($settings['agent_auto_plugin_audit']); ?> /> Agent pri behu skontroluje doplnky a pripraví plán upratania.</label></td></tr>
					<tr><th scope="row">Automatické rozloženie domovskej stránky</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_auto_homepage_layout]" value="1" <?php checked($settings['agent_auto_homepage_layout']); ?> /> Agent pri behu skontroluje alebo doplní rozloženie bočného panelu na domovskej stránke.</label></td></tr>
					<tr><th scope="row">Automatický plán upratania</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_auto_cleanup_plan]" value="1" <?php checked($settings['agent_auto_cleanup_plan']); ?> /> Agent pripraví plán vypnutia rizikových doplnkov bez automatickej deaktivácie.</label></td></tr>
					<tr><th scope="row">Audit pri manuálnom behu</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_audit_on_manual]" value="1" <?php checked($settings['agent_audit_on_manual']); ?> /> Manuálne spustenie agenta vždy spustí audit produktov.</label></td></tr>
					<tr><th scope="row"><label>Limit úplného auditu</label></th><td><input type="number" min="1" max="500" name="komarena_pf_settings[agent_full_audit_limit]" value="<?php echo esc_attr($settings['agent_full_audit_limit']); ?>" /></td></tr>
					<tr><th scope="row">Auto oprava auditu</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_auto_repair_audit]" value="1" <?php checked($settings['agent_auto_repair_audit']); ?> /> Agent po audite automaticky opraví bezpečnú dávku produktov.</label></td></tr>
					<tr><th scope="row"><label>Limit opráv po audite</label></th><td><input type="number" min="0" max="25" name="komarena_pf_settings[agent_audit_repair_limit]" value="<?php echo esc_attr($settings['agent_audit_repair_limit']); ?>" /></td></tr>
					<tr><th scope="row">Opravovať publikované produkty</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_repair_live_products]" value="1" <?php checked($settings['agent_repair_live_products']); ?> /> Povoliť agentovi automaticky upravovať aj živé publikované produkty. Predvolene vypnuté.</label></td></tr>
					<tr><th scope="row">Prísna verifikácia</th><td><label><input type="checkbox" name="komarena_pf_settings[strict_source_verification]" value="1" <?php checked($settings['strict_source_verification']); ?> /> Nepustiť produkt medzi pripravené produkty, ak chýbajú overené zdroje.</label></td></tr>
					<tr><th scope="row">100 % overené fakty</th><td><label><input type="checkbox" name="komarena_pf_settings[require_verified_product_facts]" value="1" <?php checked($settings['require_verified_product_facts']); ?> /> Technické tvrdenia v popise musia byť naviazané na overený zdroj. Pri klonoch je povinný aj reálny klon alebo listing overeného distribútora; inak produkt zostane na ručnú kontrolu.</label></td></tr>
					<tr><th scope="row">Reálne obrázky povinné</th><td><label><input type="checkbox" name="komarena_pf_settings[require_real_product_images]" value="1" <?php checked($settings['require_real_product_images']); ?> /> Ak je zapnuté, produkt môže byť pripravený na publikovanie iba s reálnymi nahranými alebo zachovanými produktovými obrázkami.</label></td></tr>
					<tr><th scope="row">Kreslená záloha</th><td><label><input type="checkbox" name="komarena_pf_settings[allow_illustrated_image_fallback]" value="1" <?php checked($settings['allow_illustrated_image_fallback']); ?> /> Iba pracovný kontrolný náhľad. Nikdy nestačí na pripravenie na publikovanie a nenahrádza originálne/reálne produktové fotografie.</label></td></tr>
					<tr><th scope="row">Dočasné kontrolné obrázky</th><td><label><input type="checkbox" name="komarena_pf_settings[allow_placeholder_images]" value="1" <?php checked($settings['allow_placeholder_images']); ?> /> Núdzovo vytvoriť iba dočasné kontrolné obrázky. Nikdy nestačia na pripravenie na publikovanie.</label></td></tr>
					<tr><th scope="row">Automatický agent</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_autopilot]" value="1" <?php checked($settings['agent_autopilot']); ?> /> Automaticky spracovať frontu cez WP-Cron po vložení produktov.</label></td></tr>
					<tr><th scope="row">Opakovať zlyhané úlohy</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_retry_failed]" value="1" <?php checked($settings['agent_retry_failed']); ?> /> Agent sám vráti do fronty zlyhané úlohy, ktoré ešte nemajú 3 pokusy.</label></td></tr>
					<tr><th scope="row">Plánovaný audit</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_scheduled_audit]" value="1" <?php checked($settings['agent_scheduled_audit']); ?> /> Agent sám spustí audit podľa intervalu.</label></td></tr>
					<tr><th scope="row">Prebudovanie vlastných produktov</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_rebuild_own_products]" value="1" <?php checked($settings['agent_rebuild_own_products']); ?> /> Agent smie opravovať produkty vytvorené manifestom KomArena Factory.</label></td></tr>
					<tr><th scope="row">Zámok hromadného prebudovania</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_bulk_rebuild_lock]" value="1" <?php checked($settings['agent_bulk_rebuild_lock']); ?> /> Zamknúť hromadné automatické prebudovania. Jednotlivé obsahové opravy a upratanie zostávajú dostupné iba cez výslovný administrátorský alebo PC príkaz.</label></td></tr>
					<tr><th scope="row"><label>Audit interval hodín</label></th><td><input type="number" min="1" max="168" name="komarena_pf_settings[agent_audit_interval_hours]" value="<?php echo esc_attr($settings['agent_audit_interval_hours']); ?>" /></td></tr>
					<tr><th scope="row"><label>Limit prebudovania na beh</label></th><td><input type="number" min="0" max="10" name="komarena_pf_settings[agent_rebuild_limit]" value="<?php echo esc_attr($settings['agent_rebuild_limit']); ?>" /></td></tr>
					<tr><th scope="row"><label>Limit opakovania na beh</label></th><td><input type="number" min="0" max="25" name="komarena_pf_settings[agent_retry_limit]" value="<?php echo esc_attr($settings['agent_retry_limit']); ?>" /></td></tr>
					<tr><th scope="row">Woo atribúty</th><td><label><input type="checkbox" name="komarena_pf_settings[auto_apply_attributes]" value="1" <?php checked($settings['auto_apply_attributes']); ?> /> Agent automaticky doplní technické atribúty produktu.</label></td></tr>
					<tr><th scope="row"><label>Duplicity</label></th><td><select name="komarena_pf_settings[duplicate_strategy]"><option value="reuse_review" <?php selected($settings['duplicate_strategy'], 'reuse_review'); ?>>Označiť existujúci produkt na kontrolu</option><option value="create_anyway" <?php selected($settings['duplicate_strategy'], 'create_anyway'); ?>>Povoliť vytvorenie duplicity</option></select></td></tr>
					<tr><th scope="row"><label>Časový limit zdrojového importu</label></th><td><input type="number" min="3" max="30" name="komarena_pf_settings[supplier_fetch_timeout]" value="<?php echo esc_attr($settings['supplier_fetch_timeout']); ?>" /> sekúnd</td></tr>
					<tr><th scope="row"><label>Limit importu zo zdrojov</label></th><td><input type="number" min="1" max="100" name="komarena_pf_settings[supplier_import_limit]" value="<?php echo esc_attr($settings['supplier_import_limit']); ?>" /></td></tr>
					<tr><th scope="row">Automatické publikovanie</th><td><label><input type="checkbox" name="komarena_pf_settings[auto_publish]" value="1" <?php checked($settings['auto_publish']); ?> /> Publikovať iba produkty, ktoré prešli úplnou kontrolou kvality, majú overené zdroje, 100 % overené technické fakty, reálne obrázky a stav pripravené na publikovanie.</label></td></tr>
					<tr><th scope="row"><label>Home Assistant URL</label></th><td><input class="regular-text" type="url" name="komarena_pf_settings[home_assistant_url]" value="<?php echo esc_attr($settings['home_assistant_url']); ?>" /></td></tr>
					<tr><th scope="row"><label>ESPHome URL</label></th><td><input class="regular-text" type="url" name="komarena_pf_settings[esphome_url]" value="<?php echo esc_attr($settings['esphome_url']); ?>" /></td></tr>
					<tr><th scope="row"><label>Overené zdroje</label></th><td><textarea class="large-text" rows="6" name="komarena_pf_settings[trusted_source_urls]"><?php echo esc_textarea($settings['trusted_source_urls']); ?></textarea><p class="description">Globálne zdroje alebo interné KomArena referencie. Produktové zdroje odporúčame zadávať pri konkrétnom produkte.</p></td></tr>
					<tr><th scope="row">Autonómny dozor webu</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_auto_autonomy_supervisor]" value="1" <?php checked($settings['agent_auto_autonomy_supervisor']); ?> /> Agent pri behu skontroluje produkty, ceny, doplnky, rozloženie, frontu, plánované úlohy a informačné stránky.</label></td></tr>
					<tr><th scope="row"><label>Limit autonómneho dozoru</label></th><td><input type="number" min="1" max="500" name="komarena_pf_settings[agent_autonomy_audit_limit]" value="<?php echo esc_attr($settings['agent_autonomy_audit_limit']); ?>" /></td></tr>
					<tr><th scope="row">Bezpečné opravy v dozore</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_autonomy_safe_repairs]" value="1" <?php checked($settings['agent_autonomy_safe_repairs']); ?> /> Dozor smie spustiť iba bezpečné opravy bez mazania médií, publikovania, zmeny cien, vypínania doplnkov alebo hromadného prebudovania.</label></td></tr>
					<tr><th scope="row">Kontrola informačných stránok</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_autonomy_law_watch]" value="1" <?php checked($settings['agent_autonomy_law_watch']); ?> /> Dozor sleduje prítomnosť obchodných podmienok, odstúpenia, reklamácií, GDPR/cookies, kontaktu, dopravy a platby.</label></td></tr>
					<tr><th scope="row">Cenová kontrola</th><td><label><input type="checkbox" name="komarena_pf_settings[agent_autonomy_price_watch]" value="1" <?php checked($settings['agent_autonomy_price_watch']); ?> /> Dozor hlási ceny, ktoré nekončia .99, chýbajúce ceny a nesprávny daňový stav. Ceny automaticky nemení.</label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
		$this->footer();
	}

	public function handle_create_single() {
		$this->guard_action('komarena_pf_create_single');
		$urls = $this->textarea_urls($_POST['source_urls'] ?? '');
		$image_urls = $this->textarea_urls($_POST['image_urls'] ?? '');
		$result = $this->plugin->creator->create_from_name(wp_unslash($_POST['product_name'] ?? ''), array(
			'source_urls'   => $urls,
			'image_sources' => $image_urls,
		));
		if (is_wp_error($result)) {
			$this->redirect('komarena-pf-single', array('kpf_error' => $result->get_error_message()));
		}

		$msg = $result['ready_to_publish'] ? 'Produkt je pripravený na publikovanie.' : 'Produkt bol vytvorený, ale potrebuje kontrolu.';
		wp_safe_redirect(add_query_arg(array('post' => $result['product_id'], 'action' => 'edit', 'kpf_message' => $msg), admin_url('post.php')));
		exit;
	}

	public function handle_enqueue_bulk() {
		$this->guard_action('komarena_pf_enqueue_bulk');
		$count = $this->plugin->queue->enqueue_from_text($_POST['bulk_products'] ?? '');
		$this->redirect('komarena-pf-queue', array('kpf_message' => sprintf('Do fronty pridané úlohy: %d', $count)));
	}

	public function handle_supplier_import() {
		$this->guard_action('komarena_pf_supplier_import');
		$result = $this->plugin->supplier->import_from_text($_POST['supplier_urls'] ?? '');
		$this->redirect('komarena-pf-supplier', array(
			'kpf_message' => sprintf('Import zo zdrojových odkazov: vytvorené %d, zlyhalo %d.', (int) $result['created'], (int) $result['failed']),
		));
	}

	public function handle_builder_images() {
		$this->guard_action('komarena_pf_builder_images');
		$product_name = sanitize_text_field(wp_unslash($_POST['product_name'] ?? ''));
		$result = $this->plugin->csv_builder->create_image_package($product_name, array(
			'source_urls' => $this->textarea_urls($_POST['source_urls'] ?? ''),
			'image_sources' => $this->textarea_urls($_POST['image_urls'] ?? ''),
		));
		if (is_wp_error($result)) {
			$this->redirect('komarena-pf-csv-builder', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-pf-csv-builder', array('kpf_message' => 'Obrázkový ZIP je pripravený. Stiahnite ho, nahrajte 4 obrázky do knižnice médií a vložte odkaz jedného obrázka v druhom kroku.'));
	}

	public function handle_builder_csv() {
		$this->guard_action('komarena_pf_builder_csv');
		$product_name = sanitize_text_field(wp_unslash($_POST['product_name'] ?? ''));
		$one_image_url = esc_url_raw(wp_unslash($_POST['one_image_url'] ?? ''));
		$result = $this->plugin->csv_builder->create_csv_package($product_name, $one_image_url, array(
			'source_urls' => $this->textarea_urls($_POST['source_urls'] ?? ''),
		));
		if (is_wp_error($result)) {
			$this->redirect('komarena-pf-csv-builder', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-pf-csv-builder', array('kpf_message' => 'WooCommerce CSV + manifest ZIP je pripraveny na stiahnutie.'));
	}

	public function handle_create_task() {
		$this->guard_action('komarena_pf_create_task');
		$type = sanitize_key($_POST['task_type'] ?? '');
		$title = sanitize_text_field(wp_unslash($_POST['task_title'] ?? ''));
		$priority = absint($_POST['priority'] ?? 10);
		$payload = $this->task_payload_from_request($type);

		if (is_wp_error($payload)) {
			$this->redirect('komarena-pf-tasks', array('kpf_error' => $payload->get_error_message()));
		}

		$result = $this->plugin->tasks->create($type, $title, $payload, $priority);
		if (is_wp_error($result)) {
			$this->redirect('komarena-pf-tasks', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-pf-tasks', array('kpf_message' => sprintf('Agentná úloha #%d bola naplánovaná.', (int) $result)));
	}

	public function handle_run_tasks() {
		$this->guard_action('komarena_pf_run_tasks');
		$limit = absint($_POST['limit'] ?? $this->plugin->settings->get('agent_task_batch_size', 3));
		$results = $this->plugin->tasks->run_next($limit);
		$this->redirect('komarena-pf-tasks', array('kpf_message' => sprintf('Spracovanie úloh dokončilo úlohy: %d', count($results))));
	}

	public function handle_run_site_layout_task() {
		$this->guard_action('komarena_pf_run_site_layout_task');
		$type = sanitize_key($_POST['layout_task'] ?? '');
		$payload = array('page_id' => absint($_POST['page_id'] ?? 0));
		if ('homepage_sidebar_restore' === $type) {
			if ('OBNOVIT' !== trim((string) wp_unslash($_POST['confirm_restore'] ?? ''))) {
				$this->redirect('komarena-pf-site-layout', array('kpf_error' => 'Obnova vyžaduje potvrdenie OBNOVIT.'));
			}
			$payload['admin_confirmed'] = true;
		}

		$task_id = $this->plugin->tasks->create($type, 'Rozloženie webu: ' . $type, $payload, 5);
		if (is_wp_error($task_id)) {
			$this->redirect('komarena-pf-site-layout', array('kpf_error' => $task_id->get_error_message()));
		}
		$task = $this->plugin->tasks->get($task_id);
		$result = $task ? $this->plugin->tasks->run_task($task) : new WP_Error('komarena_pf_task_missing', 'Úlohu rozloženia sa nepodarilo načítať.');
		if (is_wp_error($result)) {
			$this->redirect('komarena-pf-site-layout', array('kpf_error' => $result->get_error_message()));
		}
		$this->redirect('komarena-pf-site-layout', array('kpf_message' => $result['message'] ?? 'Úloha rozloženia je hotová.'));
	}

	public function handle_run_plugin_audit() {
		$this->guard_action('komarena_pf_run_plugin_audit');
		$result = $this->plugin->housekeeper->audit_installed_plugins();
		$summary = $result['summary'] ?? array();
		$this->redirect('komarena-pf-plugin-housekeeper', array(
			'kpf_message' => sprintf('Audit doplnkov hotový: spolu %d, kandidáti na upratanie %d, riziká %d.', (int) ($summary['total'] ?? 0), (int) ($summary['cleanup_candidates'] ?? 0), (int) ($summary['high_risk'] ?? 0)),
		));
	}

	public function handle_deactivate_plugins() {
		$this->guard_action('komarena_pf_deactivate_plugins');
		if ('DEAKTIVOVAT' !== trim((string) wp_unslash($_POST['confirm_deactivate'] ?? ''))) {
			$this->redirect('komarena-pf-plugin-housekeeper', array('kpf_error' => 'Deaktivácia vyžaduje potvrdenie DEAKTIVOVAT.'));
		}

		$result = $this->plugin->housekeeper->deactivate_selected(array(
			'admin_confirmed' => true,
			'plugins'         => (array) ($_POST['plugins'] ?? array()),
		));
		if (is_wp_error($result)) {
			$this->redirect('komarena-pf-plugin-housekeeper', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-pf-plugin-housekeeper', array(
			'kpf_message' => sprintf('Deaktivované doplnky: %d, preskočené: %d.', count($result['deactivated']), count($result['skipped'])),
		));
	}

	public function handle_run_queue() {
		$this->guard_action('komarena_pf_run_queue');
		$limit = absint($_POST['limit'] ?? $this->plugin->settings->get('queue_batch_size', 3));
		$results = $this->plugin->queue->run_next($limit);
		$this->redirect('komarena-pf-queue', array('kpf_message' => sprintf('Spracované úlohy: %d', count($results))));
	}

	public function handle_run_agent() {
		$this->guard_action('komarena_pf_run_agent');
		$result = $this->plugin->agent->run(array('trigger' => 'manual'));
		$processed = isset($result['processed_queue']) ? (int) $result['processed_queue'] : 0;
		$tasks = isset($result['processed_tasks']) ? (int) $result['processed_tasks'] : 0;
		$rebuilt = isset($result['rebuilt_products']) ? (int) $result['rebuilt_products'] : 0;
		$plugins = !empty($result['plugin_audit']) ? 1 : 0;
		$layout = !empty($result['site_layout']) ? 1 : 0;
		$audit = !empty($result['audit']) && is_array($result['audit']) ? (int) ($result['audit']['checked'] ?? 0) : 0;
		$audit_repairs = !empty($result['audit_repairs']) && is_array($result['audit_repairs']) ? (int) ($result['audit_repairs']['rebuilt'] ?? 0) : 0;
		$this->redirect('komarena-pf-agent', array('kpf_message' => sprintf('Agent hotový: audit doplnkov %d, rozloženie webu %d, produktový audit %d, opravy auditu %d, fronta %d, úlohy %d, prebudovania %d.', $plugins, $layout, $audit, $audit_repairs, $processed, $tasks, $rebuilt)));
	}

	public function handle_activate_production() {
		$this->guard_action('komarena_pf_activate_production');
		$result = $this->plugin->production->activate(array(
			'note' => 'Ostrá prevádzka aktivovaná z administrácie WordPressu.',
		));

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Ostrá prevádzka je zapnutá. Automatické publikovanie: %s, pripravené nepublikované produkty: %d.',
				!empty($result['auto_publish']) ? 'zapnuté' : 'vypnuté',
				(int) ($result['ready_unpublished_products'] ?? 0)
			),
		));
	}

	public function handle_run_production() {
		$this->guard_action('komarena_pf_run_production');
		$limit = max(1, min(100, absint($_POST['limit'] ?? 20)));
		$result = $this->plugin->production->run_cycle(array('limit' => $limit));
		$publish = is_array($result['publish_ready_products'] ?? null) ? $result['publish_ready_products'] : array();

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Ostrý produkčný beh hotový: stav %s, publikované %d, na kontrolu %d.',
				$this->visible_status($result['status'] ?? 'done'),
				(int) ($publish['published'] ?? 0),
				(int) ($publish['needs_review'] ?? 0)
			),
		));
	}

	public function handle_publish_ready_products() {
		$this->guard_action('komarena_pf_publish_ready_products');
		$limit = max(1, min(100, absint($_POST['limit'] ?? 20)));
		$result = $this->plugin->production->publish_ready_products($limit);
		if (is_wp_error($result)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Publikovanie pripravených produktov hotové: skontrolované %d, publikované %d, na kontrolu %d, zlyhané %d.',
				(int) ($result['checked'] ?? 0),
				(int) ($result['published'] ?? 0),
				(int) ($result['needs_review'] ?? 0),
				(int) ($result['failed'] ?? 0)
			),
		));
	}

	public function handle_run_image_recovery() {
		$this->guard_action('komarena_pf_run_image_recovery');
		$limit = max(1, min(100, absint($_POST['limit'] ?? 10)));
		$result = $this->plugin->image_recovery->image_recovery_run($limit);
		if (is_wp_error($result)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Obrázková obnova hotová: skontrolované %d, obnovené %d, zablokované %d, zlyhané %d.',
				(int) ($result['checked'] ?? 0),
				(int) ($result['recovered'] ?? 0),
				(int) ($result['blocked'] ?? 0),
				(int) ($result['failed'] ?? 0)
			),
		));
	}

	public function handle_repair_product_images() {
		$this->guard_action('komarena_pf_repair_product_images');
		$product_id = absint($_REQUEST['product_id'] ?? 0);
		if (!$product_id) {
			$this->redirect('komarena-product-factory', array('kpf_error' => 'Oprava obrázkov vyžaduje ID produktu.'));
		}

		$result = $this->plugin->image_recovery->repair_product_images($product_id);
		if (is_wp_error($result)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Obrázky produktu #%d spracované: stav %s. %s',
				$product_id,
				$this->visible_status($result['status'] ?? 'needs_review'),
				(string) ($result['reason'] ?? '')
			),
		));
	}

	public function handle_run_autonomy() {
		$this->guard_action('komarena_pf_run_autonomy');
		$limit = max(1, min(500, absint($_POST['limit'] ?? $this->plugin->settings->get('agent_autonomy_audit_limit', 100))));
		$result = $this->plugin->autonomy->run_cycle(array(
			'trigger' => 'manual_admin',
			'limit' => $limit,
			'run_product_audit' => !empty($_POST['run_product_audit']),
			'safe_repairs' => !empty($_POST['safe_repairs']),
		));

		if (is_wp_error($result)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Autonómny dozor hotový: stav %s, upozornenia %d, návrhy %d, bezpečné akcie %d. Rizikové zásahy ostali blokované.',
				$this->visible_status($result['status'] ?? 'done'),
				count((array) ($result['warnings'] ?? array())),
				count((array) ($result['proposals'] ?? array())),
				count((array) ($result['safe_actions'] ?? array()))
			),
		));
	}

	public function handle_create_legal_draft() {
		$this->guard_action('komarena_pf_create_legal_draft');
		$type = sanitize_key($_POST['legal_page_type'] ?? 'cookies');
		$overwrite = !empty($_POST['overwrite']);
		$result = $this->plugin->legal_pages->create_draft($type, array('overwrite' => $overwrite));
		if (is_wp_error($result)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $result->get_error_message()));
		}

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Koncept informačnej stránky hotový: %s, ID stránky %d. Pred publikovaním vyžaduje kontrolu.',
				$result['message'] ?? 'vytvorený',
				(int) ($result['page_id'] ?? 0)
			),
		));
	}

	public function handle_run_safe_repairs() {
		$this->guard_action('komarena_pf_run_safe_repairs');
		$limit = max(1, min(500, absint($_POST['limit'] ?? 200)));
		$audit_limit = max(1, min(500, absint($_POST['audit_limit'] ?? 100)));
		$started_at = current_time('mysql');

		$plugin_audit = $this->plugin->housekeeper->audit_installed_plugins();
		if (is_wp_error($plugin_audit)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $plugin_audit->get_error_message()));
		}

		$cleanup = $this->plugin->rebuild->cleanup_bad_product_images(array('limit' => $limit));
		if (is_wp_error($cleanup)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $cleanup->get_error_message()));
		}

		$audit = $this->plugin->audit->audit_products($audit_limit);
		if (is_wp_error($audit)) {
			$this->redirect('komarena-product-factory', array('kpf_error' => $audit->get_error_message()));
		}

		$restored_titles = 0;
		foreach ((array) ($cleanup['title_restores'] ?? array()) as $row) {
			if ('restored' === (string) ($row['status'] ?? '')) {
				$restored_titles++;
			}
		}

		$result = array(
			'started_at'        => $started_at,
			'finished_at'       => current_time('mysql'),
			'plugin_audit'      => $plugin_audit,
			'cleanup'           => $cleanup,
			'audit'             => $audit,
			'bulk_rebuild_lock' => (int) $this->plugin->settings->get('agent_bulk_rebuild_lock', 1),
			'publish_blocked'   => true,
			'note'              => 'Bezpečná oprava nerobí hromadné prebudovanie, nemaže médiá a nepublikuje produkty.',
		);
		update_option('komarena_pf_last_safe_repair', $result, false);
		$this->plugin->logger->log('info', 'Bezpečné opravy po reporte dokončené', 'bezpecne-opravy', array(
			'obnovene_nazvy'    => $restored_titles,
			'upravene_produkty' => (int) ($cleanup['changed'] ?? 0),
			'odpojene_obrazky'  => (int) ($cleanup['detached_images'] ?? 0),
			'skontrolovane'     => (int) ($audit['checked'] ?? 0),
			's_problemami'      => (int) ($audit['with_issues'] ?? 0),
		));

		$this->redirect('komarena-product-factory', array(
			'kpf_message' => sprintf(
				'Bezpečné opravy hotové: obnovené názvy %d, upravené produkty %d, odpojené zlé obrázky %d, audit %d produktov, problémových %d. Hromadné prebudovanie a publikovanie ostali blokované.',
				$restored_titles,
				(int) ($cleanup['changed'] ?? 0),
				(int) ($cleanup['detached_images'] ?? 0),
				(int) ($audit['checked'] ?? 0),
				(int) ($audit['with_issues'] ?? 0)
			),
		));
	}

	public function handle_run_audit() {
		$this->guard_action('komarena_pf_run_audit');
		$result = $this->plugin->audit->audit_products(absint($_POST['limit'] ?? 100));
		if (is_wp_error($result)) {
			$this->redirect('komarena-pf-audit', array('kpf_error' => $result->get_error_message()));
		}
		$this->redirect('komarena-pf-audit', array('kpf_message' => sprintf('Audit hotový: %d produktov, %d s problémami.', $result['checked'], $result['with_issues'])));
	}

	public function handle_rebuild_product() {
		$product_id = absint($_REQUEST['product_id'] ?? 0);
		$this->guard_action('komarena_pf_rebuild_' . $product_id, '_wpnonce');
		$result = $this->plugin->rebuild->rebuild($product_id, array(
			'overwrite_price' => !empty($_REQUEST['overwrite_price']),
			'force_images'    => !empty($_REQUEST['allow_image_update']) && !empty($_REQUEST['force_images']),
			'allow_title_update' => !empty($_REQUEST['allow_title_update']),
			'allow_image_update' => !empty($_REQUEST['allow_image_update']),
			'allow_price_update' => !empty($_REQUEST['allow_price_update']),
			'allow_category_update' => !empty($_REQUEST['allow_category_update']),
			'preserve_status' => true,
		));
		if (is_wp_error($result)) {
			wp_safe_redirect(add_query_arg(array('post' => $product_id, 'action' => 'edit', 'kpf_error' => $result->get_error_message()), admin_url('post.php')));
			exit;
		}
		$msg = $result['ready_to_publish'] ? 'Produkt prebudovaný a pripravený.' : 'Produkt prebudovaný, ale potrebuje kontrolu.';
		wp_safe_redirect(add_query_arg(array('post' => $product_id, 'action' => 'edit', 'kpf_message' => $msg), admin_url('post.php')));
		exit;
	}

	public function handle_run_product_qa() {
		$product_id = absint($_REQUEST['product_id'] ?? 0);
		$this->guard_action('komarena_pf_run_product_qa_' . $product_id, '_wpnonce');
		$payload = array('product_id' => $product_id);
		$task_id = $this->plugin->tasks->create('review_product', 'Kontrola kvality produktu #' . $product_id, $payload, 5);
		if (is_wp_error($task_id)) {
			wp_safe_redirect(add_query_arg(array('post' => $product_id, 'action' => 'edit', 'kpf_error' => $task_id->get_error_message()), admin_url('post.php')));
			exit;
		}
		$task = $this->plugin->tasks->get($task_id);
		$result = $task ? $this->plugin->tasks->run_task($task) : new WP_Error('komarena_pf_task_missing', 'Úlohu kontroly kvality sa nepodarilo načítať.');
		$ready = is_array($result) && !empty($result['ready_to_publish']);
		$msg = $ready ? 'Kontrola kvality prešla, produkt je pripravený na publikovanie.' : 'Kontrola kvality je dokončená, produkt potrebuje kontrolu.';
		wp_safe_redirect(add_query_arg(array('post' => $product_id, 'action' => 'edit', 'kpf_message' => $msg), admin_url('post.php')));
		exit;
	}

	public function product_row_action($actions, $post) {
		if ('product' !== $post->post_type || !current_user_can($this->plugin->capability())) {
			return $actions;
		}

		$url = wp_nonce_url(
			add_query_arg(array(
				'action'     => 'komarena_pf_rebuild_product',
				'product_id' => $post->ID,
			), admin_url('admin-post.php')),
			'komarena_pf_rebuild_' . $post->ID
		);
		$qa_url = wp_nonce_url(
			add_query_arg(array(
				'action'     => 'komarena_pf_run_product_qa',
				'product_id' => $post->ID,
			), admin_url('admin-post.php')),
			'komarena_pf_run_product_qa_' . $post->ID
		);

		$actions['komarena_pf_rebuild'] = '<a href="' . esc_url($url) . '">Prebudovať podľa KomArena štandardu</a>';
		$actions['komarena_pf_qa'] = '<a href="' . esc_url($qa_url) . '">Spustiť kontrolu kvality KomArena</a>';
		$actions['komarena_pf_images'] = '<a href="' . esc_url(wp_nonce_url(add_query_arg(array('action' => 'komarena_pf_repair_product_images', 'product_id' => $post->ID), admin_url('admin-post.php')), 'komarena_pf_repair_product_images')) . '">Opraviť obrázky</a>';
		return $actions;
	}

	public function meta_boxes() {
		add_meta_box('komarena_pf_product_box', 'KomArena produktový agent', array($this, 'product_meta_box'), 'product', 'side', 'high');
	}

	public function product_meta_box($post) {
		if (!current_user_can($this->plugin->capability())) {
			return;
		}

		$status = get_post_meta($post->ID, '_komarena_pf_status', true);
		$qa = json_decode((string) get_post_meta($post->ID, '_komarena_pf_qa_report', true), true);
		$url = wp_nonce_url(add_query_arg(array('action' => 'komarena_pf_rebuild_product', 'product_id' => $post->ID), admin_url('admin-post.php')), 'komarena_pf_rebuild_' . $post->ID);
		$qa_url = wp_nonce_url(add_query_arg(array('action' => 'komarena_pf_run_product_qa', 'product_id' => $post->ID), admin_url('admin-post.php')), 'komarena_pf_run_product_qa_' . $post->ID);
		echo '<p><strong>Stav:</strong> ' . esc_html($status ? $this->visible_status($status) : 'nezistený') . '</p>';
		if (!empty($qa['missing'])) {
			echo '<p><strong>Kontrola kvality:</strong></p><ul class="kpf-missing">';
			foreach ($qa['missing'] as $missing) {
				echo '<li>' . esc_html($missing) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Prebudovať štandard</a></p>';
		echo '<p><a class="button" href="' . esc_url($qa_url) . '">Spustiť kontrolu kvality</a></p>';
		echo '<p><a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(array('action' => 'komarena_pf_repair_product_images', 'product_id' => $post->ID), admin_url('admin-post.php')), 'komarena_pf_repair_product_images')) . '">Opraviť obrázky</a></p>';
	}

	private function header($title) {
		echo '<div class="wrap kpf-wrap"><h1>' . esc_html($title) . '</h1>';
	}

	private function footer() {
		echo '</div>';
	}

	private function metric($label, $value) {
		echo '<div class="kpf-metric"><span>' . esc_html($label) . '</span><strong>' . esc_html(number_format_i18n((int) $value)) . '</strong></div>';
	}

	private function production_panel() {
		$status = !empty($this->plugin->production) ? $this->plugin->production->status() : array();
		$active = !empty($status['production_mode']);
		$ready = (int) ($status['ready_unpublished_products'] ?? 0);

		echo '<div class="kpf-panel kpf-action-panel">';
		echo '<div class="kpf-action-copy">';
		echo '<h2>Ostrá prevádzka</h2>';
		echo '<p>Produkčný režim prepne KomArena agenta z kontrolného režimu do ostrého výkonu. Agent smie spúšťať audit, opravy, frontu a publikovanie pripravených produktov bez ďalšieho ručného kroku.</p>';
		echo '<p class="kpf-note"><strong>Pevné pravidlo:</strong> produkt sa publikuje iba vtedy, keď má overené zdroje, 100 % overené technické fakty, reálne originálne obrázky bez vodoznaku a cudzieho loga, SEO, EAN, SKU, cenu .99, bezpečnostný blok, záruku a úspešnú kontrolu kvality.</p>';
		echo '<div class="kpf-status-list">';
		echo '<span><strong>Režim</strong> ' . esc_html($active ? 'ostrá prevádzka' : 'kontrolný režim') . '</span>';
		echo '<span><strong>Automatické publikovanie</strong> ' . esc_html(!empty($status['auto_publish']) ? 'zapnuté' : 'vypnuté') . '</span>';
		echo '<span><strong>Pripravené na publikovanie</strong> ' . esc_html(number_format_i18n($ready)) . '</span>';
		echo '<span><strong>Reálne obrázky</strong> ' . esc_html(!empty($status['require_real_product_images']) ? 'povinné' : 'nepovinné') . '</span>';
		echo '<span><strong>Overené zdroje</strong> ' . esc_html(!empty($status['strict_source_verification']) ? 'povinné' : 'nepovinné') . '</span>';
		echo '</div>';
		echo '</div>';
		echo '<div class="kpf-action-form">';
		if (!$active) {
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			echo '<input type="hidden" name="action" value="komarena_pf_activate_production" />';
			wp_nonce_field('komarena_pf_activate_production');
			echo '<button class="button button-primary button-hero">Zapnúť ostrú prevádzku</button>';
			echo '</form>';
		}
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
		echo '<input type="hidden" name="action" value="komarena_pf_run_production" />';
		wp_nonce_field('komarena_pf_run_production');
		echo '<label>Limit publikovania pripravených produktov</label>';
		echo '<input type="number" min="1" max="100" name="limit" value="20" />';
		echo '<button class="button button-primary">Spustiť ostrý produkčný beh</button>';
		echo '</form>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
		echo '<input type="hidden" name="action" value="komarena_pf_publish_ready_products" />';
		wp_nonce_field('komarena_pf_publish_ready_products');
		echo '<label>Limit pripravených produktov</label>';
		echo '<input type="number" min="1" max="100" name="limit" value="20" />';
		echo '<button class="button">Publikovať pripravené produkty</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	private function image_recovery_panel() {
		$counts = !empty($this->plugin->image_recovery) ? $this->plugin->image_recovery->counts() : array();
		$last = get_option('komarena_pf_last_image_recovery_run', array());
		$last = is_array($last) ? $last : array();

		echo '<div class="kpf-panel kpf-action-panel">';
		echo '<div class="kpf-action-copy">';
		echo '<h2>Obrázková obnova a kontrola zhody</h2>';
		echo '<p>Agent dohľadá kandidátov obrázkov, overí presnú zhodu produktu, pôvod, použiteľnosť, vodoznak, cudzie predajné logo a príznak AI. Finálne priradenie obrázkov prebehne iba pri 4 originálnych alebo reálnych produktových fotkách.</p>';
		echo '<p class="kpf-note"><strong>Pevné pravidlo:</strong> ak agent nevie potvrdiť 4 čisté a presné obrázky, produkt označí ako zablokovaný obrázkami a nepublikuje ho.</p>';
		echo '<div class="kpf-status-list">';
		echo '<span><strong>Bez 4 obrázkov</strong> ' . esc_html(number_format_i18n((int) ($counts['without_four_images'] ?? 0))) . '</span>';
		echo '<span><strong>Zablokované obrázkami</strong> ' . esc_html(number_format_i18n((int) ($counts['blocked_by_images'] ?? 0))) . '</span>';
		echo '<span><strong>Obrázky pripravené</strong> ' . esc_html(number_format_i18n((int) ($counts['ready_images'] ?? 0))) . '</span>';
		echo '<span><strong>Obrázky na kontrolu</strong> ' . esc_html(number_format_i18n((int) ($counts['needs_review_images'] ?? 0))) . '</span>';
		echo '</div>';
		if (!empty($last['finished_at'])) {
			echo '<p class="kpf-last-run">Posledná obrázková obnova: <strong>' . esc_html($last['finished_at']) . '</strong> | obnovené: <strong>' . esc_html((int) ($last['recovered'] ?? 0)) . '</strong> | zablokované: <strong>' . esc_html((int) ($last['blocked'] ?? 0)) . '</strong> | zlyhané: <strong>' . esc_html((int) ($last['failed'] ?? 0)) . '</strong></p>';
		}
		echo '</div>';
		echo '<div class="kpf-action-form">';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="komarena_pf_run_image_recovery" />';
		wp_nonce_field('komarena_pf_run_image_recovery');
		echo '<label>Limit produktov na obrázkovú obnovu</label>';
		echo '<input type="number" min="1" max="100" name="limit" value="10" />';
		echo '<button class="button button-primary">Spustiť obrázkovú obnovu</button>';
		echo '</form>';
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
		echo '<input type="hidden" name="action" value="komarena_pf_repair_product_images" />';
		wp_nonce_field('komarena_pf_repair_product_images');
		echo '<label>ID produktu na opravu obrázkov</label>';
		echo '<input type="number" min="1" name="product_id" value="" placeholder="Napr. 3016" />';
		echo '<button class="button">Opraviť obrázky produktu</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	private function autonomy_panel() {
		$last = !empty($this->plugin->autonomy) ? $this->plugin->autonomy->last_report() : array();
		$warnings = is_array($last) ? count((array) ($last['warnings'] ?? array())) : 0;
		$proposals = is_array($last) ? count((array) ($last['proposals'] ?? array())) : 0;
		$safe_actions = is_array($last) ? count((array) ($last['safe_actions'] ?? array())) : 0;
		$guards = is_array($last) && !empty($last['guards']) ? (array) $last['guards'] : array(
			'hromadne_prebudovanie' => 'blokované',
			'publikovanie' => 'iba po schválení',
			'mazanie_medii' => 'blokované',
			'vypinanie_doplnkov' => 'iba po potvrdení',
			'zmena_cien' => 'iba ako návrh',
		);

		echo '<div class="kpf-panel kpf-action-panel kpf-autonomy-panel">';
		echo '<div class="kpf-action-copy">';
		echo '<h2>Autonómny dozor webu</h2>';
		echo '<p>Agent priebežne kontroluje produkty, SEO, obrázky, ceny, daňový stav, doplnky, domovskú stránku, frontu, plánované úlohy a prítomnosť dôležitých informačných stránok. Bezpečné opravy robí sám, rizikové zásahy iba navrhne.</p>';
		echo '<p class="kpf-note"><strong>Bezpečnostná brzda:</strong> nepublikuje produkty bez schválenia, nemení ceny bez potvrdenia, nevypína doplnky bez potvrdenia, nemaže médiá a nespúšťa hromadný rebuild.</p>';
		if (!empty($last['finished_at'])) {
			echo '<p class="kpf-last-run">Posledný autonómny dozor: <strong>' . esc_html($last['finished_at']) . '</strong> | stav: <strong>' . esc_html($this->visible_status($last['status'] ?? 'done')) . '</strong> | upozornenia: <strong>' . esc_html($warnings) . '</strong> | návrhy: <strong>' . esc_html($proposals) . '</strong> | bezpečné akcie: <strong>' . esc_html($safe_actions) . '</strong></p>';
		} else {
			echo '<p class="kpf-last-run">Autonómny dozor ešte nebol spustený.</p>';
		}
		echo '<div class="kpf-status-list">';
		foreach ($guards as $label => $value) {
			echo '<span><strong>' . esc_html($this->human_key($label)) . '</strong> ' . esc_html((string) $value) . '</span>';
		}
		echo '</div>';
		$this->autonomy_proposals_preview($last);
		echo '</div>';
		echo '<form class="kpf-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="komarena_pf_run_autonomy" />';
		wp_nonce_field('komarena_pf_run_autonomy');
		echo '<label>Limit kontroly produktov</label>';
		echo '<input type="number" min="1" max="500" name="limit" value="' . esc_attr($this->plugin->settings->get('agent_autonomy_audit_limit', 100)) . '" />';
		echo '<label><input type="checkbox" name="run_product_audit" value="1" checked /> Spustiť produktový audit</label>';
		echo '<label><input type="checkbox" name="safe_repairs" value="1" ' . checked($this->plugin->settings->get('agent_autonomy_safe_repairs', 1), 1, false) . ' /> Povoliť iba bezpečné opravy</label>';
		echo '<button class="button button-primary button-hero">Spustiť autonómny dozor teraz</button>';
		echo '</form>';
		echo '<div class="kpf-action-links">';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-agent')) . '">Autopilot agenta</a>';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-audit')) . '">Audit produktov</a>';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-plugin-housekeeper')) . '">Audit doplnkov</a>';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-settings')) . '">Nastavenia</a>';
		echo '</div>';
		echo '</div>';
	}

	private function autonomy_proposals_preview($report) {
		if (!is_array($report) || empty($report['proposals'])) {
			return;
		}

		echo '<div class="kpf-autonomy-proposals">';
		echo '<h3>Návrhy na schválenie</h3>';
		echo '<ul class="kpf-missing">';
		foreach (array_slice((array) $report['proposals'], 0, 5) as $proposal) {
			$title = is_array($proposal) ? (string) ($proposal['title'] ?? '') : '';
			$message = is_array($proposal) ? (string) ($proposal['message'] ?? '') : '';
			echo '<li><strong>' . esc_html($title) . '</strong> ' . esc_html($message) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	private function legal_pages_panel() {
		$last = get_option('komarena_pf_last_legal_page_draft', array());
		$last = is_array($last) ? $last : array();

		echo '<div class="kpf-panel kpf-action-panel">';
		echo '<div class="kpf-action-copy">';
		echo '<h2>Informačné stránky</h2>';
		echo '<p>Agent vie pripraviť chýbajúce informačné stránky ako koncepty na schválenie. Nepublikuje ich automaticky a nenahrádza právnu kontrolu textu.</p>';
		echo '<p class="kpf-note"><strong>Bezpečnostná brzda:</strong> vytvorí sa iba koncept. Pred publikovaním treba skontrolovať skutočné cookies, analytiku, marketing, platobné brány, dopravcov a cookie lištu.</p>';
		if (!empty($last['page_id'])) {
			echo '<p class="kpf-last-run">Posledný koncept: <strong>#' . esc_html((int) $last['page_id']) . '</strong> | stav: <strong>' . esc_html($this->visible_status($last['status'] ?? 'needs_review')) . '</strong>';
			if (!empty($last['edit_url'])) {
				echo ' | <a href="' . esc_url($last['edit_url']) . '">Upraviť koncept</a>';
			}
			echo '</p>';
		}
		echo '</div>';
		echo '<form class="kpf-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="komarena_pf_create_legal_draft" />';
		wp_nonce_field('komarena_pf_create_legal_draft');
		echo '<label>Typ stránky</label>';
		echo '<select name="legal_page_type"><option value="cookies">Cookies</option></select>';
		echo '<label><input type="checkbox" name="overwrite" value="1" /> Prepísať existujúci koncept</label>';
		echo '<button class="button button-primary button-hero">Vytvoriť koncept stránky</button>';
		echo '</form>';
		echo '<div class="kpf-action-links">';
		echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=page')) . '">Stránky</a>';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-agent')) . '">Autopilot agenta</a>';
		echo '</div>';
		echo '</div>';
	}

	private function human_key($key) {
		$key = str_replace('_', ' ', (string) $key);
		return ucfirst($key);
	}

	private function safe_repairs_panel() {
		$last = get_option('komarena_pf_last_safe_repair', array());
		$cleanup = is_array($last) && !empty($last['cleanup']) && is_array($last['cleanup']) ? $last['cleanup'] : array();
		$audit = is_array($last) && !empty($last['audit']) && is_array($last['audit']) ? $last['audit'] : array();

		echo '<div class="kpf-panel kpf-action-panel">';
		echo '<div class="kpf-action-copy">';
		echo '<h2>Opravy po reporte</h2>';
		echo '<p>Jedno tlačidlo spustí bezpečný opravný beh: kontrolu doplnkov, obnovu známych poškodených názvov, odpojenie zlých alebo neoverených produktových obrázkov a nový audit produktov.</p>';
		echo '<p class="kpf-note"><strong>Bezpečnostná brzda:</strong> nespúšťa hromadné prebudovanie, nemaže médiá, nevypína doplnky a nepublikuje produkty. Produkty, ktoré stále potrebujú obsahovú opravu alebo reálne obrázky, ostanú na ručnú kontrolu.</p>';
		if (!empty($last['finished_at'])) {
			echo '<p class="kpf-last-run">Posledná bezpečná oprava: <strong>' . esc_html($last['finished_at']) . '</strong> | upravené produkty: <strong>' . esc_html((int) ($cleanup['changed'] ?? 0)) . '</strong> | odpojené obrázky: <strong>' . esc_html((int) ($cleanup['detached_images'] ?? 0)) . '</strong> | problémové produkty po audite: <strong>' . esc_html((int) ($audit['with_issues'] ?? 0)) . '</strong></p>';
		}
		echo '</div>';
		echo '<form class="kpf-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="komarena_pf_run_safe_repairs" />';
		wp_nonce_field('komarena_pf_run_safe_repairs');
		echo '<label>Limit opravy obrázkov</label>';
		echo '<input type="number" min="1" max="500" name="limit" value="200" />';
		echo '<label>Limit auditu produktov</label>';
		echo '<input type="number" min="1" max="500" name="audit_limit" value="100" />';
		echo '<button class="button button-primary button-hero">Vykonať bezpečné opravy teraz</button>';
		echo '</form>';
		echo '<div class="kpf-action-links">';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-audit')) . '">Audit produktov</a>';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-review')) . '">Kontrola produktov</a>';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=komarena-pf-plugin-housekeeper')) . '">Audit doplnkov</a>';
		echo '</div>';
		echo '</div>';
	}

	private function agent_summary_panel($summary = null) {
		if (null === $summary) {
			$summary = get_option('komarena_pf_last_agent_summary', array());
		}
		if (!is_array($summary) || empty($summary)) {
			echo '<div class="kpf-panel"><h2>Súhrn agenta</h2><p>Zatiaľ neexistuje žiadny agentný beh.</p></div>';
			return;
		}

		echo '<div class="kpf-panel"><h2>Posledný agentný beh</h2>';
		echo '<table class="widefat striped"><tbody>';
		$rows = array(
			'Spúšťač' => $summary['trigger'] ?? '',
			'Začiatok' => $summary['started_at'] ?? '',
			'Koniec' => $summary['finished_at'] ?? '',
			'Zdroje doplnené' => empty($summary['sources_seeded']) ? 0 : 1,
			'Audit doplnkov' => !empty($summary['plugin_audit']) ? $this->visible_status($summary['plugin_audit']['status'] ?? 'hotovo') : '',
			'Rozloženie domovskej stránky' => !empty($summary['site_layout']) ? $this->visible_status($summary['site_layout']['status'] ?? 'hotovo') : '',
			'Produktový audit' => !empty($summary['audit']) && is_array($summary['audit']) ? (string) ($summary['audit']['checked'] ?? 0) : '',
			'Opravy z auditu' => !empty($summary['audit_repairs']) && is_array($summary['audit_repairs']) ? sprintf('opravené %d / plán %d / preskočené %d', (int) ($summary['audit_repairs']['rebuilt'] ?? 0), (int) ($summary['audit_repairs']['planned'] ?? 0), (int) ($summary['audit_repairs']['skipped'] ?? 0)) : '',
			'Opakované zlyhané úlohy' => $summary['retried_failed'] ?? 0,
			'Spracovaná fronta' => $summary['processed_queue'] ?? 0,
			'Spracované úlohy' => $summary['processed_tasks'] ?? 0,
			'Prebudované produkty' => $summary['rebuilt_products'] ?? 0,
			'Preskočené prebudovania' => $summary['skipped_rebuilds'] ?? 0,
		);
		foreach ($rows as $label => $value) {
			echo '<tr><th style="width:220px;">' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
		}
		if (!empty($summary['warnings']) && is_array($summary['warnings'])) {
			echo '<tr><th>Upozornenia</th><td>' . esc_html(implode(', ', $summary['warnings'])) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function health_panel() {
		$upload = wp_upload_dir();
		$upload_ok = empty($upload['error']) && !empty($upload['path']) && wp_mkdir_p($upload['path']);
		if ($upload_ok && function_exists('wp_is_writable')) {
			$upload_ok = wp_is_writable($upload['path']);
		}

		$checks = array(
			'WooCommerce' => $this->plugin->has_woocommerce(),
			'Nahrávací adresár zapisovateľný' => (bool) $upload_ok,
			'Grafická knižnica PHP' => function_exists('imagecreatetruecolor'),
			'ZIP rozšírenie PHP' => class_exists('ZipArchive'),
			'Plánovač WordPressu' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
			'Tabuľka fronty' => $this->table_exists('komarena_pf_queue'),
			'Tabuľka logov' => $this->table_exists('komarena_pf_logs'),
			'Tabuľka pamäte' => $this->table_exists('komarena_pf_memory'),
			'Tabuľka úloh' => $this->table_exists('komarena_pf_tasks'),
			'Naplánované spracovanie úloh' => (bool) wp_next_scheduled('komarena_pf_task_tick'),
		);

		echo '<div class="kpf-panel"><h2>Zdravie platformy</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Kontrola</th><th>Stav</th></tr></thead><tbody>';
		foreach ($checks as $label => $pass) {
			echo '<tr><td>' . esc_html($label) . '</td><td><span class="kpf-badge">' . ($pass ? 'OK' : 'Pozor') . '</span></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private function table_exists($suffix) {
		global $wpdb;

		$table = $wpdb->prefix . sanitize_key($suffix);
		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	private function visible_level($level) {
		$labels = array(
			'info'    => 'informácia',
			'warning' => 'upozornenie',
			'error'   => 'chyba',
			'success' => 'úspech',
		);

		$key = sanitize_key($level);
		return $labels[$key] ?? (string) $level;
	}

	private function visible_status($status) {
		$labels = array(
			'waiting'          => 'čaká',
			'running'          => 'beží',
			'done'             => 'hotovo',
			'failed'           => 'zlyhalo',
			'ready'            => 'pripravené',
			'needs_review'     => 'na kontrolu',
			'publish'          => 'publikovať',
			'draft'            => 'koncept',
			'skipped'          => 'preskočené',
			'locked'           => 'zamknuté',
			'pass'             => 'prešlo',
			'needs_sources'    => 'chýbajú zdroje',
			'failed_sources'   => 'zdroje zlyhali',
			'pending'          => 'čaká na schválenie',
			'private'          => 'súkromné',
			'active'           => 'aktívne',
			'inactive'         => 'neaktívne',
			'needs_approval'   => 'čaká na schválenie',
			'ready_to_publish' => 'pripravené na publikovanie',
		);

		$key = sanitize_key($status);
		return $labels[$key] ?? (string) $status;
	}

	private function visible_image_mode($mode) {
		$labels = array(
			'real_standardized'                    => 'reálne zjednotené obrázky',
			'real_partial'                         => 'čiastočné reálne podklady',
			'illustrated_fallback'                 => 'kreslený kontrolný náhľad',
			'illustrated_fallback_colored_pencil'  => 'kreslený kontrolný náhľad',
			'placeholder_generated_for_review'     => 'dočasný kontrolný náhľad',
			'reuse_existing_verified'              => 'zachované overené obrázky',
			'strict_review_no_images'              => 'čaká na reálne obrázky',
			'uploaded_real_sources'                => 'nahrané reálne podklady',
		);

		$key = strtolower(str_replace('-', '_', (string) $mode));
		return $labels[$key] ?? (string) $mode;
	}

	private function visible_image_variant($variant) {
		$labels = array(
			'main'      => 'hlavný pohľad',
			'angle'     => 'pohľad z uhla',
			'detail'    => 'detail',
			'technical' => 'technický pohľad',
		);

		$key = sanitize_key($variant);
		return $labels[$key] ?? (string) $variant;
	}

	private function visible_plugin_category($category) {
		$labels = array(
			'required'        => 'povinný',
			'komarena'        => 'KomArena',
			'optional_active' => 'voliteľný aktívny',
			'review'          => 'na kontrolu',
			'seo'             => 'SEO',
			'cache'           => 'vyrovnávacia pamäť',
			'security'        => 'bezpečnosť',
			'builder'         => 'tvorca stránok',
		);

		$key = sanitize_key($category);
		return $labels[$key] ?? (string) $category;
	}

	private function visible_risk($risk) {
		$labels = array(
			'low'    => 'nízke',
			'medium' => 'stredné',
			'high'   => 'vysoké',
		);

		$key = sanitize_key($risk);
		return $labels[$key] ?? (string) $risk;
	}

	private function logs_table($logs) {
		echo '<table class="widefat striped"><thead><tr><th>Čas</th><th>Úroveň</th><th>Kontext</th><th>Správa</th><th>Produkt</th><th>Beh</th></tr></thead><tbody>';
		foreach ($logs as $log) {
			echo '<tr>';
			echo '<td>' . esc_html($log['created_at']) . '</td>';
			echo '<td><span class="kpf-badge">' . esc_html($this->visible_level($log['level'])) . '</span></td>';
			echo '<td>' . esc_html($log['context']) . '</td>';
			echo '<td>' . esc_html($log['message']) . '</td>';
			echo '<td>' . ($log['product_id'] ? '<a href="' . esc_url(get_edit_post_link($log['product_id'])) . '">#' . esc_html($log['product_id']) . '</a>' : '') . '</td>';
			echo '<td><code>' . esc_html($log['run_id']) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function audit_table() {
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => 40,
			'meta_query'     => array(
				array('key' => '_komarena_pf_audit_issues', 'compare' => 'EXISTS'),
			),
		));

		echo '<table class="widefat striped"><thead><tr><th>Produkt</th><th>Problémy</th><th>Akcia</th></tr></thead><tbody>';
		foreach ($query->posts as $post) {
			$issues = json_decode((string) get_post_meta($post->ID, '_komarena_pf_audit_issues', true), true);
			if (empty($issues)) {
				continue;
			}
			$url = wp_nonce_url(add_query_arg(array('action' => 'komarena_pf_rebuild_product', 'product_id' => $post->ID), admin_url('admin-post.php')), 'komarena_pf_rebuild_' . $post->ID);
			echo '<tr>';
			echo '<td><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html(get_the_title($post)) . '</a></td>';
			echo '<td>' . esc_html(implode(', ', $issues)) . '</td>';
			echo '<td><a class="button" href="' . esc_url($url) . '">Prebudovať</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function review_products_table() {
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => 60,
			'meta_query'     => array(
				'relation' => 'OR',
				array('key' => '_komarena_pf_status', 'value' => 'needs_review'),
				array('key' => '_komarena_pf_audit_issues', 'compare' => 'EXISTS'),
				array('key' => '_komarena_pf_duplicate_of', 'compare' => 'EXISTS'),
			),
		));

		echo '<table class="widefat striped"><thead><tr><th>Produkt</th><th>Stav</th><th>Kontrola kvality / audit</th><th>Zdroj</th><th>Akcia</th></tr></thead><tbody>';
		foreach ($query->posts as $post) {
			$qa = json_decode((string) get_post_meta($post->ID, '_komarena_pf_qa_report', true), true);
			$audit = json_decode((string) get_post_meta($post->ID, '_komarena_pf_audit_issues', true), true);
			$manifest = json_decode((string) get_post_meta($post->ID, '_komarena_pf_manifest', true), true);
			$issues = array();
			if (!empty($qa['missing'])) {
				$issues = array_merge($issues, (array) $qa['missing']);
			}
			if (!empty($audit)) {
				$issues = array_merge($issues, (array) $audit);
			}
			$source = '';
			if (!empty($manifest['source_urls'][0])) {
				$source = $manifest['source_urls'][0];
			}
			$url = wp_nonce_url(add_query_arg(array('action' => 'komarena_pf_rebuild_product', 'product_id' => $post->ID), admin_url('admin-post.php')), 'komarena_pf_rebuild_' . $post->ID);
			$qa_url = wp_nonce_url(add_query_arg(array('action' => 'komarena_pf_run_product_qa', 'product_id' => $post->ID), admin_url('admin-post.php')), 'komarena_pf_run_product_qa_' . $post->ID);
			echo '<tr>';
			echo '<td><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html(get_the_title($post)) . '</a></td>';
			echo '<td><span class="kpf-badge">' . esc_html($this->visible_status(get_post_meta($post->ID, '_komarena_pf_status', true) ?: get_post_status($post))) . '</span></td>';
			echo '<td>' . esc_html(implode(', ', array_slice(array_unique($issues), 0, 5))) . '</td>';
			echo '<td>' . ($source ? '<a href="' . esc_url($source) . '" target="_blank" rel="noopener">zdroj</a>' : '') . '</td>';
			echo '<td><a class="button" href="' . esc_url($url) . '">Prebudovať</a> <a class="button" href="' . esc_url($qa_url) . '">Kontrola kvality</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function failed_queue_table() {
		$items = $this->plugin->queue->items(100);
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Produkt</th><th>Pokusy</th><th>Chyba</th><th>Aktualizované</th></tr></thead><tbody>';
		foreach ($items as $item) {
			if ('failed' !== $item['status']) {
				continue;
			}
			echo '<tr>';
			echo '<td>' . esc_html($item['id']) . '</td>';
			echo '<td>' . esc_html($item['product_name']) . '</td>';
			echo '<td>' . esc_html($item['attempts']) . '</td>';
			echo '<td>' . esc_html($item['last_error']) . '</td>';
			echo '<td>' . esc_html($item['updated_at']) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function memory_table() {
		$rows = $this->plugin->memory->recent(40);
		echo '<table class="widefat striped"><thead><tr><th>Typ</th><th>Názov</th><th>Kľúč</th><th>Istota</th><th>Aktualizované</th></tr></thead><tbody>';
		foreach ($rows as $row) {
			echo '<tr>';
			echo '<td>' . esc_html($row['memory_type']) . '</td>';
			echo '<td>' . esc_html($row['title']) . '</td>';
			echo '<td><code>' . esc_html($row['memory_key']) . '</code></td>';
			echo '<td>' . esc_html($row['confidence']) . '</td>';
			echo '<td>' . esc_html($row['updated_at']) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function site_layout_audit_table($audit, $page_id) {
		if (is_wp_error($audit)) {
			echo '<p>' . esc_html($audit->get_error_message()) . '</p>';
			return;
		}
		if (!$page_id || empty($audit)) {
			echo '<p>Domovská stránka zatiaľ nebola nájdená.</p>';
			return;
		}

		echo '<p><a class="button" href="' . esc_url(get_edit_post_link($page_id)) . '">Upraviť domovskú stránku</a> <a class="button" href="' . esc_url(get_permalink($page_id)) . '" target="_blank" rel="noopener">Otvoriť domovskú stránku</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Kontrola</th><th>Stav</th></tr></thead><tbody>';
		foreach ((array) ($audit['checks'] ?? array()) as $label => $pass) {
			echo '<tr><td>' . esc_html($label) . '</td><td><span class="kpf-badge">' . ($pass ? 'OK' : 'Pozor') . '</span></td></tr>';
		}
		echo '</tbody></table>';
		if (!empty($audit['missing'])) {
			echo '<p><strong>Chyba:</strong> ' . esc_html(implode(', ', (array) $audit['missing'])) . '</p>';
		}
	}

	private function plugin_audit_table($report, $with_checkboxes = false) {
		if (empty($report['plugins'])) {
			echo '<p>Zatiaľ neexistuje audit doplnkov. Spustite kontrolu doplnkov.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		if ($with_checkboxes) {
			echo '<th>Vypnúť</th>';
		}
		echo '<th>Doplnok</th><th>Stav</th><th>Kategória</th><th>Riziko</th><th>Odporúčanie</th><th>Signály</th></tr></thead><tbody>';
		foreach ((array) $report['plugins'] as $row) {
			$can_select = $with_checkboxes && !empty($row['active']) && empty($row['protected']) && (!empty($row['cleanup_candidate']) || 'high' === ($row['risk'] ?? 'low') || !empty($row['flags']));
			echo '<tr>';
			if ($with_checkboxes) {
				echo '<td>' . ($can_select ? '<input type="checkbox" name="plugins[]" value="' . esc_attr($row['basename']) . '" />' : '') . '</td>';
			}
			echo '<td><strong>' . esc_html($row['name']) . '</strong><br /><code>' . esc_html($row['basename']) . '</code><br />' . esc_html($row['version']) . '</td>';
			echo '<td><span class="kpf-badge">' . (empty($row['active']) ? 'neaktívny' : 'aktívny') . '</span></td>';
			echo '<td>' . esc_html($this->visible_plugin_category($row['category'])) . (!empty($row['protected']) ? ' / chránený' : '') . '</td>';
			echo '<td><span class="kpf-badge">' . esc_html($this->visible_risk($row['risk'])) . '</span></td>';
			echo '<td>' . esc_html($row['recommendation']) . '</td>';
			echo '<td>' . esc_html(implode(', ', (array) ($row['flags'] ?? array()))) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if (!empty($report['broken_active'])) {
			echo '<p><strong>Rozbité aktívne referencie:</strong> ' . esc_html(implode(', ', (array) $report['broken_active'])) . '</p>';
		}
	}

	private function builder_image_result($result) {
		if (empty($result) || !is_array($result)) {
			echo '<p>Zatiaľ neexistuje obrázkový ZIP. Spustite prvý krok tvorcu.</p>';
			return;
		}

		echo '<p><a class="button button-primary" href="' . esc_url($result['download_url'] ?? '') . '" target="_blank" rel="noopener">Stiahnuť obrázkový ZIP</a></p>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>Produkt</th><td>' . esc_html($result['product_name'] ?? '') . '</td></tr>';
		echo '<tr><th>URL skratka</th><td><code>' . esc_html($result['slug'] ?? '') . '</code></td></tr>';
		echo '<tr><th>Režim obrázkov</th><td><span class="kpf-badge">' . esc_html($this->visible_image_mode($result['image_mode'] ?? '')) . '</span></td></tr>';
		echo '<tr><th>Súbory</th><td>';
		foreach ((array) ($result['filenames'] ?? array()) as $filename) {
			echo '<code>' . esc_html($filename) . '</code> ';
		}
		echo '</td></tr>';
		echo '</tbody></table>';
		if (!empty($result['alt_texts'])) {
			echo '<h3>Alt texty</h3><ul class="kpf-missing">';
			foreach ((array) $result['alt_texts'] as $alt) {
				echo '<li>' . esc_html($alt) . '</li>';
			}
			echo '</ul>';
		}
		if (!empty($result['warnings'])) {
			echo '<h3>Upozornenia</h3><ul class="kpf-missing">';
			foreach ((array) $result['warnings'] as $warning) {
				echo '<li>' . esc_html($warning) . '</li>';
			}
			echo '</ul>';
		}
	}

	private function builder_csv_result($result) {
		if (empty($result) || !is_array($result)) {
			echo '<p>Zatiaľ neexistuje CSV balík. Vytvorte ho po nahratí obrázkov do knižnice médií.</p>';
			return;
		}

		echo '<p><a class="button button-primary" href="' . esc_url($result['download_url'] ?? '') . '" target="_blank" rel="noopener">Stiahnuť CSV + manifest ZIP</a> ';
		if (!empty($result['csv_url'])) {
			echo '<a class="button" href="' . esc_url($result['csv_url']) . '" target="_blank" rel="noopener">Otvoriť CSV</a>';
		}
		echo '</p>';
		echo '<table class="widefat striped"><tbody>';
		echo '<tr><th>Produkt</th><td>' . esc_html($result['product_name'] ?? '') . '</td></tr>';
		echo '<tr><th>SKU</th><td><code>' . esc_html($result['sku'] ?? '') . '</code></td></tr>';
		echo '<tr><th>EAN</th><td><code>' . esc_html($result['ean'] ?? '') . '</code></td></tr>';
		echo '<tr><th>Cena</th><td>' . esc_html($result['price'] ?? '') . '</td></tr>';
		echo '<tr><th>Kategórie</th><td>' . esc_html(implode(', ', (array) ($result['categories'] ?? array()))) . '</td></tr>';
		echo '<tr><th>Stav importu</th><td><span class="kpf-badge">' . esc_html($this->visible_status($result['import_status'] ?? 'publish')) . '</span></td></tr>';
		echo '</tbody></table>';
		if (!empty($result['image_urls'])) {
			echo '<h3>Finálne odkazy obrázkov</h3><ul class="kpf-missing">';
			foreach ((array) $result['image_urls'] as $variant => $url) {
				echo '<li><strong>' . esc_html($this->visible_image_variant($variant)) . ':</strong> <code>' . esc_html($url) . '</code></li>';
			}
			echo '</ul>';
		}
		if (!empty($result['qa']['missing'])) {
			echo '<h3>Upozornenia kontroly kvality</h3><ul class="kpf-missing">';
			foreach ((array) $result['qa']['missing'] as $missing) {
				echo '<li>' . esc_html($missing) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><strong>Poznámka k importu:</strong> Aktualizáciu podľa SKU zapnite iba vtedy, ak produkt už vo WooCommerce existuje.</p>';
	}

	private function task_types_table($types) {
		echo '<table class="widefat striped"><thead><tr><th>Názov</th><th>Stav</th><th>Popis</th></tr></thead><tbody>';
		foreach ($types as $type => $definition) {
			echo '<tr>';
			echo '<td>' . esc_html($definition['label'] ?? $type) . '</td>';
			echo '<td><span class="kpf-badge">' . (empty($definition['future']) ? 'aktívne' : 'rozširujúci bod') . '</span></td>';
			echo '<td>' . esc_html($definition['description'] ?? '') . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function tasks_table($items) {
		$types = $this->plugin->tasks->registered_task_types();
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Typ</th><th>Názov</th><th>Stav</th><th>Priorita</th><th>Pokusy</th><th>Plán</th><th>Chyba</th></tr></thead><tbody>';
		foreach ($items as $item) {
			$type = $item['task_type'];
			$type_label = isset($types[$type]['label']) ? $types[$type]['label'] : $type;
			echo '<tr>';
			echo '<td>' . esc_html($item['id']) . '</td>';
			echo '<td>' . esc_html($type_label) . '</td>';
			echo '<td>' . esc_html($item['title']) . '</td>';
			echo '<td><span class="kpf-badge">' . esc_html($this->visible_status($item['status'])) . '</span></td>';
			echo '<td>' . esc_html($item['priority']) . '</td>';
			echo '<td>' . esc_html($item['attempts']) . '</td>';
			echo '<td>' . esc_html($item['scheduled_at']) . '</td>';
			echo '<td>' . esc_html($item['last_error']) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function woocommerce_warning() {
		if (!$this->plugin->has_woocommerce()) {
			echo '<div class="notice notice-warning inline"><p>WooCommerce nie je aktívny. Factory vie zobraziť UI, ale produkty sa vytvoria až po aktivácii WooCommerce.</p></div>';
		}
	}

	private function next_cron_label($hook) {
		$next = wp_next_scheduled($hook);
		if (!$next) {
			return 'nenaplánované';
		}

		return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next);
	}

	private function guard() {
		if (!current_user_can($this->plugin->capability())) {
			wp_die(esc_html__('Nemáte oprávnenie.', 'komarena-product-factory'));
		}
	}

	private function guard_action($nonce_action, $nonce_name = '_wpnonce') {
		$this->guard();
		check_admin_referer($nonce_action, $nonce_name);
	}

	private function redirect($page, $args = array()) {
		wp_safe_redirect(add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php')));
		exit;
	}

	private function textarea_urls($raw) {
		if (!is_scalar($raw)) {
			return array();
		}

		$urls = array();
		foreach (preg_split('/\r\n|\r|\n/', (string) wp_unslash($raw)) as $url) {
			$url = esc_url_raw(trim($url));
			if ($url) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	private function task_payload_from_request($type) {
		$payload = array();

		if (isset($_POST['task_limit']) && '' !== (string) $_POST['task_limit']) {
			$payload['limit'] = absint($_POST['task_limit']);
		}

		if ('supplier_import' === $type && isset($_POST['supplier_urls'])) {
			$payload['text'] = sanitize_textarea_field(wp_unslash($_POST['supplier_urls']));
		}

		if (isset($_POST['product_name'])) {
			$payload['product_name'] = sanitize_text_field(wp_unslash($_POST['product_name']));
		}
		if (isset($_POST['one_image_url'])) {
			$payload['one_image_url'] = esc_url_raw(wp_unslash($_POST['one_image_url']));
		}
		if (isset($_POST['source_urls'])) {
			$payload['source_urls'] = $this->textarea_urls($_POST['source_urls']);
		}
		if (isset($_POST['image_urls'])) {
			$payload['image_sources'] = $this->textarea_urls($_POST['image_urls']);
		}

		$raw_json = isset($_POST['payload_json']) ? trim((string) wp_unslash($_POST['payload_json'])) : '';
		if ('' !== $raw_json) {
			$decoded = json_decode($raw_json, true);
			if (!is_array($decoded)) {
				return new WP_Error('komarena_pf_bad_task_payload', 'Dáta úlohy vo formáte JSON nie sú platný objekt alebo pole.');
			}
			$payload = array_merge($payload, $decoded);
		}

		if (isset($_POST['product_id']) && absint($_POST['product_id'])) {
			$payload['product_id'] = absint($_POST['product_id']);
		}

		return $payload;
	}
}
