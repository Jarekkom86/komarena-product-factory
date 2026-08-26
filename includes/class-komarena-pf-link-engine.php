<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Link_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function links($research = array()) {
		$links = array(
			array('label' => 'KomArena.sk', 'url' => home_url('/')),
			array('label' => 'Home Assistant', 'url' => $this->plugin->settings->get('home_assistant_url', home_url('/?s=Home+Assistant'))),
			array('label' => 'ESP / ESPHome', 'url' => $this->plugin->settings->get('esphome_url', home_url('/?s=ESPHome'))),
			array('label' => 'Návody', 'url' => $this->search_url('navody')),
			array('label' => 'Senzory', 'url' => $this->search_url('senzory')),
			array('label' => 'Napajanie', 'url' => $this->search_url('napajanie')),
			array('label' => 'Vyvojove dosky', 'url' => $this->search_url('vyvojove dosky')),
			array('label' => 'Kable', 'url' => $this->search_url('kable')),
			array('label' => 'Moduly', 'url' => $this->search_url('moduly')),
		);

		if (!empty($research['categories'])) {
			foreach ($research['categories'] as $category) {
				$url = $this->category_url($category);
				$links[] = array('label' => $category, 'url' => $url);
			}
		}

		if (!empty($research['normalized_name'])) {
			$links[] = array('label' => 'Hladat podobne', 'url' => $this->search_url($research['normalized_name']));
		}

		$deduped = array();
		foreach ($links as $link) {
			$key = sanitize_title($link['label']) . '|' . $link['url'];
			$deduped[$key] = $link;
		}

		return array_values($deduped);
	}

	public function pills_html($links) {
		$html = '<div class="komarena-link-pills" style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;">';

		foreach ($links as $link) {
			$html .= sprintf(
				'<a href="%s" style="display:inline-block;border:1px solid #0f9f9a;background:#e6fffb;color:#075f5b;border-radius:999px;padding:7px 12px;text-decoration:none;font-weight:600;font-size:13px;">%s</a>',
				esc_url($link['url']),
				esc_html($link['label'])
			);
		}

		$html .= '</div>';
		return $html;
	}

	public function product_search_pills($terms) {
		$links = array();
		foreach ((array) $terms as $term) {
			$term = sanitize_text_field($term);
			if ($term) {
				$links[] = array('label' => $term, 'url' => $this->search_url($term));
			}
		}

		return $this->pills_html($links);
	}

	private function category_url($category) {
		$term = term_exists($category, 'product_cat');
		if ($term && !is_wp_error($term)) {
			$link = get_term_link((int) $term['term_id'], 'product_cat');
			if (!is_wp_error($link)) {
				return $link;
			}
		}

		return $this->search_url($category);
	}

	private function search_url($query) {
		return add_query_arg(
			array('s' => $query, 'post_type' => 'product'),
			home_url('/')
		);
	}
}
