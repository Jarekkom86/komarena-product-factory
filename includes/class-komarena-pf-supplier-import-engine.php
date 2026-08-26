<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Supplier_Import_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function import_from_text($raw_text) {
		if (!is_scalar($raw_text)) {
			return array('created' => 0, 'failed' => 0, 'items' => array());
		}

		$created = 0;
		$failed = 0;
		$items = array();
		$limit = (int) $this->plugin->settings->get('supplier_import_limit', 20);

		foreach (preg_split('/\r\n|\r|\n/', (string) wp_unslash($raw_text)) as $line) {
			if ($created + $failed >= $limit) {
				break;
			}

			$line = trim($line);
			if ('' === $line) {
				continue;
			}

			$parsed_line = $this->parse_line($line);
			if (is_wp_error($parsed_line)) {
				$failed++;
				$items[] = array('line' => $line, 'error' => $parsed_line->get_error_message());
				continue;
			}

			$supplier = $this->fetch_supplier_data($parsed_line['url']);
			if (is_wp_error($supplier)) {
				$failed++;
				$items[] = array('line' => $line, 'error' => $supplier->get_error_message());
				continue;
			}

			if ($parsed_line['name']) {
				$supplier['name'] = $parsed_line['name'];
			}

			$name = sanitize_text_field($supplier['name'] ?: $supplier['title'] ?: $parsed_line['url']);
			$image_sources = $this->image_sources_from_supplier($supplier);

			$queue_id = $this->plugin->queue->insert($name, array(
				'source_urls'   => array($parsed_line['url']),
				'supplier_data' => $supplier,
				'image_sources' => $image_sources,
				'sku'           => $supplier['sku'] ?? '',
				'ean'           => $supplier['ean'] ?? '',
			));

			$created++;
			$items[] = array('queue_id' => $queue_id, 'name' => $name, 'url' => $parsed_line['url']);
		}

		if ($created > 0) {
			$this->plugin->queue->schedule_soon();
		}

		$this->plugin->logger->log('info', 'Supplier import finished', 'supplier', array(
			'created' => $created,
			'failed'  => $failed,
			'items'   => $items,
		));

		return array('created' => $created, 'failed' => $failed, 'items' => $items);
	}

	public function fetch_supplier_data($url) {
		$url = esc_url_raw(trim($url));
		if (!$url) {
			return new WP_Error('komarena_pf_supplier_url', 'Neplatna URL dodavatela.');
		}

		$response = wp_safe_remote_get($url, array(
			'timeout'             => (int) $this->plugin->settings->get('supplier_fetch_timeout', 12),
			'limit_response_size' => 700000,
			'user-agent'          => 'KomArena Produktovy Agent/' . KOMARENA_PF_VERSION,
		));

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 400) {
			return new WP_Error('komarena_pf_supplier_http', 'Dodavatelska URL vratila HTTP ' . $code . '.');
		}

		$html = (string) wp_remote_retrieve_body($response);
		$data = $this->parse_html($html, $url);
		$data['url'] = $url;
		$data['fetched_at'] = current_time('mysql');

		return $data;
	}

	private function parse_line($line) {
		$parts = array_map('trim', explode('|', $line));
		if (count($parts) === 1) {
			$url = esc_url_raw($parts[0]);
			return $url ? array('name' => '', 'url' => $url) : new WP_Error('komarena_pf_supplier_line', 'Riadok neobsahuje platnu URL.');
		}

		$name = sanitize_text_field($parts[0]);
		$url = esc_url_raw($parts[1]);
		if (!$url) {
			return new WP_Error('komarena_pf_supplier_line', 'Riadok neobsahuje platnu URL.');
		}

		return array('name' => $name, 'url' => $url);
	}

	private function parse_html($html, $url) {
		$json_product = $this->json_ld_product($html);
		$title = $json_product['name'] ?? $this->meta($html, 'property', 'og:title');
		if (!$title) {
			$title = $this->title($html);
		}

		$description = $json_product['description'] ?? $this->meta($html, 'name', 'description');
		$images = array();
		if (!empty($json_product['image'])) {
			$images = is_array($json_product['image']) ? $json_product['image'] : array($json_product['image']);
		}
		$og_image = $this->meta($html, 'property', 'og:image');
		if ($og_image) {
			$images[] = $og_image;
		}

		$price = $this->product_price($json_product);
		$sku = $json_product['sku'] ?? '';
		$ean = $json_product['gtin13'] ?? $json_product['gtin'] ?? '';
		if (!$ean && preg_match('/\b(2998\d{9}|\d{13})\b/', $html, $m)) {
			$ean = $m[1];
		}

		return array(
			'name'        => sanitize_text_field(wp_strip_all_tags($title)),
			'title'       => sanitize_text_field(wp_strip_all_tags($title)),
			'description' => sanitize_textarea_field(wp_strip_all_tags($description)),
			'price'       => $price ? (float) preg_replace('/[^0-9.,]/', '', str_replace(',', '.', (string) $price)) : 0,
			'sku'         => sanitize_text_field($sku),
			'ean'         => preg_replace('/\D+/', '', (string) $ean),
			'image_urls'  => array_values(array_unique(array_filter(array_map('esc_url_raw', $this->absolute_urls($images, $url))))),
			'raw_source'  => 'jsonld_or_meta',
		);
	}

	private function json_ld_product($html) {
		if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches)) {
			return array();
		}

		foreach ($matches[1] as $json) {
			$decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES), true);
			$product = $this->find_product_node($decoded);
			if (!empty($product)) {
				return $product;
			}
		}

		return array();
	}

	private function product_price($product) {
		if (!is_array($product)) {
			return '';
		}
		if (!empty($product['price'])) {
			return $product['price'];
		}
		if (empty($product['offers'])) {
			return '';
		}

		$offers = $product['offers'];
		if (isset($offers['price'])) {
			return $offers['price'];
		}
		if (is_array($offers)) {
			foreach ($offers as $offer) {
				if (is_array($offer) && isset($offer['price'])) {
					return $offer['price'];
				}
			}
		}

		return '';
	}

	private function find_product_node($node) {
		if (!is_array($node)) {
			return array();
		}

		$type = $node['@type'] ?? '';
		if (is_array($type)) {
			$type = implode(' ', $type);
		}
		if (is_string($type) && false !== stripos($type, 'Product')) {
			return $node;
		}

		foreach (array('@graph', 'itemListElement') as $key) {
			if (!empty($node[$key]) && is_array($node[$key])) {
				foreach ($node[$key] as $child) {
					$found = $this->find_product_node($child);
					if ($found) {
						return $found;
					}
				}
			}
		}

		foreach ($node as $child) {
			if (is_array($child)) {
				$found = $this->find_product_node($child);
				if ($found) {
					return $found;
				}
			}
		}

		return array();
	}

	private function meta($html, $attribute, $value) {
		$pattern = '/<meta[^>]+'. preg_quote($attribute, '/') .'=["\']'. preg_quote($value, '/') .'["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i';
		if (preg_match($pattern, $html, $m)) {
			return html_entity_decode($m[1], ENT_QUOTES);
		}

		$pattern = '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+'. preg_quote($attribute, '/') .'=["\']'. preg_quote($value, '/') .'["\'][^>]*>/i';
		if (preg_match($pattern, $html, $m)) {
			return html_entity_decode($m[1], ENT_QUOTES);
		}

		return '';
	}

	private function title($html) {
		if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
			return html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES);
		}

		return '';
	}

	private function absolute_urls($urls, $base_url) {
		$out = array();
		$parts = wp_parse_url($base_url);
		$origin = (!empty($parts['scheme']) && !empty($parts['host'])) ? $parts['scheme'] . '://' . $parts['host'] : '';
		foreach ((array) $urls as $url) {
			$url = trim((string) $url);
			if ('' === $url) {
				continue;
			}
			if (0 === strpos($url, '//')) {
				$url = ($parts['scheme'] ?? 'https') . ':' . $url;
			} elseif (0 === strpos($url, '/') && $origin) {
				$url = $origin . $url;
			}
			$out[] = $url;
		}

		return $out;
	}

	private function image_sources_from_supplier($supplier) {
		$variants = array('main', 'angle', 'detail', 'technical');
		$out = array();
		$images = $supplier['image_urls'] ?? array();
		foreach ($variants as $index => $variant) {
			if (!empty($images[$index])) {
				$out[$variant] = $images[$index];
			}
		}

		return $out;
	}
}
