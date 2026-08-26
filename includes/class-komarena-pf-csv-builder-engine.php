<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_CSV_Builder_Engine {
	const STANDARD_VERSION = 'komarena-product-builder-csv-1.8.1-canonical-2978';

	private $plugin;
	private $variants = array('main', 'angle', 'detail', 'technical');

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function standard() {
		return array(
			'version' => self::STANDARD_VERSION,
			'workflow' => array(
				'Najprv overiť produkt z výrobcu, dokumentácie, datasheetu, overeného distribútora alebo internej KomArena referencie.',
				'Najprv vytvoriť iba ZIP so štyrmi obrázkami a presnými názvami súborov.',
				'CSV vytvoriť až po vložení odkazu jedného nahratého obrázka z knižnice médií WordPressu.',
				'Dlhý popis musí byť WooCommerce inline-safe HTML bez blokov štýlov, JavaScriptu a externého CSS.',
				'Technické parametre sa nesmú vymýšľať; pri neistote použiť opatrnú formuláciu.',
			),
			'csv_columns' => $this->csv_columns(),
			'image_variants' => $this->variants,
			'image_modes' => array('REAL_STANDARDIZED', 'REAL_PARTIAL'),
			'review_only_image_modes' => array('ILLUSTRATED_FALLBACK'),
			'allowed_categories' => $this->allowed_categories(),
		);
	}

	public function create_image_package($product_name, $options = array()) {
		$name = sanitize_text_field($product_name);
		if ('' === $name) {
			return new WP_Error('komarena_pf_builder_name_missing', 'Chýba názov produktu pre obrázkový ZIP.');
		}
		if (!class_exists('ZipArchive')) {
			return new WP_Error('komarena_pf_builder_zip_missing', 'PHP ZipArchive nie je dostupné, ZIP balík sa nedá vytvoriť.');
		}
		if (!function_exists('imagecreatetruecolor')) {
			return new WP_Error('komarena_pf_builder_gd_missing', 'PHP GD nie je dostupné, reálne obrázky sa nedajú štandardizovať.');
		}

		$source_urls = $this->source_urls_from_options($options);
		$research = $this->plugin->research->research($name, array('source_urls' => $source_urls));
		$slug = $this->stable_slug($research, $name);
		$dir = $this->artifact_dir($slug, 'images');
		if (is_wp_error($dir)) {
			return $dir;
		}

		$real_sources = $this->real_image_sources($research, $options);
		$use_illustrated_fallback = count($real_sources) < 4;
		if ($use_illustrated_fallback && !$this->plugin->settings->get('allow_illustrated_image_fallback', 0)) {
			return new WP_Error('komarena_pf_builder_real_images_missing', 'Agent nenašiel 4 reálne/originálne zdrojové obrázky. Produkt zostáva na ručnú kontrolu, kým sa nedoplnia originálne produktové fotografie alebo overené reálne produktové podklady.');
		}

		$image_mode = $this->image_mode($options, $real_sources);
		$files = array();
		$filenames = array();
		$alt_texts = $this->alt_texts($research, array());
		foreach ($this->variants as $variant) {
			$filename = sprintf('komarena-%s-%s.jpg', $slug, $variant);
			$path = trailingslashit($dir['path']) . $filename;
			$created = !empty($real_sources[$variant])
				? $this->standardize_real_image($real_sources[$variant], $path)
				: $this->generate_builder_image($path, $research, $variant, $image_mode);
			if (is_wp_error($created)) {
				return $created;
			}
			$files[] = $path;
			$filenames[] = $filename;
		}

		$zip_name = sprintf('komarena-%s-images.zip', $slug);
		$zip_path = trailingslashit($dir['path']) . $zip_name;
		$zip_result = $this->zip_files($zip_path, $files);
		if (is_wp_error($zip_result)) {
			return $zip_result;
		}

		$warnings = (array) ($research['warnings'] ?? array());
		if ($use_illustrated_fallback) {
			$warnings[] = 'Agent nenašiel dostatok reálnych fotiek a použil farebnú kreslenú technickú zálohu iba pre kontrolu. Tento výstup nie je originálna produktová fotografia a nesmie ísť do pripravenia na publikovanie.';
		} else {
			$warnings[] = 'Obrázky boli štandardizované z reálnych zdrojov. Pred publikovaním odporúčame vizuálne overiť, že neobsahujú cudzí vodoznak alebo predajné logo.';
		}
		$image_provenance = $this->builder_image_provenance($real_sources, $image_mode);

		$result = array(
			'status' => 'needs_review',
			'message' => 'Obrázkový ZIP je pripravený. Nahrajte 4 obrázky do knižnice médií WordPressu a vložte odkaz jedného z nich do CSV kroku.',
			'product_name' => $name,
			'slug' => $slug,
			'image_mode' => $image_mode,
			'download_url' => trailingslashit($dir['url']) . $zip_name,
			'zip_path' => $zip_path,
			'filenames' => $filenames,
			'alt_texts' => $alt_texts,
			'source_urls' => $research['source_urls'] ?? array(),
			'image_provenance' => $image_provenance,
			'warnings' => array_values(array_unique(array_filter($warnings))),
			'manifest' => array(
				'standard' => self::STANDARD_VERSION,
				'product_name' => $name,
				'slug' => $slug,
				'image_mode' => $image_mode,
				'source_urls' => $research['source_urls'] ?? array(),
				'image_source_urls' => $real_sources,
				'image_provenance' => $image_provenance,
				'filenames' => $filenames,
				'alt_texts' => $alt_texts,
				'created_at' => current_time('mysql'),
				'agent_version' => KOMARENA_PF_VERSION,
				'next_step' => 'Nahrajte obrázky do knižnice médií WordPressu a vložte jeden výsledný odkaz obrázka do CSV tvorcu.',
				'warnings' => array_values(array_unique(array_filter($warnings))),
			),
		);

		$result = apply_filters('komarena_pf_builder_image_package_result', $result, $research, $options, $this);
		update_option('komarena_pf_last_builder_image_package', $result, false);
		$this->plugin->logger->log('info', 'CSV tvorca vytvoril obrázkový balík', 'csv_tvorca', $result);

		return $result;
	}

	public function create_csv_package($product_name, $one_image_url, $options = array()) {
		$name = sanitize_text_field($product_name);
		if ('' === $name) {
			return new WP_Error('komarena_pf_builder_name_missing', 'Chýba názov produktu pre CSV.');
		}
		if (!class_exists('ZipArchive')) {
			return new WP_Error('komarena_pf_builder_zip_missing', 'PHP ZipArchive nie je dostupné, CSV ZIP balík sa nedá vytvoriť.');
		}

		$source_urls = $this->source_urls_from_options($options);
		$research = $this->plugin->research->research($name, array('source_urls' => $source_urls));
		$derived = $this->derive_image_urls($one_image_url, $name);
		if (is_wp_error($derived)) {
			return $derived;
		}

		$slug = $derived['slug'] ? $derived['slug'] : $this->stable_slug($research, $name);
		$image_urls = $derived['urls'];
		$price_data = $this->plugin->pricing->price($research);
		$sku = $this->plugin->sku_ean->sku($research['normalized_name'] ?? $name, $options['sku'] ?? '');
		$ean = $this->plugin->sku_ean->ean($options['ean'] ?? '');
		$categories = $this->normalize_categories($research['categories'] ?? array());
		$alt_texts = $this->alt_texts($research, $image_urls);
		$short_description = $this->short_description($research);
		$description = $this->long_description($research, $image_urls, $price_data, $categories);
		$yoast_title = $this->yoast_title($research);
		$yoast_desc = $this->yoast_description($research);

		$row = array(
			'Type' => 'simple',
			'Name' => $research['normalized_name'] ?? $name,
			'Short description' => $short_description,
			'Description' => $description,
			'SKU' => $sku,
			'Regular price' => $price_data['price'],
			'Categories' => implode(', ', $categories),
			'EAN' => $ean,
			'Stock' => (string) max(0, (int) $this->plugin->settings->get('default_stock', 10)),
			'Status' => 'publish',
			'Tax status' => 'taxable',
			'Images' => implode(', ', array_values($image_urls)),
			'Image alt text' => implode(', ', array_values($alt_texts)),
			'Meta: _yoast_wpseo_title' => $yoast_title,
			'Meta: _yoast_wpseo_metadesc' => $yoast_desc,
		);
		$row = apply_filters('komarena_pf_builder_csv_row', $row, $research, $image_urls, $options, $this);

		$dir = $this->artifact_dir($slug, 'csv');
		if (is_wp_error($dir)) {
			return $dir;
		}

		$csv_name = sprintf('komarena-%s-woocommerce.csv', $slug);
		$manifest_name = sprintf('komarena-%s-manifest.json', $slug);
		$zip_name = sprintf('komarena-%s-csv.zip', $slug);
		$csv_path = trailingslashit($dir['path']) . $csv_name;
		$manifest_path = trailingslashit($dir['path']) . $manifest_name;
		$zip_path = trailingslashit($dir['path']) . $zip_name;

		$written = $this->write_csv($csv_path, $row);
		if (is_wp_error($written)) {
			return $written;
		}

		$qa = $this->csv_qa($row, $image_urls, $description, $categories, $research);
		$manifest = array(
			'standard' => self::STANDARD_VERSION,
			'product_name' => $name,
			'slug' => $slug,
			'source_urls' => $research['source_urls'] ?? array(),
			'source_evidence' => $research['source_evidence'] ?? array(),
			'fact_verification' => $research['fact_verification'] ?? array(),
			'verified_facts_policy' => $research['verified_facts_policy'] ?? '',
			'image_mode' => $options['image_mode'] ?? 'MEDIA_LIBRARY_URL_DERIVED',
			'image_urls' => $image_urls,
			'sku' => $sku,
			'ean' => $ean,
			'price' => $price_data['price'],
			'price_reasoning' => $price_data['reasoning'] ?? array(),
			'categories' => $categories,
			'category_reasoning' => 'Kategórie sú normalizované iba na povolený KomArena CSV zoznam.',
			'qa_status' => empty($qa['missing']) ? 'ready' : 'needs_review',
			'ready_to_import' => empty($qa['missing']),
			'qa' => $qa,
			'warnings' => array_values(array_unique(array_filter((array) ($research['warnings'] ?? array())))),
			'confidence_score' => $research['confidence_score'] ?? 0,
			'generated_at' => current_time('mysql'),
			'agent_version' => KOMARENA_PF_VERSION,
		);
		$manifest = apply_filters('komarena_pf_builder_manifest', $manifest, $row, $research, $options, $this);

		$manifest_written = file_put_contents($manifest_path, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if (false === $manifest_written) {
			return new WP_Error('komarena_pf_builder_manifest_failed', 'Manifest sa nepodarilo zapísať.');
		}

		$zip_result = $this->zip_files($zip_path, array($csv_path, $manifest_path));
		if (is_wp_error($zip_result)) {
			return $zip_result;
		}

		$result = array(
			'status' => empty($qa['missing']) ? 'done' : 'needs_review',
			'message' => empty($qa['missing']) ? 'WooCommerce CSV balík je pripravený.' : 'CSV balík je pripravený, ale manifest obsahuje upozornenia kontroly kvality.',
			'product_name' => $name,
			'slug' => $slug,
			'download_url' => trailingslashit($dir['url']) . $zip_name,
			'csv_url' => trailingslashit($dir['url']) . $csv_name,
			'manifest_url' => trailingslashit($dir['url']) . $manifest_name,
			'zip_path' => $zip_path,
			'csv_path' => $csv_path,
			'image_urls' => $image_urls,
			'sku' => $sku,
			'ean' => $ean,
			'price' => $price_data['price'],
			'categories' => $categories,
			'import_status' => 'publish',
			'qa' => $qa,
			'manifest' => $manifest,
		);

		update_option('komarena_pf_last_builder_csv_package', $result, false);
		$this->plugin->logger->log('info', 'CSV tvorca vytvoril balík', 'csv_tvorca', $result);

		return $result;
	}

	public function derive_image_urls($one_image_url, $product_name = '') {
		$url = esc_url_raw(trim((string) $one_image_url));
		if ('' === $url) {
			return new WP_Error('komarena_pf_builder_image_url_missing', 'Chýba odkaz jedného nahratého obrázka.');
		}

		$path = wp_parse_url($url, PHP_URL_PATH);
		$basename = $path ? basename($path) : basename($url);
		$slug = '';
		if (preg_match('/^komarena-(.+)-(main|angle|detail|technical)\.jpe?g$/i', $basename, $matches)) {
			$slug = sanitize_title($matches[1]);
		} elseif ($product_name) {
			$slug = sanitize_title($product_name);
		}

		if (!$slug) {
			return new WP_Error('komarena_pf_builder_slug_missing', 'Odkaz obrázka nemá očakávaný názov komarena-[slug]-[variant].jpg.');
		}

		$base_url = substr($url, 0, strrpos($url, '/') + 1);
		$urls = array();
		foreach ($this->variants as $variant) {
			$urls[$variant] = $base_url . sprintf('komarena-%s-%s.jpg', $slug, $variant);
		}

		return array('slug' => $slug, 'urls' => $urls);
	}

	private function csv_columns() {
		return array(
			'Type',
			'Name',
			'Short description',
			'Description',
			'SKU',
			'Regular price',
			'Categories',
			'EAN',
			'Stock',
			'Status',
			'Tax status',
			'Images',
			'Image alt text',
			'Meta: _yoast_wpseo_title',
			'Meta: _yoast_wpseo_metadesc',
		);
	}

	private function allowed_categories() {
		return array('Elektronika', 'Vývojové dosky', 'Senzory', 'Batérie', 'Náradie', 'Kity a sety', 'Napájanie', 'Home Assistant', 'ESP / ESPHome');
	}

	private function source_urls_from_options($options) {
		$urls = array();
		$raw = $options['source_urls'] ?? array();
		if (is_string($raw)) {
			$raw = preg_split('/\r\n|\r|\n/', $raw);
		}
		foreach ((array) $raw as $url) {
			$url = esc_url_raw(trim((string) $url));
			if ($url) {
				$urls[] = $url;
			}
		}

		return array_values(array_unique($urls));
	}

	private function real_image_sources($research, $options) {
		$sources = array();
		if (!empty($options['image_sources']) && is_array($options['image_sources'])) {
			$sources = $options['image_sources'];
		}
		if (!empty($research['image_sources']) && is_array($research['image_sources'])) {
			$sources = array_merge($sources, $research['image_sources']);
		}

		$sources = apply_filters('komarena_pf_builder_real_image_sources', $sources, $research, $options, $this);
		$normalized = $this->normalize_image_sources($sources);
		if (count($normalized) >= 4) {
			return $normalized;
		}

		$discovered = $this->discover_images_from_source_urls($research['source_urls'] ?? array());
		$normalized = array_merge($normalized, $this->normalize_image_sources($discovered));

		return array_intersect_key($normalized, array_flip($this->variants));
	}

	private function normalize_image_sources($sources) {
		$out = array();
		foreach ($this->variants as $index => $variant) {
			$url = '';
			if (isset($sources[$variant])) {
				$url = is_array($sources[$variant]) ? ($sources[$variant]['url'] ?? '') : $sources[$variant];
			} elseif (isset($sources[$index])) {
				$url = is_array($sources[$index]) ? ($sources[$index]['url'] ?? '') : $sources[$index];
			}
			$url = esc_url_raw($url);
			if ($url) {
				$out[$variant] = $url;
			}
		}

		return $out;
	}

	private function discover_images_from_source_urls($source_urls) {
		$candidates = array();
		foreach ((array) $source_urls as $source_url) {
			$source_url = esc_url_raw($source_url);
			if (!$source_url) {
				continue;
			}

			$response = wp_remote_get($source_url, array(
				'timeout'     => 12,
				'redirection' => 4,
				'user-agent'  => 'KomArena Produktovy Agent/' . KOMARENA_PF_VERSION,
			));
			if (is_wp_error($response)) {
				continue;
			}

			$html = (string) wp_remote_retrieve_body($response);
			if ('' === $html) {
				continue;
			}

			$candidates = array_merge($candidates, $this->extract_image_urls($html, $source_url));
			if (count($candidates) >= 8) {
				break;
			}
		}

		$out = array();
		$candidates = array_values(array_unique(array_filter($candidates)));
		foreach ($this->variants as $index => $variant) {
			if (!empty($candidates[$index])) {
				$out[$variant] = $candidates[$index];
			}
		}

		return $out;
	}

	private function extract_image_urls($html, $base_url) {
		$urls = array();
		if (preg_match_all('/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}
		if (preg_match_all('/"image"\s*:\s*(?:"([^"]+)"|\[([^\]]+)\])/i', $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				if (!empty($match[1])) {
					$urls[] = $match[1];
				}
				if (!empty($match[2]) && preg_match_all('/"([^"]+)"/', $match[2], $nested)) {
					$urls = array_merge($urls, $nested[1]);
				}
			}
		}
		if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}

		$out = array();
		foreach ($urls as $url) {
			$url = $this->absolute_url($url, $base_url);
			if ($url && preg_match('/\.(jpe?g|png|webp)(\?.*)?$/i', $url)) {
				$out[] = $url;
			}
		}

		return array_values(array_unique($out));
	}

	private function absolute_url($url, $base_url) {
		$url = trim(html_entity_decode((string) $url, ENT_QUOTES));
		if ('' === $url || 0 === strpos($url, 'data:')) {
			return '';
		}

		$parts = wp_parse_url($base_url);
		$scheme = $parts['scheme'] ?? 'https';
		$host = $parts['host'] ?? '';
		if (0 === strpos($url, '//')) {
			return esc_url_raw($scheme . ':' . $url);
		}
		if (0 === strpos($url, '/') && $host) {
			return esc_url_raw($scheme . '://' . $host . $url);
		}

		return esc_url_raw($url);
	}

	private function standardize_real_image($url, $path) {
		if (!function_exists('download_url')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = download_url(esc_url_raw($url), 30);
		if (is_wp_error($tmp)) {
			return $tmp;
		}

		$bytes = file_get_contents($tmp);
		@unlink($tmp);
		if (!$bytes) {
			return new WP_Error('komarena_pf_builder_image_read_failed', 'Realny zdrojovy obrazok sa nepodarilo nacitat.');
		}

		$source = @imagecreatefromstring($bytes);
		if (!$source) {
			return new WP_Error('komarena_pf_builder_image_decode_failed', 'Realny zdrojovy obrazok sa nepodarilo dekodovat.');
		}

		$src_w = imagesx($source);
		$src_h = imagesy($source);
		$canvas = imagecreatetruecolor(1200, 1200);
		$white = imagecolorallocate($canvas, 255, 255, 255);
		$shadow = imagecolorallocate($canvas, 228, 236, 236);
		$border = imagecolorallocate($canvas, 216, 236, 236);
		imagefilledrectangle($canvas, 0, 0, 1200, 1200, $white);
		imagefilledellipse($canvas, 600, 1020, 760, 80, $shadow);

		$max = 1000;
		$scale = min($max / max(1, $src_w), $max / max(1, $src_h), 1);
		$dst_w = max(1, (int) round($src_w * $scale));
		$dst_h = max(1, (int) round($src_h * $scale));
		$dst_x = (int) floor((1200 - $dst_w) / 2);
		$dst_y = (int) floor((1200 - $dst_h) / 2);
		imagecopyresampled($canvas, $source, $dst_x, $dst_y, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
		imagerectangle($canvas, 60, 60, 1140, 1140, $border);

		$saved = imagejpeg($canvas, $path, 92);
		imagedestroy($source);
		imagedestroy($canvas);
		if (!$saved) {
			return new WP_Error('komarena_pf_builder_image_save_failed', 'Standardizovany realny obrazok sa nepodarilo zapisat.');
		}

		return $path;
	}

	private function image_mode($options, $real_sources = array()) {
		$mode = sanitize_key($options['image_mode'] ?? '');
		$map = array(
			'real_standardized' => 'REAL_STANDARDIZED',
			'real_partial' => 'REAL_PARTIAL',
			'illustrated_fallback' => 'ILLUSTRATED_FALLBACK',
		);

		return (count($real_sources) >= 4) ? ($map[$mode] ?? 'REAL_STANDARDIZED') : 'ILLUSTRATED_FALLBACK';
	}

	private function builder_image_provenance($real_sources, $image_mode) {
		$out = array();
		foreach ($this->variants as $variant) {
			$source = $real_sources[$variant] ?? array();
			$record = is_array($source) ? $source : array('url' => $source);
			$url = esc_url_raw($record['url'] ?? $record['source_url'] ?? '');
			$generated = 'ILLUSTRATED_FALLBACK' === $image_mode && !$url;
			$source_type = sanitize_key($record['source_type'] ?? $record['sourceType'] ?? ($url ? $this->builder_source_type($url) : 'generated_review_only'));
			$usage = sanitize_key($record['usage_permission'] ?? $record['usagePermission'] ?? $record['usePolicy'] ?? ($generated ? 'blocked_for_final' : 'needs_rights_review'));
			$watermark = sanitize_key($record['watermark_status'] ?? $record['watermarkStatus'] ?? ($generated ? 'not_applicable' : 'unknown'));
			$seller_logo = sanitize_key($record['seller_logo_status'] ?? $record['sellerLogoStatus'] ?? ($generated ? 'not_applicable' : 'unknown'));
			$ai_generated = !empty($record['ai_generated']) || !empty($record['aiGenerated']);
			$is_real = !$generated && !$ai_generated && in_array($image_mode, array('REAL_STANDARDIZED', 'REAL_PARTIAL'), true);

			$out[$variant] = array(
				'role'               => $variant,
				'mode'               => sanitize_key($image_mode),
				'source_url'         => $url,
				'source_host'        => $url ? wp_parse_url($url, PHP_URL_HOST) : '',
				'source_type'        => $source_type,
				'usage_permission'   => $usage,
				'watermark_status'   => $watermark,
				'seller_logo_status' => $seller_logo,
				'ai_generated'       => (bool) $ai_generated,
				'generated'          => (bool) $generated,
				'is_original_or_real'=> (bool) $is_real,
				'is_final_eligible'  => $is_real && in_array($usage, array('allowed', 'allowed_internal', 'own_photo', 'licensed', 'manufacturer_allowed'), true) && 'clean' === $watermark && 'clean' === $seller_logo,
				'checked_at'         => current_time('mysql'),
			);
		}

		return $out;
	}

	private function builder_source_type($url) {
		$host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
		if (false !== strpos($host, 'komarena.sk')) {
			return 'internal_komarena';
		}
		if (preg_match('/(arduino\.cc|espressif\.com|raspberrypi\.com|bosch-sensortec\.com|seeedstudio\.com|adafruit\.com|sparkfun\.com)$/i', $host)) {
			return 'manufacturer_or_official';
		}
		if (preg_match('/(mouser|digikey|farnell|rs-online|tme|gme|techfun)/i', $host)) {
			return 'verified_distributor_or_listing';
		}
		if (preg_match('/\.pdf($|\?)/i', (string) $url)) {
			return 'datasheet';
		}

		return $host ? 'external_web' : 'unknown';
	}

	private function stable_slug($research, $fallback) {
		$base = $research['slug'] ?? sanitize_title($research['normalized_name'] ?? $fallback);
		$base = sanitize_title($base);
		if (!$base) {
			$base = 'produkt';
		}

		return substr($base, 0, 80);
	}

	private function artifact_dir($slug, $type) {
		$upload = wp_upload_dir();
		if (!empty($upload['error'])) {
			return new WP_Error('komarena_pf_builder_upload_error', $upload['error']);
		}

		$folder = sanitize_file_name(current_time('Ymd-His') . '-' . $type . '-' . $slug);
		$path = trailingslashit($upload['basedir']) . 'komarena-product-builder/' . $folder;
		$url = trailingslashit($upload['baseurl']) . 'komarena-product-builder/' . $folder;

		if (!wp_mkdir_p($path)) {
			return new WP_Error('komarena_pf_builder_dir_failed', 'Adresár pre artefakty tvorcu produktov sa nepodarilo vytvoriť.');
		}

		return array('path' => $path, 'url' => $url);
	}

	private function generate_builder_image($path, $research, $variant, $image_mode) {
		$width = 1200;
		$height = 1200;
		$image = imagecreatetruecolor($width, $height);
		if (!$image) {
			return new WP_Error('komarena_pf_builder_image_failed', 'Obrazok sa nepodarilo inicializovat.');
		}
		if (function_exists('imageantialias')) {
			imageantialias($image, true);
		}

		$white = imagecolorallocate($image, 255, 255, 255);
		$bg = imagecolorallocate($image, 247, 252, 252);
		$teal = imagecolorallocate($image, 0, 151, 157);
		$dark = imagecolorallocate($image, 16, 32, 39);
		$muted = imagecolorallocate($image, 104, 123, 128);
		$line = imagecolorallocate($image, 206, 235, 235);
		$shadow = imagecolorallocate($image, 225, 235, 235);
		$chip = imagecolorallocate($image, 35, 54, 62);
		$board = imagecolorallocate($image, 0, 132, 137);
		$metal = imagecolorallocate($image, 217, 226, 226);

		imagefilledrectangle($image, 0, 0, $width, $height, $bg);
		imagefilledrectangle($image, 130, 170, 1070, 1015, $white);
		imagerectangle($image, 130, 170, 1070, 1015, $line);
		imagefilledellipse($image, 600, 830, 620, 90, $shadow);

		$tilt = 'angle' === $variant ? 45 : 0;
		$this->draw_board($image, $variant, $board, $chip, $metal, $teal, $dark, $line, $tilt);

		$title = $this->image_safe_text($research['normalized_name'] ?? $research['input_name'] ?? 'KomArena produkt', 48);
		$subtitle = strtoupper(str_replace('_', ' ', $variant));
		imagestring($image, 5, 170, 86, $title, $dark);
		imagestring($image, 4, 170, 116, 'KomArena tvorca produktov / ' . $subtitle, $teal);
		imagestring($image, 3, 170, 1045, 'Rezim: ' . $image_mode . ' - rozlozenie produktu pred publikovanim overit podla konkretnej dodavky.', $muted);

		if ('technical' === $variant) {
			$this->draw_pin_callouts($image, $teal, $dark, $line);
		}
		if ('detail' === $variant) {
			imagestring($image, 4, 780, 690, 'detail', $teal);
			imagerectangle($image, 755, 430, 990, 670, $teal);
		}

		$saved = imagejpeg($image, $path, 92);
		imagedestroy($image);

		if (!$saved) {
			return new WP_Error('komarena_pf_builder_image_save_failed', 'JPG obrazok sa nepodarilo zapisat.');
		}

		return $path;
	}

	private function draw_board($image, $variant, $board, $chip, $metal, $teal, $dark, $line, $tilt = 0) {
		$x1 = 'detail' === $variant ? 255 : 290;
		$y1 = 'detail' === $variant ? 360 : 385;
		$x2 = 'detail' === $variant ? 945 : 885;
		$y2 = 'detail' === $variant ? 705 : 680;
		if ('technical' === $variant) {
			$x1 = 235;
			$y1 = 330;
			$x2 = 965;
			$y2 = 725;
		}
		if ($tilt) {
			$x1 += 25;
			$x2 -= 15;
			$y1 -= 20;
			$y2 += 20;
		}

		imagefilledrectangle($image, $x1, $y1, $x2, $y2, $board);
		imagerectangle($image, $x1, $y1, $x2, $y2, $teal);
		imagefilledrectangle($image, $x1 + 235, $y1 + 95, $x1 + 455, $y1 + 245, $chip);
		imagerectangle($image, $x1 + 235, $y1 + 95, $x1 + 455, $y1 + 245, $dark);
		imagestring($image, 3, $x1 + 275, $y1 + 160, 'MCU / MODULE', imagecolorallocate($image, 235, 244, 244));

		for ($i = 0; $i < 18; $i++) {
			$py = $y1 + 28 + ($i * 17);
			imagefilledrectangle($image, $x1 - 18, $py, $x1 + 10, $py + 7, $metal);
			imagefilledrectangle($image, $x2 - 10, $py, $x2 + 18, $py + 7, $metal);
		}

		imagefilledrectangle($image, $x1 + 35, $y1 + 120, $x1 + 145, $y1 + 210, $metal);
		imagerectangle($image, $x1 + 35, $y1 + 120, $x1 + 145, $y1 + 210, $line);
		imagestring($image, 3, $x1 + 58, $y1 + 154, 'USB', $dark);
		imagefilledellipse($image, $x1 + 55, $y1 + 38, 22, 22, $line);
		imagefilledellipse($image, $x2 - 55, $y1 + 38, 22, 22, $line);
		imagefilledellipse($image, $x1 + 55, $y2 - 38, 22, 22, $line);
		imagefilledellipse($image, $x2 - 55, $y2 - 38, 22, 22, $line);
	}

	private function draw_pin_callouts($image, $teal, $dark, $line) {
		imagestring($image, 4, 210, 780, 'Piny / konektory overit podla datasheetu konkretnej verzie', $dark);
		imageline($image, 305, 760, 248, 725, $teal);
		imageline($image, 895, 760, 956, 725, $teal);
		imagefilledrectangle($image, 190, 800, 1010, 870, imagecolorallocate($image, 255, 255, 255));
		imagerectangle($image, 190, 800, 1010, 870, $line);
		imagestring($image, 3, 220, 825, 'Napajanie, logicke urovne, rozhrania a pinout sa mozu lisit podla dodavky.', $dark);
	}

	private function image_safe_text($text, $max = 48) {
		$text = strtoupper(remove_accents(wp_strip_all_tags((string) $text)));
		$text = preg_replace('/[^A-Z0-9 \-\/\.]/', '', $text);
		$text = trim(preg_replace('/\s+/', ' ', $text));

		return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
	}

	private function zip_files($zip_path, $files) {
		$zip = new ZipArchive();
		$opened = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if (true !== $opened) {
			return new WP_Error('komarena_pf_builder_zip_failed', 'ZIP balik sa nepodarilo vytvorit.');
		}

		foreach ($files as $file) {
			if (!file_exists($file)) {
				$zip->close();
				return new WP_Error('komarena_pf_builder_zip_file_missing', 'Subor pre ZIP neexistuje: ' . basename($file));
			}
			$zip->addFile($file, basename($file));
		}

		$zip->close();
		return $zip_path;
	}

	private function write_csv($path, $row) {
		$handle = fopen($path, 'w');
		if (!$handle) {
			return new WP_Error('komarena_pf_builder_csv_failed', 'CSV subor sa nepodarilo otvorit na zapis.');
		}

		fputcsv($handle, $this->csv_columns());
		$ordered = array();
		foreach ($this->csv_columns() as $column) {
			$ordered[] = $row[$column] ?? '';
		}
		fputcsv($handle, $ordered);
		fclose($handle);

		return $path;
	}

	private function normalize_categories($categories) {
		$allowed = $this->allowed_categories();
		$map = array(
			'vyvojove dosky' => 'Vývojové dosky',
			'vĂ˝vojovĂ© dosky' => 'Vývojové dosky',
			'senzory' => 'Senzory',
			'baterie' => 'Batérie',
			'batĂ©rie' => 'Batérie',
			'naradie' => 'Náradie',
			'nĂˇradie' => 'Náradie',
			'kity a sety' => 'Kity a sety',
			'napajanie' => 'Napájanie',
			'napĂˇjanie' => 'Napájanie',
			'home assistant' => 'Home Assistant',
			'esp & esphome' => 'ESP / ESPHome',
			'esp / esphome' => 'ESP / ESPHome',
			'esphome' => 'ESP / ESPHome',
			'esp' => 'ESP / ESPHome',
			'moduly' => 'Elektronika',
			'elektronika' => 'Elektronika',
		);

		$out = array();
		foreach ((array) $categories as $category) {
			$key = strtolower(remove_accents((string) $category));
			$key = trim($key);
			if (isset($map[$key])) {
				$out[] = $map[$key];
			}
		}
		if (empty($out)) {
			$out[] = 'Elektronika';
		}

		return array_values(array_intersect($allowed, array_unique($out)));
	}

	private function short_description($research) {
		if ($this->plugin && $this->plugin->settings->get('require_verified_product_facts', 1) && empty($research['fact_verification']['verified'])) {
			$research = $this->mask_unverified_research($research);
		}
		$title = esc_html($research['normalized_name'] ?? $research['input_name'] ?? 'Produkt');
		$chip = esc_html($research['chip'] ?? 'podla konkretnej verzie / dodavky sa moze lisit');
		$use = esc_html($research['typical_use'] ?? 'Arduino, ESP, Home Assistant alebo DIY elektronicke projekty');
		$compatibility = esc_html(implode(', ', array_slice((array) ($research['compatibility'] ?? array()), 0, 4)));

		return sprintf('%s je technicky komponent pre elektronicke, IoT alebo automatizacne projekty. Hlavny cip alebo verzia: %s. Typicke pouzitie: %s. Kompatibilita: %s.', $title, $chip, $use, $compatibility ? $compatibility : 'podla konkretnej verzie a zapojenia');
	}

	private function mask_unverified_research($research) {
		$note = 'caka na 100% zdrojove overenie';
		$research['chip'] = $note;
		$research['model'] = $note;
		$research['ports'] = $note;
		$research['connectors'] = $note;
		$research['pin_layout'] = $note;
		$research['typical_use'] = 'pracovny navrh; pouzitie sa potvrdi az po overeni zdrojov';
		$research['compatibility'] = array('overit podla datasheetu alebo distributor listingu');
		$research['specs'] = array('Zdrojový stav' => 'na kontrolu', 'Technické fakty' => $note);

		return $research;
	}

	private function long_description($research, $image_urls, $price_data, $categories) {
		if ($this->plugin && isset($this->plugin->content) && method_exists($this->plugin->content, 'canonical_description')) {
			return $this->plugin->content->canonical_description($research, $image_urls, $price_data, $categories);
		}
		if ($this->plugin && $this->plugin->settings->get('require_verified_product_facts', 1) && empty($research['fact_verification']['verified'])) {
			$research = $this->mask_unverified_research($research);
		}

		$title = esc_html($research['normalized_name'] ?? $research['input_name'] ?? 'Produkt');
		$model = esc_html($research['model'] ?? 'Podla konkretnej verzie / dodavky sa moze lisit.');
		$chip = esc_html($research['chip'] ?? 'Podla konkretnej verzie / dodavky sa moze lisit.');
		$ports = esc_html($research['ports'] ?? 'Podla konkretnej verzie / dodavky sa moze lisit.');
		$connectors = esc_html($research['connectors'] ?? 'Podla konkretnej verzie / dodavky sa moze lisit.');
		$pin_layout = esc_html($research['pin_layout'] ?? 'Podľa konkrétnej verzie / dodávky sa môže líšiť.');
		$appearance = esc_html($research['appearance'] ?? 'Fyzicke vyhotovenie treba overit podla konkretnej dodavky.');
		$typical_use = esc_html($research['typical_use'] ?? 'DIY elektronika, vyvoj, testovanie alebo prototypovanie.');
		$compatibility = array_map('esc_html', (array) ($research['compatibility'] ?? array()));
		$package = array_map('esc_html', (array) ($research['package_contents'] ?? array('1x produkt / modul')));
		$alts = $this->alt_texts($research, $image_urls);
		$links = $this->inline_links();
		$related = $this->related_terms($research);

		$html = '<div style="font-family:Arial,sans-serif;color:#102027;line-height:1.62;">';
		$html .= '<section style="display:flex;flex-wrap:wrap;gap:22px;align-items:center;background:#f3ffff;border:1px solid rgba(0,151,157,.24);border-radius:18px;padding:22px;margin:0 0 22px;box-shadow:0 10px 26px rgba(15,35,45,.05);">';
		$html .= '<div style="flex:1 1 320px;"><h2 style="margin:0 0 10px;color:#00777c;font-size:26px;">' . $title . '</h2><p style="margin:0 0 12px;color:#334347;">Technicky overovany produkt pre KomArena projekty. Parametre, pinout a fyzicke vyhotovenie sa pri klonoch a dodavkach mozu lisit, preto su neiste udaje oznacene opatrne.</p>';
		$html .= '<ul style="margin:0;padding-left:18px;"><li>Model: ' . $model . '</li><li>Cip/verzia: ' . $chip . '</li><li>Typicke pouzitie: ' . $typical_use . '</li></ul></div>';
		$html .= '<div style="flex:1 1 280px;">' . $this->image_html($image_urls['main'], $alts['main']) . '</div></section>';

		$html .= '<section style="display:flex;flex-wrap:wrap;gap:12px;margin:0 0 22px;">';
		$html .= $this->kpi_card('Model', $model);
		$html .= $this->kpi_card('Rozhranie', $ports);
		$html .= $this->kpi_card('Cena', esc_html($price_data['price'] ?? '') . ' EUR');
		$html .= '</section>';

		$html .= '<section style="display:flex;flex-wrap:wrap;gap:22px;margin:0 0 22px;"><div style="flex:1 1 320px;"><h3 style="color:#00777c;margin:0 0 10px;">Preco si vybrat</h3><p>Produkt je vhodny tam, kde potrebujete rychlo postavit prototyp, doplnit senzor, vystup alebo riadiacu cast projektu. Dolezite prepojenia na ' . $links['vyvojove'] . ', ' . $links['senzory'] . ' a ' . $links['napajanie'] . ' su priamo v popise.</p></div><div style="flex:1 1 280px;">' . $this->image_html($image_urls['angle'], $alts['angle']) . '</div></section>';

		$html .= '<section style="display:flex;flex-wrap:wrap;gap:22px;margin:0 0 22px;"><div style="flex:1 1 280px;">' . $this->image_html($image_urls['detail'], $alts['detail']) . '</div><div style="flex:1 1 320px;"><h3 style="color:#00777c;margin:0 0 10px;">Pre koho je urceny</h3><p>Pre hobby tvorcov, skolitelov, servisnych technikov, vyvojarov a smart-home nadsencov, ktori pracuju s Arduino, ' . $links['esphome'] . ', ' . $links['home_assistant'] . ' alebo vlastnymi elektronickymi prototypmi.</p><p>Fyzicky vzhlad: ' . $appearance . '</p></div></section>';

		$html .= '<section style="background:#ffffff;border:1px solid rgba(0,151,157,.18);border-radius:16px;padding:18px;margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Prakticky priklad pouzitia</h3><p>Produkt mozete pouzit ako sucast maleho meracieho alebo riadiaceho zapojenia: napriklad senzor pripojeny k vyvojovej doske, napajany z vhodneho zdroja a nasledne prepojeny s dashboardom alebo automatizaciou.</p></section>';

		$html .= '<section style="background:#e9fbfb;border:1px solid rgba(0,151,157,.24);border-radius:16px;padding:18px;margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Ako to spolu funguje</h3><ol style="margin:0;padding-left:20px;"><li>Overte napajanie, logicke urovne a pinout.</li><li>Pripojte modul k vyvojovej doske alebo riadiacej elektronike.</li><li>Otestujte zakladnu komunikaciu a az potom zapojte realnu zataz.</li><li>Pri Home Assistant projekte prepojte zariadenie cez ESPHome alebo inu podporovanu integraciu.</li></ol></section>';

		$html .= '<section style="background:#ffffff;border:1px solid rgba(0,151,157,.18);border-radius:16px;padding:18px;margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Ako produkt vyuzit v projekte</h3><p>Produkt je vhodne brat ako sucast projektoveho riesenia, nie iba ako samostatnu suciastku. Agent k nemu odporuci navody, projektove scenare, suvisiace moduly, clanky, kompatibilne doplnky a pri vhodnych produktoch aj Home Assistant alebo ESPHome pouzitie.</p><div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;">' . $this->pills_html(array('KomArena navod', 'projekt s modulom', 'Home Assistant projekt', 'ESPHome navod', 'Arduino projekt', 'prepojovacie vodice')) . '</div></section>';

		$html .= '<section style="background:#ffffff;border:1px solid rgba(0,151,157,.18);border-radius:16px;padding:18px;margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">3D tlaceny doplnok k produktu</h3><p>K produktu moze vzniknut samostatny fyzicky KomArena 3D tlaceny doplnok, napriklad drziak, stojan, krabicka, panelovy adapter alebo montazny diel priradeny ku konkretnemu senzoru, modulu alebo projektu. Zakaznik tak nekupuje model na stiahnutie, ale hotovy vytlaceny doplnok.</p><p style="color:#6f7f84;margin:10px 0;">Interny agent ma pre KomArena pripravit kandidatne modely na vyrobu, overit licenciu, rozmery, polohu pinov a konektorov a navrhnut blogovy alebo projektovy navod s fotkami finalneho doplnku.</p><div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;">' . $this->pills_html(array('3D doplnok ' . $title, 'hotovy drziak', 'krabicka pre modul', 'stojan k senzoru', 'KomArena projektovy navod')) . '</div></section>';

		$html .= '<section style="display:flex;flex-wrap:wrap;gap:22px;margin:0 0 22px;"><div style="flex:2 1 420px;"><h3 style="color:#00777c;margin:0 0 10px;">Technicke parametre</h3><table style="width:100%;border-collapse:collapse;background:#ffffff;border:1px solid rgba(0,151,157,.24);"><tbody>';
		$rows = array('Model' => $model, 'Čip / verzia' => $chip, 'Porty' => $ports, 'Konektory' => $connectors, 'Rozloženie pinov' => $pin_layout);
		foreach ((array) ($research['specs'] ?? array()) as $key => $value) {
			$rows[sanitize_text_field($key)] = esc_html($value);
		}
		foreach ($rows as $key => $value) {
			$html .= '<tr><th style="text-align:left;padding:10px;border-bottom:1px solid rgba(0,151,157,.16);color:#334347;width:34%;">' . esc_html($key) . '</th><td style="padding:10px;border-bottom:1px solid rgba(0,151,157,.16);">' . $value . '</td></tr>';
		}
		$html .= '</tbody></table></div><div style="flex:1 1 280px;">' . $this->image_html($image_urls['technical'], $alts['technical']) . '</div></section>';

		$html .= '<section style="margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Kompatibilita</h3><p>' . esc_html(implode(', ', $compatibility)) . '. Presna kompatibilita zavisi od konkretnej verzie modulu, napatia, kniznice a sposobu zapojenia.</p></section>';
		$html .= '<section style="margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Typicka kombinacia</h3><p>Najcastejsie sa kombinuje s vyvojovou doskou, prepojovacimi kablami, vhodnym napajanim a podla projektu aj so senzormi, rele modulom, displejom alebo krabickou.</p></section>';
		$html .= '<section style="margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Obsah balenia</h3><ul style="margin:0;padding-left:18px;"><li>' . implode('</li><li>', $package) . '</li></ul></section>';
		$html .= '<section style="background:#fffdf7;border:1px solid rgba(214,144,0,.28);border-radius:16px;padding:18px;margin:0 0 22px;"><h3 style="color:#8a5a00;margin:0 0 10px;">Bezpecnostne odporucanie</h3><p>Pracujte len v odporucanom napati a neprekracujte prudove limity. Pri vykonovych zataziach pouzite rele, MOSFET, driver alebo externy zdroj. Pri bateriach a vyssich prudoch pouzite istenie. Trvala elektroinstalacia patri kvalifikovanej osobe.</p></section>';
		$html .= '<section style="background:#ffffff;border:1px solid rgba(0,151,157,.18);border-radius:16px;padding:18px;margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Zaruka a vratenie</h3><p>Na novy tovar sa vztahuje zakonna zaruka 24 mesiacov. Pri online nakupe ma zakaznik standardne 14 dni na odstupenie od zmluvy. Produkt je urceny na vyvoj, testovanie, vyucbu, hobby alebo prototypove pouzitie, ak ide o elektronicky modul.</p></section>';
		$html .= '<section style="margin:0 0 22px;"><h3 style="color:#00777c;margin:0 0 10px;">Poznamka ku klonu / variantom</h3><p>Ak ide o kompatibilny klon alebo modul od roznych dodavatelov, nejde o originalny produkt vyrobcu. USB prevodnik, konektor, potlac, farba PCB, pin listy a drobne osadenie sa podla konkretnej verzie / dodavky mozu lisit.</p></section>';
		$html .= '<section style="background:#f3ffff;border:1px solid rgba(0,151,157,.24);border-radius:16px;padding:18px;margin:0;"><h3 style="color:#00777c;margin:0 0 10px;">Suvisiace produkty a odporucane doplnky</h3>' . $this->pills_html($related) . '</section>';
		$html .= '</div>';

		return $html;
	}

	private function image_html($url, $alt) {
		return '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" loading="lazy" style="display:block;width:100%;height:auto;object-fit:contain;border:1px solid rgba(0,151,157,.20);border-radius:16px;background:#ffffff;padding:10px;box-shadow:0 10px 26px rgba(15,35,45,.05);" />';
	}

	private function kpi_card($label, $value) {
		return '<div style="flex:1 1 220px;background:#ffffff;border:1px solid rgba(0,151,157,.24);border-radius:16px;padding:16px;box-shadow:0 10px 26px rgba(15,35,45,.05);"><strong style="display:block;color:#00777c;margin-bottom:6px;">' . esc_html($label) . '</strong><span style="color:#334347;">' . esc_html($value) . '</span></div>';
	}

	private function inline_links() {
		return array(
			'vyvojove' => $this->link('https://komarena.sk/kategoria-produktu/elektronika/vyvojove-dosky/', 'vyvojove dosky'),
			'senzory' => $this->link('https://komarena.sk/kategoria-produktu/senzory/', 'senzory'),
			'napajanie' => $this->link('https://komarena.sk/kategoria-produktu/napajanie/', 'napajanie'),
			'home_assistant' => $this->link('https://komarena.sk/home-assistant/', 'Home Assistant'),
			'esphome' => $this->link('https://komarena.sk/esp-esphome/', 'ESP / ESPHome'),
		);
	}

	private function link($url, $label) {
		return '<a href="' . esc_url($url) . '" style="color:#00777c;font-weight:800;text-decoration:underline;">' . esc_html($label) . '</a>';
	}

	private function related_terms($research) {
		$name = strtolower(remove_accents($research['input_name'] ?? $research['normalized_name'] ?? ''));
		if (false !== strpos($name, 'arduino') || false !== strpos($name, 'esp') || false !== strpos($name, 'rp2040')) {
			return array('prepojovacie vodice', 'napajaci adapter', 'rele modul', 'OLED displej', 'senzor DHT22', 'RFID RC522');
		}
		if (false !== strpos($name, 'senzor') || false !== strpos($name, 'bme') || false !== strpos($name, 'dht') || false !== strpos($name, 'ds18b20')) {
			return array('ESP vyvojova doska', 'ESPHome', 'prepojovacie vodice', 'napajanie 5V', 'krabicka pre senzor', 'Home Assistant');
		}
		if (false !== strpos($name, 'rele') || false !== strpos($name, 'relay')) {
			return array('ESP modul', 'napajaci zdroj', 'svorkovnica', 'MOSFET modul', 'Home Assistant', 'ESPHome');
		}

		return array('ESP', 'prepojovacie vodice', 'napajanie', 'senzory', 'vyvojove dosky', 'Home Assistant');
	}

	private function pills_html($terms) {
		$html = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;">';
		foreach ((array) $terms as $term) {
			$url = add_query_arg(array('s' => $term), home_url('/'));
			$html .= '<a href="' . esc_url($url) . '" style="display:inline-block;border:1px solid #00979D;background:#e9fbfb;color:#00777c;border-radius:999px;padding:8px 12px;text-decoration:none;font-weight:800;font-size:13px;">' . esc_html($term) . '</a>';
		}
		$html .= '</div>';

		return $html;
	}

	private function alt_texts($research, $image_urls) {
		$title = $research['normalized_name'] ?? $research['input_name'] ?? 'KomArena produkt';
		return array(
			'main' => $title . ' - hlavny produktovy pohlad',
			'angle' => $title . ' - pohlad z uhla',
			'detail' => $title . ' - detail produktu',
			'technical' => $title . ' - technicky pohlad a pinout',
		);
	}

	private function yoast_title($research) {
		$title = $research['normalized_name'] ?? $research['input_name'] ?? 'Produkt';
		return wp_trim_words($title, 12, '') . ' | KomArena.sk';
	}

	private function yoast_description($research) {
		if ($this->plugin && $this->plugin->settings->get('require_verified_product_facts', 1) && empty($research['fact_verification']['verified'])) {
			$research = $this->mask_unverified_research($research);
		}
		$title = $research['normalized_name'] ?? $research['input_name'] ?? 'Produkt';
		$use = $research['typical_use'] ?? 'elektronicke, Arduino, ESPHome a Home Assistant projekty';
		$text = $title . ' pre ' . $use . '. Overene parametre, KomArena popis, obrazky, SEO a interne prelinky.';
		$text = wp_strip_all_tags($text);

		return function_exists('mb_substr') ? mb_substr($text, 0, 158) : substr($text, 0, 158);
	}

	private function csv_qa($row, $image_urls, $description, $categories, $research = array()) {
		$missing = array();
		foreach ($this->csv_columns() as $column) {
			if ('' === (string) ($row[$column] ?? '')) {
				$missing[] = 'Chyba CSV stlpec: ' . $column;
			}
		}
		foreach ($this->variants as $variant) {
			if (empty($image_urls[$variant])) {
				$missing[] = 'Chyba URL obrazka: ' . $variant;
			}
		}
		if (0 !== strpos((string) ($row['EAN'] ?? ''), '2998')) {
			$missing[] = 'EAN nezacina na 2998.';
		}
		if (!preg_match('/\.99$/', (string) ($row['Regular price'] ?? ''))) {
			$missing[] = 'Cena nekonci na .99.';
		}
		$has_allowed = false;
		foreach ($this->allowed_categories() as $allowed) {
			if (in_array($allowed, $categories, true)) {
				$has_allowed = true;
				break;
			}
		}
		if (empty($has_allowed)) {
			$missing[] = 'Kategoria nie je z povoleneho KomArena zoznamu.';
		}
		$required = array('Preco si vybrat', 'Pre koho je urceny', 'Ako produkt vyuzit v projekte', '3D tlaceny doplnok', 'Technicke parametre', 'Bezpecnostne odporucanie', 'Zaruka a vratenie', 'Suvisiace produkty');
		foreach ($required as $needle) {
			if (false === strpos($description, $needle)) {
				$missing[] = 'Popis neobsahuje sekciu: ' . $needle;
			}
		}
		if (false !== strpos($description, '<style') || false !== strpos($description, '<script')) {
			$missing[] = 'Popis obsahuje zakazany style/script blok.';
		}
		if (substr_count($description, '<img ') < 4) {
			$missing[] = 'V popise nie su vlozene 4 obrazky.';
		}
		if (substr_count($description, '<a ') < 3) {
			$missing[] = 'V popise je malo klikatelnych internych odkazov.';
		}
		$fact_verification = is_array($research) ? ($research['fact_verification'] ?? array()) : array();
		if (empty($fact_verification['verified'])) {
			$missing[] = 'Technicke fakty v CSV popise nie su 100 % naviazane na overene zdroje.';
		}

		return array(
			'status' => empty($missing) ? 'ready' : 'needs_review',
			'missing' => array_values(array_unique($missing)),
			'checked_at' => current_time('mysql'),
		);
	}
}
