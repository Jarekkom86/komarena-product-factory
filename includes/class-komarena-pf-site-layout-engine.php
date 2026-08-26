<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Site_Layout_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function register_shortcodes() {
		if (!shortcode_exists('komarena_sidebar_panel')) {
			add_shortcode('komarena_sidebar_panel', array($this, 'sidebar_shortcode'));
		}
	}

	public function enqueue_assets() {
		wp_enqueue_style('komarena-pf-frontend', KOMARENA_PF_URL . 'assets/frontend.css', array(), KOMARENA_PF_VERSION);
	}

	public function shortcode_available() {
		return shortcode_exists('komarena_sidebar_panel');
	}

	public function sidebar_shortcode($atts = array()) {
		$atts = shortcode_atts(array(
			'title' => 'KomArena',
		), $atts, 'komarena_sidebar_panel');

		$quick_links = apply_filters('komarena_pf_sidebar_quick_links', array(
			array('label' => 'Home Assistant', 'url' => home_url('/?s=Home+Assistant')),
			array('label' => 'ESP / ESPHome', 'url' => home_url('/?s=ESPHome')),
			array('label' => 'Senzory', 'url' => home_url('/?s=senzory')),
			array('label' => 'Napajanie', 'url' => home_url('/?s=napajanie')),
			array('label' => 'Vyvojove dosky', 'url' => home_url('/?s=vyvojove+dosky')),
			array('label' => 'Kable', 'url' => home_url('/?s=kable')),
			array('label' => 'Moduly', 'url' => home_url('/?s=moduly')),
		));

		ob_start();
		?>
		<aside class="ka-sidebar-panel" aria-label="KomArena sidebar">
			<div class="ka-sidebar-panel__head">
				<strong><?php echo esc_html($atts['title']); ?></strong>
				<span>rychly vyber</span>
			</div>
			<form class="ka-sidebar-search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
				<label class="screen-reader-text" for="ka-sidebar-search-field">Hladat na KomArena</label>
				<input id="ka-sidebar-search-field" type="search" name="s" placeholder="Hľadať produkt alebo návod" />
				<input type="hidden" name="post_type" value="product" />
				<button type="submit">Hladat</button>
			</form>
			<nav class="ka-sidebar-pills" aria-label="KomArena odporucane odkazy">
				<?php foreach ($quick_links as $link) : ?>
					<a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php $this->render_product_categories(); ?>
			<?php $this->render_recent_products(); ?>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	public function apply_homepage_sidebar_layout($payload = array()) {
		$page_id = $this->resolve_page_id($payload);
		if (is_wp_error($page_id)) {
			return $page_id;
		}

		$post = get_post($page_id);
		if (!$post || 'page' !== $post->post_type) {
			return new WP_Error('komarena_pf_layout_page_missing', 'Homepage stranka neexistuje.');
		}

		$content = (string) $post->post_content;
		$audit_before = $this->audit_content($content, $page_id);
		$core_layout_ok = !empty($audit_before['checks']['layout_wrapper']) && !empty($audit_before['checks']['main_content_slot']) && !empty($audit_before['checks']['sidebar_slot']) && !empty($audit_before['checks']['sidebar_shortcode']);
		if (empty($payload['force']) && $core_layout_ok) {
			if (empty($audit_before['checks']['backup_available'])) {
				$this->backup_page_content($page_id, $content);
				$audit_before = $this->audit_content($content, $page_id);
				update_post_meta($page_id, '_komarena_pf_homepage_sidebar_audit', wp_json_encode($audit_before));
			}
			return array(
				'status'    => 'skipped',
				'message'   => 'Homepage uz obsahuje KomArena sidebar layout.',
				'page_id'   => $page_id,
				'audit'     => $audit_before,
				'edit_link' => get_edit_post_link($page_id, ''),
			);
		}

		$this->backup_page_content($page_id, $content);
		$wrapped = $this->wrap_content($content);

		if (!empty($payload['dry_run'])) {
			return array(
				'status'  => 'needs_review',
				'message' => 'Dry-run hotovy. Homepage nebola zmenena.',
				'page_id' => $page_id,
				'audit'   => $this->audit_content($wrapped, $page_id),
			);
		}

		$result = wp_update_post(array(
			'ID'           => $page_id,
			'post_content' => $wrapped,
		), true);

		if (is_wp_error($result)) {
			return $result;
		}

		update_post_meta($page_id, '_komarena_pf_homepage_sidebar_layout', wp_json_encode(array(
			'applied_at'    => current_time('mysql'),
			'agent_version' => KOMARENA_PF_VERSION,
			'shortcode'     => '[komarena_sidebar_panel]',
		)));

		$audit = $this->audit_homepage_sidebar_layout(array('page_id' => $page_id));
		$this->plugin->logger->log('info', 'Homepage sidebar layout applied', 'site_layout', $audit, null, $page_id);

		return array(
			'status'    => empty($audit['missing']) ? 'done' : 'needs_review',
			'message'   => 'Homepage bola obalena layoutom s pravym sidebar panelom.',
			'page_id'   => $page_id,
			'audit'     => $audit,
			'edit_link' => get_edit_post_link($page_id, ''),
			'view_link' => get_permalink($page_id),
		);
	}

	public function audit_homepage_sidebar_layout($payload = array()) {
		$page_id = $this->resolve_page_id($payload);
		if (is_wp_error($page_id)) {
			return $page_id;
		}

		$post = get_post($page_id);
		if (!$post || 'page' !== $post->post_type) {
			return new WP_Error('komarena_pf_layout_page_missing', 'Homepage stranka neexistuje.');
		}

		$audit = $this->audit_content((string) $post->post_content, $page_id);
		$audit['status'] = empty($audit['missing']) ? 'done' : 'needs_review';
		$audit['page_id'] = $page_id;
		$audit['edit_link'] = get_edit_post_link($page_id, '');
		$audit['view_link'] = get_permalink($page_id);

		update_post_meta($page_id, '_komarena_pf_homepage_sidebar_audit', wp_json_encode($audit));
		$this->plugin->logger->log(empty($audit['missing']) ? 'info' : 'warning', 'Homepage sidebar layout audit finished', 'site_layout', $audit, null, $page_id);

		return $audit;
	}

	public function restore_homepage_sidebar_backup($payload = array()) {
		$page_id = $this->resolve_page_id($payload);
		if (is_wp_error($page_id)) {
			return $page_id;
		}

		$backup = json_decode((string) get_post_meta($page_id, '_komarena_pf_homepage_sidebar_backup', true), true);
		if (empty($backup['content'])) {
			return new WP_Error('komarena_pf_layout_backup_missing', 'Pre homepage neexistuje zaloha obsahu.');
		}

		$result = wp_update_post(array(
			'ID'           => $page_id,
			'post_content' => (string) $backup['content'],
		), true);

		if (is_wp_error($result)) {
			return $result;
		}

		update_post_meta($page_id, '_komarena_pf_homepage_sidebar_restored_at', current_time('mysql'));
		$this->plugin->logger->log('info', 'Homepage sidebar layout restored from backup', 'site_layout', array('page_id' => $page_id), null, $page_id);

		return array(
			'status'    => 'done',
			'message'   => 'Homepage bola vratena zo zalohy pred sidebar layoutom.',
			'page_id'   => $page_id,
			'edit_link' => get_edit_post_link($page_id, ''),
		);
	}

	public function default_homepage_id() {
		$page_id = (int) get_option('page_on_front');
		if ($page_id) {
			return $page_id;
		}

		foreach (array('domov', 'home', 'homepage') as $slug) {
			$page = get_page_by_path($slug);
			if ($page && 'page' === $page->post_type) {
				return (int) $page->ID;
			}
		}

		$pages = get_pages(array(
			'number'      => 1,
			'post_status' => 'publish',
			'sort_column' => 'menu_order,post_title',
		));

		return !empty($pages[0]->ID) ? (int) $pages[0]->ID : 0;
	}

	private function resolve_page_id($payload) {
		$page_id = absint($payload['page_id'] ?? 0);
		if (!$page_id) {
			$page_id = $this->default_homepage_id();
		}
		if (!$page_id) {
			return new WP_Error('komarena_pf_layout_page_missing', 'Nepodarilo sa najst homepage stranku.');
		}

		return $page_id;
	}

	private function backup_page_content($page_id, $content) {
		$existing = get_post_meta($page_id, '_komarena_pf_homepage_sidebar_backup', true);
		if ($existing) {
			return;
		}

		update_post_meta($page_id, '_komarena_pf_homepage_sidebar_backup', wp_json_encode(array(
			'content'       => $content,
			'saved_at'      => current_time('mysql'),
			'agent_version' => KOMARENA_PF_VERSION,
		)));
	}

	private function wrap_content($content) {
		$content = trim((string) $content);

		return '<div class="ka-home-with-sidebar" data-komarena-agent-layout="homepage-sidebar">' . "\n" .
			'  <div class="ka-home-main-content">' . "\n" .
			$content . "\n" .
			'  </div>' . "\n\n" .
			'  <aside class="ka-home-sidebar-slot">' . "\n" .
			'    [komarena_sidebar_panel]' . "\n" .
			'  </aside>' . "\n" .
			'</div>';
	}

	private function audit_content($content, $page_id) {
		$checks = array(
			'layout_wrapper'       => false !== strpos($content, 'ka-home-with-sidebar'),
			'main_content_slot'    => false !== strpos($content, 'ka-home-main-content'),
			'sidebar_slot'         => false !== strpos($content, 'ka-home-sidebar-slot'),
			'sidebar_shortcode'    => has_shortcode($content, 'komarena_sidebar_panel') || false !== strpos($content, '[komarena_sidebar_panel'),
			'shortcode_registered' => $this->shortcode_available(),
			'backup_available'     => '' !== (string) get_post_meta($page_id, '_komarena_pf_homepage_sidebar_backup', true),
		);

		$labels = array(
			'layout_wrapper'       => 'Chyba obal .ka-home-with-sidebar.',
			'main_content_slot'    => 'Chyba lavy hlavny obsah .ka-home-main-content.',
			'sidebar_slot'         => 'Chyba pravy slot .ka-home-sidebar-slot.',
			'sidebar_shortcode'    => 'Chyba shortcode [komarena_sidebar_panel].',
			'shortcode_registered' => 'Shortcode [komarena_sidebar_panel] nie je registrovany.',
			'backup_available'     => 'Chyba zaloha povodneho homepage obsahu.',
		);

		$missing = array();
		foreach ($checks as $key => $passed) {
			if (!$passed) {
				$missing[] = $labels[$key];
			}
		}

		return array(
			'checks'     => $checks,
			'missing'    => $missing,
			'checked_at' => current_time('mysql'),
		);
	}

	private function render_product_categories() {
		if (!taxonomy_exists('product_cat')) {
			return;
		}

		$terms = get_terms(array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'number'     => 6,
			'orderby'    => 'count',
			'order'      => 'DESC',
		));

		if (is_wp_error($terms) || empty($terms)) {
			return;
		}

		echo '<div class="ka-sidebar-section"><h3>Kategorie</h3><div class="ka-sidebar-list">';
		foreach ($terms as $term) {
			echo '<a href="' . esc_url(get_term_link($term)) . '">' . esc_html($term->name) . '</a>';
		}
		echo '</div></div>';
	}

	private function render_recent_products() {
		$query = new WP_Query(array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => 3,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		));

		if (!$query->have_posts()) {
			wp_reset_postdata();
			return;
		}

		echo '<div class="ka-sidebar-section"><h3>Novinky</h3><div class="ka-sidebar-products">';
		while ($query->have_posts()) {
			$query->the_post();
			$product_id = get_the_ID();
			echo '<a class="ka-sidebar-product" href="' . esc_url(get_permalink($product_id)) . '">';
			if (has_post_thumbnail($product_id)) {
				echo get_the_post_thumbnail($product_id, 'thumbnail');
			}
			echo '<span>' . esc_html(get_the_title($product_id)) . '</span>';
			echo '</a>';
		}
		echo '</div></div>';
		wp_reset_postdata();
	}
}
