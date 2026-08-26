<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Image_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function prepare_images($product_id, $research, $slug, $options = array()) {
		$variants = array(
			'main'      => 'Hlavný produktový pohľad',
			'angle'     => 'Šikmý produktový pohľad',
			'detail'    => 'Detail konektorov a čipov',
			'technical' => 'Technický pohľad / referencia rozloženia pinov',
		);

		$sources = $this->real_sources_for_research($product_id, $research);
		$ids     = array();
		$urls    = array();
		$files   = array();
		$modes   = array();
		$warnings = array();
		$provenance = array();
		$attach_to_product = array_key_exists('attach_to_product', (array) $options) ? !empty($options['attach_to_product']) : true;
		$require_exact_match = array_key_exists('require_exact_match', (array) $options) ? !empty($options['require_exact_match']) : true;
		$is_published = 'publish' === get_post_status($product_id);

		foreach ($variants as $variant => $label) {
			$filename = sprintf('komarena-%s-%s.jpg', sanitize_title($slug), $variant);
			$files[]  = $filename;
			$source_record = $this->source_record_for_variant($sources, $variant);
			$source   = $source_record['url'] ?? '';

			if ($source) {
				$exact_match = $this->exact_image_match($source_record, $research);
				$source_record['_komarena_exact_image_match'] = $exact_match;
				if ($require_exact_match && empty($exact_match['pass'])) {
					$warnings[] = sprintf('Obrázok pre variant %s bol zamietnutý: presná zhoda obrázka neprešla (%s).', $variant, $exact_match['reason']);
					$source = '';
				}
			}

			if ($source) {
				$preflight = $this->source_final_preflight($source_record, $source, 'sideloaded_verified_source');
				if ($is_published && empty($preflight['is_final_eligible'])) {
					$warnings[] = sprintf('Obrázok pre variant %s nebol nahraný k publikovanému produktu, pretože nemá finálne práva, čistý pôvod alebo presnú zhodu.', $variant);
					$source = '';
				}
			}

			if ($source) {
				$id = $this->sideload_image($source, $filename, $product_id, $research['normalized_name'] . ' - ' . $label);
				if (!is_wp_error($id)) {
					$modes[] = 'sideloaded_verified_source';
					$ids[]   = $id;
					$urls[]  = wp_get_attachment_url($id);
					$provenance[$variant] = $this->image_provenance_item($variant, 'sideloaded_verified_source', $source, $id, wp_get_attachment_url($id), $source_record);
					continue;
				}

				$warnings[] = $id->get_error_message();
			}

			if ($this->plugin->settings->get('allow_illustrated_image_fallback', 0)) {
				if ($is_published) {
					$warnings[] = 'Kreslený kontrolný náhľad nebol pripojený k publikovanému produktu.';
					continue;
				}
				$id = $this->generate_illustrated_fallback_image($filename, $product_id, $research, $label, $variant);
				if (is_wp_error($id)) {
					$warnings[] = $id->get_error_message();
					continue;
				}

				$modes[] = 'illustrated_fallback_colored_pencil';
				$ids[]   = $id;
				$urls[]  = wp_get_attachment_url($id);
				$provenance[$variant] = $this->image_provenance_item($variant, 'illustrated_fallback_colored_pencil', '', $id, wp_get_attachment_url($id), array(
					'source_type'       => 'generated_review_only',
					'usage_permission'  => 'blocked_for_final',
					'watermark_status'  => 'not_applicable',
					'seller_logo_status'=> 'not_applicable',
					'ai_generated'      => false,
					'generated'         => true,
				));
				continue;
			}

			if (!$this->plugin->settings->get('allow_placeholder_images', 0)) {
				$warnings[] = 'Chýba overený obrázok pre variant ' . $variant . '.';
				continue;
			}

			if ($is_published) {
				$warnings[] = 'Kontrolný náhľad nebol pripojený k publikovanému produktu.';
				continue;
			}

			$id = $this->generate_review_image($filename, $product_id, $research['normalized_name'], $label);
			if (is_wp_error($id)) {
				$warnings[] = $id->get_error_message();
				continue;
			}

			$modes[] = 'placeholder_generated_for_review';
			$ids[]   = $id;
			$urls[]  = wp_get_attachment_url($id);
			$provenance[$variant] = $this->image_provenance_item($variant, 'placeholder_generated_for_review', '', $id, wp_get_attachment_url($id), array(
				'source_type'       => 'generated_review_only',
				'usage_permission'  => 'blocked_for_final',
				'watermark_status'  => 'not_applicable',
				'seller_logo_status'=> 'not_applicable',
				'ai_generated'      => false,
				'generated'         => true,
			));
		}

		if ($attach_to_product && count($ids) >= 4 && $this->all_provenance_final_eligible($provenance)) {
			set_post_thumbnail($product_id, (int) $ids[0]);
			update_post_meta($product_id, '_product_image_gallery', implode(',', array_map('absint', array_slice($ids, 1))));
		} elseif ($attach_to_product && !empty($ids)) {
			$warnings[] = 'Obrazky neboli nastavene ako featured/gallery, lebo nie su 4 finalne eligible exact-match produktove fotky.';
		}

		$mode = empty($ids) ? 'real_images_missing' : (in_array('placeholder_generated_for_review', $modes, true) ? 'placeholder_generated_for_review' : (in_array('illustrated_fallback_colored_pencil', $modes, true) ? 'illustrated_fallback_colored_pencil' : 'sideloaded_verified_source'));
		if ('placeholder_generated_for_review' === $mode) {
			$warnings[] = 'Obrázky sú iba kontrolné náhľady. Pred publikovaním nahrajte technicky verné fotografie alebo overené produktové obrázky.';
		} elseif ('illustrated_fallback_colored_pencil' === $mode) {
			$warnings[] = 'Agent nenašiel dostatok reálnych fotografií a použil farebný kreslený technický náhľad iba na kontrolu. Tento obrázok nie je originálna produktová fotografia a nikdy nestačí na pripravenie na publikovanie.';
		} elseif ('real_images_missing' === $mode) {
			$warnings[] = 'Agent nenašiel 4 reálne zdrojové obrázky. Produkt zostane na kontrolu, kým sa nedoplnia reálne obrázky.';
		}

		$this->plugin->logger->log('info', 'Obrázky pripravené.', 'obrázky', array(
			'product_id' => $product_id,
			'image_ids'  => $ids,
			'filenames'  => $files,
			'mode'       => $mode,
			'provenance' => $provenance,
			'warnings'   => $warnings,
		), null, $product_id);

		return array(
			'image_ids'   => $ids,
			'image_urls'  => array_filter($urls),
			'filenames'   => $files,
			'image_mode'  => $mode,
			'image_provenance' => $provenance,
			'warnings'    => array_values(array_unique($warnings)),
		);
	}

	public function candidate_sources($product_id, $research) {
		$sources = $this->real_sources_for_research($product_id, is_array($research) ? $research : array());
		$out = array();

		foreach (array('main', 'angle', 'detail', 'technical') as $variant) {
			$record = $this->source_record_for_variant($sources, $variant);
			if (empty($record['url'])) {
				continue;
			}

			$exact_match = $this->exact_image_match($record, is_array($research) ? $research : array());
			$record['_komarena_exact_image_match'] = $exact_match;
			$preflight = $this->source_final_preflight($record, $record['url'], 'sideloaded_verified_source');
			$record['_komarena_final_preflight'] = $preflight;
			$record['_komarena_final_eligible'] = !empty($preflight['is_final_eligible']);
			$record['_komarena_rejection_reasons'] = $this->candidate_rejection_reasons($record, $exact_match, $preflight);
			$out[$variant] = $record;
		}

		return $out;
	}

	private function candidate_rejection_reasons($record, $exact_match, $preflight) {
		$reasons = array();
		if (empty($exact_match['pass'])) {
			$reasons[] = sanitize_text_field($exact_match['reason'] ?? 'obrázok nemá potvrdenú presnú zhodu s produktom');
		}
		if (empty($preflight['is_final_eligible'])) {
			$permission = sanitize_key($preflight['usage_permission'] ?? '');
			$watermark = sanitize_key($preflight['watermark_status'] ?? '');
			$logo = sanitize_key($preflight['seller_logo_status'] ?? '');
			if (!$this->usage_allows_final($permission)) {
				$reasons[] = 'chýba potvrdené právo použiť obrázok vo finálnom produkte';
			}
			if ('clean' !== $watermark) {
				$reasons[] = 'nie je potvrdené, že obrázok je bez vodoznaku';
			}
			if ('clean' !== $logo) {
				$reasons[] = 'nie je potvrdené, že obrázok je bez cudzieho predajného loga';
			}
		}
		if (!empty($record['ai_generated']) || !empty($record['generated'])) {
			$reasons[] = 'obrázok je označený ako generovaný alebo AI';
		}

		return array_values(array_unique(array_filter($reasons)));
	}

	private function real_sources_for_research($product_id, $research) {
		$sources = is_array($research['image_sources'] ?? null) ? $research['image_sources'] : array();
		$sources = apply_filters('komarena_pf_image_sources', $sources, $product_id, $research);
		if ($this->has_variant_sources($sources)) {
			return $sources;
		}

		$source_urls = (array) ($research['source_urls'] ?? array());
		$discovered = $this->discover_images_from_source_urls($source_urls);
		if (!empty($discovered)) {
			$sources = array_merge((array) $sources, $discovered);
		}

		return apply_filters('komarena_pf_real_image_sources', $sources, $product_id, $research);
	}

	private function has_variant_sources($sources) {
		foreach (array('main', 'angle', 'detail', 'technical') as $variant) {
			if (!$this->source_for_variant($sources, $variant)) {
				return false;
			}
		}

		return true;
	}

	private function source_for_variant($sources, $variant) {
		$record = $this->source_record_for_variant($sources, $variant);
		return $record['url'] ?? '';
	}

	private function source_record_for_variant($sources, $variant) {
		if (!is_array($sources)) {
			return array();
		}

		if (!empty($sources[$variant])) {
			return $this->normalize_source_record($sources[$variant], $variant);
		}

		$index = array_search($variant, array('main', 'angle', 'detail', 'technical'), true);
		if (false !== $index && !empty($sources[$index])) {
			return $this->normalize_source_record($sources[$index], $variant);
		}

		return array();
	}

	private function normalize_source_record($source, $variant) {
		if (is_array($source)) {
			$record = $source;
		} else {
			$record = array('url' => $source);
		}

		$record['url'] = esc_url_raw($record['url'] ?? $record['source_url'] ?? '');
		$record['image_url'] = esc_url_raw($record['image_url'] ?? $record['url']);
		$record['source_url'] = esc_url_raw($record['source_url'] ?? $record['page_url'] ?? $record['sourcePageUrl'] ?? $record['url']);
		$record['role'] = sanitize_key($record['role'] ?? $variant);

		return $record;
	}

	private function image_provenance_item($variant, $mode, $source_url, $attachment_id, $url, $source_record = array()) {
		$image_source_url = esc_url_raw($source_record['image_url'] ?? $source_record['url'] ?? $source_url);
		$source_url = esc_url_raw($source_record['source_url'] ?? $source_url);
		$url = esc_url_raw($url);
		$source_type = sanitize_key($source_record['source_type'] ?? $source_record['sourceType'] ?? $this->detect_source_type($source_url, $source_record));
		$usage_permission = sanitize_key($source_record['usage_permission'] ?? $source_record['usagePermission'] ?? $source_record['usePolicy'] ?? $this->default_usage_permission($source_url, $source_type, $mode));
		$watermark_status = sanitize_key($source_record['watermark_status'] ?? $source_record['watermarkStatus'] ?? $this->default_visual_status($image_source_url . ' ' . $source_url, $mode));
		$seller_logo_status = sanitize_key($source_record['seller_logo_status'] ?? $source_record['sellerLogoStatus'] ?? $this->default_visual_status($image_source_url . ' ' . $source_url, $mode));
		$ai_generated = $this->truthy($source_record['ai_generated'] ?? $source_record['aiGenerated'] ?? false);
		$generated = $this->truthy($source_record['generated'] ?? false) || in_array($mode, array('illustrated_fallback_colored_pencil', 'placeholder_generated_for_review'), true);
		$is_real = !$generated && !$ai_generated && in_array($mode, array('sideloaded_verified_source', 'real_standardized', 'real_partial'), true);
		$exact_match = is_array($source_record['_komarena_exact_image_match'] ?? null) ? $source_record['_komarena_exact_image_match'] : array('pass' => false, 'reason' => 'exact image match not evaluated', 'matched_tokens' => array());
		$exact_pass = !empty($exact_match['pass']);

		return array(
			'role'                     => sanitize_key($variant),
			'mode'                     => sanitize_key($mode),
			'source_url'               => $source_url,
			'source_image_url'         => $image_source_url,
			'source_host'              => $this->source_host($source_url),
			'source_type'              => $source_type,
			'attachment_id'            => (int) $attachment_id,
			'url'                      => $url,
			'usage_permission'         => $usage_permission,
			'watermark_status'         => $watermark_status,
			'seller_logo_status'       => $seller_logo_status,
			'ai_generated'             => $ai_generated,
			'generated'                => $generated,
			'is_original_or_real'      => $is_real,
			'exact_image_match'        => $exact_pass,
			'exact_image_match_reason' => sanitize_text_field($exact_match['reason'] ?? ''),
			'matched_model_tokens'     => array_values(array_map('sanitize_text_field', (array) ($exact_match['matched_tokens'] ?? array()))),
			'is_final_eligible'        => $is_real && $exact_pass && $this->usage_allows_final($usage_permission) && 'clean' === $watermark_status && 'clean' === $seller_logo_status,
			'checked_at'               => current_time('mysql'),
			'notes'                    => sanitize_text_field($source_record['notes'] ?? ''),
		);
	}

	private function source_final_preflight($source_record, $source_url, $mode) {
		$source_url = esc_url_raw($source_record['source_url'] ?? $source_url);
		$image_source_url = esc_url_raw($source_record['image_url'] ?? $source_record['url'] ?? $source_url);
		$source_type = sanitize_key($source_record['source_type'] ?? $source_record['sourceType'] ?? $this->detect_source_type($source_url, $source_record));
		$usage_permission = sanitize_key($source_record['usage_permission'] ?? $source_record['usagePermission'] ?? $source_record['usePolicy'] ?? $this->default_usage_permission($source_url, $source_type, $mode));
		$watermark_status = sanitize_key($source_record['watermark_status'] ?? $source_record['watermarkStatus'] ?? $this->default_visual_status($image_source_url . ' ' . $source_url, $mode));
		$seller_logo_status = sanitize_key($source_record['seller_logo_status'] ?? $source_record['sellerLogoStatus'] ?? $this->default_visual_status($image_source_url . ' ' . $source_url, $mode));
		$ai_generated = $this->truthy($source_record['ai_generated'] ?? $source_record['aiGenerated'] ?? false);
		$exact_match = is_array($source_record['_komarena_exact_image_match'] ?? null) ? $source_record['_komarena_exact_image_match'] : array('pass' => false);
		$is_real = !$ai_generated && in_array($mode, array('sideloaded_verified_source', 'real_standardized', 'real_partial'), true);

		return array(
			'is_final_eligible' => $is_real && !empty($exact_match['pass']) && $this->usage_allows_final($usage_permission) && 'clean' === $watermark_status && 'clean' === $seller_logo_status,
			'usage_permission' => $usage_permission,
			'watermark_status' => $watermark_status,
			'seller_logo_status' => $seller_logo_status,
		);
	}

	private function all_provenance_final_eligible($provenance) {
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}
		foreach ($provenance as $item) {
			if (empty($item['is_final_eligible']) || empty($item['exact_image_match']) || !empty($item['generated']) || !empty($item['ai_generated'])) {
				return false;
			}
		}

		return true;
	}

	private function exact_image_match($source_record, $research) {
		$source_type = sanitize_key($source_record['source_type'] ?? $source_record['sourceType'] ?? '');
		$usage_permission = sanitize_key($source_record['usage_permission'] ?? $source_record['usagePermission'] ?? '');
		$watermark_status = sanitize_key($source_record['watermark_status'] ?? $source_record['watermarkStatus'] ?? '');
		$seller_logo_status = sanitize_key($source_record['seller_logo_status'] ?? $source_record['sellerLogoStatus'] ?? '');
		$is_original = $this->truthy($source_record['is_original_or_real'] ?? $source_record['isOriginalOrReal'] ?? false);
		$is_final = $this->truthy($source_record['is_final_eligible'] ?? $source_record['isFinalEligible'] ?? false);
		if (in_array($source_type, array('internal_komarena', 'internal_komarena_media'), true) && 'own_photo' === $usage_permission && 'clean' === $watermark_status && 'clean' === $seller_logo_status && $is_original && $is_final) {
			return array(
				'pass' => true,
				'reason' => 'interné schválenie vlastnej fotografie KomArena',
				'matched_tokens' => array('internal_komarena_own_photo'),
			);
		}

		$text_parts = array(
			$source_record['url'] ?? '',
			$source_record['image_url'] ?? '',
			$source_record['source_url'] ?? '',
			$source_record['page_url'] ?? '',
			$source_record['title'] ?? '',
			$source_record['alt'] ?? '',
			$source_record['name'] ?? '',
			$source_record['filename'] ?? '',
			$source_record['notes'] ?? '',
		);
		$text = $this->compact_match_text(implode(' ', array_map('strval', $text_parts)));
		$matched = array();
		foreach ($this->model_tokens($research) as $token) {
			$needle = $this->compact_match_text($token);
			if (strlen($needle) >= 4 && false !== strpos($text, $needle)) {
				$matched[] = $token;
			}
		}

		if (!empty($matched)) {
			return array(
				'pass' => true,
				'reason' => 'odkaz, názov alebo alternatívny text obrázka obsahuje konkrétne označenie modelu produktu',
				'matched_tokens' => array_values(array_unique($matched)),
			);
		}

		return array(
			'pass' => false,
			'reason' => 'kandidát neobsahuje konkrétne označenie modelu; všeobecné obrázky výrobcu alebo predajcu sú blokované',
			'matched_tokens' => array(),
		);
	}

	private function model_tokens($research) {
		$known = array(
			'BME280', 'BMP280', 'BH1750', 'DHT11', 'DHT22', 'DS18B20', 'HC-SR04', 'RC522', 'MFRC522',
			'LM2596', 'AMS1117', 'MB102', 'SSD1306', 'ST7735', 'TM1637', 'XH-M609', 'HW-319', 'HW-183B',
			'MAX7219', 'CH340', 'CP2102', 'ATmega2560', 'ATmega328P', 'NodeMCU', 'DevKit', 'Wemos D1 mini',
		);

		$text = array();
		foreach (array('input_name', 'normalized_name', 'model', 'chip', 'ports', 'connectors', 'pin_layout', 'appearance') as $key) {
			if (!empty($research[$key]) && is_scalar($research[$key])) {
				$text[] = (string) $research[$key];
			}
		}
		foreach ((array) ($research['specs'] ?? array()) as $key => $value) {
			if (is_scalar($key)) {
				$text[] = (string) $key;
			}
			if (is_scalar($value)) {
				$text[] = (string) $value;
			}
		}
		$haystack = implode(' ', $text);
		$tokens = array();
		foreach ($known as $token) {
			if (false !== stripos(remove_accents($haystack), remove_accents($token))) {
				$tokens[] = $token;
			}
		}

		if (preg_match_all('/\b(?:[A-Z]{1,5}[-_ ]?\d{2,5}[A-Z0-9-]*|ATMEGA\d+[A-Z]*|NODEMCU|DEVKIT|WEMOS\s+D1\s+MINI)\b/i', $haystack, $matches)) {
			foreach ($matches[0] as $match) {
				$match = trim(preg_replace('/\s+/', ' ', strtoupper((string) $match)));
				if (!in_array($match, array('ESP32', 'ESP8266', 'ESP', 'USB', 'I2C', 'UART'), true)) {
					$tokens[] = $match;
				}
			}
		}

		return array_values(array_unique(array_filter($tokens)));
	}

	private function compact_match_text($text) {
		$text = strtolower(remove_accents((string) $text));
		return preg_replace('/[^a-z0-9]+/', '', $text);
	}

	private function detect_source_type($source_url, $source_record = array()) {
		$declared = sanitize_key($source_record['source'] ?? '');
		if ($declared && false !== strpos($declared, 'komarena')) {
			return 'internal_komarena';
		}

		$host = $this->source_host($source_url);
		if (!$host) {
			return 'unknown';
		}
		if (false !== strpos($host, 'komarena.sk')) {
			return 'internal_komarena';
		}
		if (preg_match('/(arduino\.cc|espressif\.com|raspberrypi\.com|bosch-sensortec\.com|seeedstudio\.com|adafruit\.com|sparkfun\.com)$/i', $host)) {
			return 'manufacturer_or_official';
		}
		if (preg_match('/(mouser|digikey|farnell|rs-online|tme|gme|techfun|techfan|dratek|aliexpress|laskakit|opencircuit|botland)/i', $host)) {
			return 'verified_distributor_or_listing';
		}
		if (preg_match('/\.pdf($|\?)/i', $source_url)) {
			return 'datasheet';
		}

		return 'external_web';
	}

	private function default_usage_permission($source_url, $source_type, $mode) {
		if (in_array($mode, array('illustrated_fallback_colored_pencil', 'placeholder_generated_for_review'), true)) {
			return 'blocked_for_final';
		}
		if (in_array($source_type, array('internal_komarena', 'internal_komarena_media'), true)) {
			return 'allowed_internal';
		}

		return 'needs_rights_review';
	}

	private function default_visual_status($source_url, $mode) {
		if (in_array($mode, array('illustrated_fallback_colored_pencil', 'placeholder_generated_for_review'), true)) {
			return 'not_applicable';
		}

		$normalized = strtolower(remove_accents((string) $source_url));
		if (preg_match('/watermark|logo|brand|seller|predajca|shop/i', $normalized)) {
			return 'suspected';
		}

		return 'unknown';
	}

	private function usage_allows_final($permission) {
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

		$candidates = array_values(array_unique(array_filter($candidates)));
		$out = array();
		foreach (array('main', 'angle', 'detail', 'technical') as $index => $variant) {
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


	private function sideload_image($url, $filename, $product_id, $description) {
		if (!function_exists('download_url')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if (!function_exists('media_handle_sideload')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$url = esc_url_raw($url);
		if (!$url) {
			return new WP_Error('komarena_pf_image_url', 'Neplatný odkaz obrázka.');
		}

		$tmp = download_url($url, 30);
		if (is_wp_error($tmp)) {
			return $tmp;
		}

		$size = @getimagesize($tmp);
		if (empty($size[0]) || empty($size[1]) || $size[0] < 300 || $size[1] < 300) {
			@unlink($tmp);
			return new WP_Error('komarena_pf_image_too_small', 'Obrázok je príliš malý alebo nie je platný obrázok: ' . $url);
		}

		$file_array = array(
			'name'     => sanitize_file_name($filename),
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload($file_array, $product_id, $description);
		if (is_wp_error($id)) {
			@unlink($tmp);
			return $id;
		}

		update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($description));
		wp_update_post(array(
			'ID'           => $id,
			'post_title'   => sanitize_text_field($description),
			'post_content' => '',
		));

		return $id;
	}

	private function generate_illustrated_fallback_image($filename, $product_id, $research, $variant_label, $variant) {
		if (!function_exists('imagecreatetruecolor')) {
			return new WP_Error('komarena_pf_gd_missing', 'Grafická knižnica PHP nie je dostupná, kreslený kontrolný náhľad sa nedá vytvoriť.');
		}

		$upload = wp_upload_dir();
		if (!empty($upload['error'])) {
			return new WP_Error('komarena_pf_upload_dir', $upload['error']);
		}
		if (!wp_mkdir_p($upload['path'])) {
			return new WP_Error('komarena_pf_upload_dir', 'Nahrávací adresár sa nepodarilo vytvoriť.');
		}

		$filename = wp_unique_filename($upload['path'], sanitize_file_name($filename));
		$path = trailingslashit($upload['path']) . $filename;
		$title = sanitize_text_field($research['normalized_name'] ?? $research['input_name'] ?? 'KomArena produkt');
		$profile = strtolower(remove_accents($research['input_name'] ?? $title));

		$img = imagecreatetruecolor(1200, 1200);
		if (function_exists('imageantialias')) {
			imageantialias($img, true);
		}
		$paper = imagecolorallocate($img, 252, 252, 248);
		$shadow = imagecolorallocate($img, 224, 232, 232);
		$teal = imagecolorallocate($img, 0, 151, 157);
		$teal_dark = imagecolorallocate($img, 0, 118, 124);
		$ink = imagecolorallocate($img, 25, 42, 48);
		$muted = imagecolorallocate($img, 102, 116, 120);
		$metal = imagecolorallocate($img, 210, 220, 220);
		$red = imagecolorallocate($img, 190, 70, 70);
		$blue = imagecolorallocate($img, 60, 100, 180);
		$green = imagecolorallocate($img, 50, 150, 90);

		imagefilledrectangle($img, 0, 0, 1200, 1200, $paper);
		imagefilledellipse($img, 610, 840, 680, 86, $shadow);
		for ($i = 0; $i < 28; $i++) {
			$y = 70 + ($i * 36);
			imageline($img, 80, $y, 1120, $y + wp_rand(-2, 2), imagecolorallocate($img, 242, 246, 246));
		}

		if (false !== strpos($profile, 'dht') || false !== strpos($profile, 'ds18') || false !== strpos($profile, 'bme') || false !== strpos($profile, 'bmp') || false !== strpos($profile, 'senzor')) {
			$this->draw_sensor_illustration($img, $teal, $teal_dark, $ink, $metal, $green, $variant);
		} elseif (false !== strpos($profile, 'relay') || false !== strpos($profile, 'rele') || false !== strpos($profile, 'rel')) {
			$this->draw_relay_illustration($img, $teal, $teal_dark, $ink, $metal, $red, $variant);
		} else {
			$this->draw_board_illustration($img, $teal, $teal_dark, $ink, $metal, $blue, $variant);
		}

		imagestring($img, 5, 120, 95, $this->image_safe_text($title, 58), $ink);
		imagestring($img, 4, 120, 128, 'Kresleny technicky nahlad - ' . strtoupper($variant), $teal_dark);
		$this->draw_wrapped($img, 'Rozlozenie pinov, konektorov a osadenie overit podla konkretnej dodavky.', 3, 120, 1040, 880, $muted);

		$saved = imagejpeg($img, $path, 92);
		imagedestroy($img);
		if (!$saved) {
			return new WP_Error('komarena_pf_image_save', 'Kreslený kontrolný náhľad sa nepodarilo uložiť.');
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$filetype = wp_check_filetype($filename, null);
		$attachment_id = wp_insert_attachment(array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field($title . ' - ' . $variant_label),
			'post_content'   => '',
			'post_status'    => 'inherit',
		), $path, $product_id);
		if (is_wp_error($attachment_id)) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata($attachment_id, $path);
		wp_update_attachment_metadata($attachment_id, $metadata);
		update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($title . ' - kreslený technický náhľad - ' . $variant_label));

		return (int) $attachment_id;
	}

	private function draw_board_illustration($img, $teal, $teal_dark, $ink, $metal, $blue, $variant) {
		$x1 = 'detail' === $variant ? 245 : 285;
		$y1 = 'technical' === $variant ? 330 : 380;
		$x2 = 'detail' === $variant ? 955 : 915;
		$y2 = 'technical' === $variant ? 735 : 705;
		imagefilledrectangle($img, $x1, $y1, $x2, $y2, $teal);
		for ($i = 0; $i < 5; $i++) {
			imagerectangle($img, $x1 + $i, $y1 + $i, $x2 - $i, $y2 - $i, $teal_dark);
		}
		for ($i = 0; $i < 18; $i++) {
			$py = $y1 + 34 + ($i * 17);
			imagefilledrectangle($img, $x1 - 20, $py, $x1 + 8, $py + 8, $metal);
			imagefilledrectangle($img, $x2 - 8, $py, $x2 + 20, $py + 8, $metal);
		}
		imagefilledrectangle($img, $x1 + 250, $y1 + 100, $x1 + 455, $y1 + 245, $ink);
		imagestring($img, 3, $x1 + 300, $y1 + 162, 'MCU', imagecolorallocate($img, 240, 248, 248));
		imagefilledrectangle($img, $x1 + 45, $y1 + 125, $x1 + 155, $y1 + 215, $metal);
		imagestring($img, 3, $x1 + 72, $y1 + 160, 'USB', $ink);
		imagefilledellipse($img, $x2 - 85, $y2 - 70, 54, 54, $blue);
		if ('technical' === $variant) {
			imageline($img, $x1 - 20, $y1 + 80, 190, 790, $teal_dark);
			imageline($img, $x2 + 20, $y1 + 80, 1010, 790, $teal_dark);
			imagestring($img, 4, 180, 805, 'pin layout overit', $ink);
			imagestring($img, 4, 875, 805, 'porty/konektory', $ink);
		}
	}

	private function draw_sensor_illustration($img, $teal, $teal_dark, $ink, $metal, $green, $variant) {
		imagefilledrectangle($img, 390, 360, 810, 690, $teal);
		imagerectangle($img, 390, 360, 810, 690, $teal_dark);
		imagefilledrectangle($img, 505, 450, 695, 610, $green);
		imagerectangle($img, 505, 450, 695, 610, $ink);
		for ($i = 0; $i < 6; $i++) {
			imagefilledrectangle($img, 440 + ($i * 58), 695, 462 + ($i * 58), 770, $metal);
		}
		for ($i = 0; $i < 5; $i++) {
			imageline($img, 530, 475 + ($i * 24), 670, 475 + ($i * 24), $ink);
		}
		if ('detail' === $variant || 'technical' === $variant) {
			imagestring($img, 4, 430, 820, 'VCC / GND / DATA alebo I2C podla verzie', $ink);
		}
	}

	private function draw_relay_illustration($img, $teal, $teal_dark, $ink, $metal, $red, $variant) {
		imagefilledrectangle($img, 300, 360, 900, 710, $teal);
		imagerectangle($img, 300, 360, 900, 710, $teal_dark);
		imagefilledrectangle($img, 455, 430, 680, 610, $red);
		imagerectangle($img, 455, 430, 680, 610, $ink);
		imagestring($img, 4, 510, 505, 'RELE', imagecolorallocate($img, 255, 245, 245));
		imagefilledrectangle($img, 720, 450, 850, 590, $metal);
		for ($i = 0; $i < 3; $i++) {
			imagefilledrectangle($img, 740 + ($i * 34), 600, 760 + ($i * 34), 680, $ink);
		}
		for ($i = 0; $i < 3; $i++) {
			imagefilledrectangle($img, 330 + ($i * 38), 710, 350 + ($i * 38), 770, $metal);
		}
		if ('technical' === $variant) {
			imagestring($img, 4, 320, 820, 'COM / NO / NC a VCC / GND / IN overit na module', $ink);
		}
	}

	private function image_safe_text($text, $max = 58) {
		$text = strtoupper(remove_accents(wp_strip_all_tags((string) $text)));
		$text = preg_replace('/[^A-Z0-9 \-\/\.]/', '', $text);
		$text = trim(preg_replace('/\s+/', ' ', $text));

		return strlen($text) > $max ? substr($text, 0, $max - 3) . '...' : $text;
	}

	private function generate_review_image($filename, $product_id, $title, $variant_label) {
		if (!function_exists('imagecreatetruecolor')) {
			return new WP_Error('komarena_pf_gd_missing', 'Grafická knižnica PHP nie je dostupná, dočasný kontrolný obrázok sa nedá vytvoriť.');
		}

		$upload = wp_upload_dir();
		if (!empty($upload['error'])) {
			return new WP_Error('komarena_pf_upload_dir', $upload['error']);
		}

		if (!wp_mkdir_p($upload['path'])) {
			return new WP_Error('komarena_pf_upload_dir', 'Nahrávací adresár sa nepodarilo vytvoriť.');
		}

		$filename = wp_unique_filename($upload['path'], sanitize_file_name($filename));
		$path     = trailingslashit($upload['path']) . $filename;

		$width = 1200;
		$height = 1200;
		$img = imagecreatetruecolor($width, $height);
		$bg = imagecolorallocate($img, 248, 250, 252);
		$white = imagecolorallocate($img, 255, 255, 255);
		$shadow = imagecolorallocate($img, 222, 231, 235);
		$teal = imagecolorallocate($img, 15, 159, 154);
		$text = imagecolorallocate($img, 30, 41, 59);
		$muted = imagecolorallocate($img, 100, 116, 139);

		imagefilledrectangle($img, 0, 0, $width, $height, $bg);
		imagefilledellipse($img, 610, 760, 620, 90, $shadow);
		imagefilledroundedrectangle($img, 300, 330, 900, 720, 28, $white);
		imagerectangle($img, 300, 330, 900, 720, $teal);
		imagefilledrectangle($img, 360, 390, 840, 610, imagecolorallocate($img, 236, 253, 245));
		imagerectangle($img, 360, 390, 840, 610, $teal);

		for ($x = 330; $x <= 870; $x += 54) {
			imagefilledrectangle($img, $x, 292, $x + 22, 330, $teal);
			imagefilledrectangle($img, $x, 720, $x + 22, 758, $teal);
		}

		imagestring($img, 5, 385, 448, 'KOMARENA KONTROLNY OBRAZOK', $teal);
		$this->draw_wrapped($img, $title, 5, 260, 830, 680, $text);
		$this->draw_wrapped($img, $variant_label, 4, 310, 885, 760, $muted);
		$this->draw_wrapped($img, 'Nahrajte overeny technicky verny obrazok pred publikovanim.', 4, 270, 930, 680, $muted);

		$saved = imagejpeg($img, $path, 92);
		imagedestroy($img);
		if (!$saved) {
			return new WP_Error('komarena_pf_image_save', 'Dočasný kontrolný obrázok sa nepodarilo uložiť.');
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filetype = wp_check_filetype($filename, null);
		$attachment_id = wp_insert_attachment(array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field($title . ' - ' . $variant_label),
			'post_content'   => '',
			'post_status'    => 'inherit',
		), $path, $product_id);

		if (is_wp_error($attachment_id)) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata($attachment_id, $path);
		wp_update_attachment_metadata($attachment_id, $metadata);
		update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($title . ' - ' . $variant_label));

		return (int) $attachment_id;
	}

	private function draw_wrapped($img, $text, $font, $x, $y, $max_width, $color) {
		$words = preg_split('/\s+/', (string) $text);
		$line = '';
		$line_height = 24;
		foreach ($words as $word) {
			$test = trim($line . ' ' . $word);
			if (imagefontwidth($font) * strlen($test) > $max_width && '' !== $line) {
				imagestring($img, $font, $x, $y, $line, $color);
				$line = $word;
				$y += $line_height;
			} else {
				$line = $test;
			}
		}

		if ('' !== $line) {
			imagestring($img, $font, $x, $y, $line, $color);
		}
	}
}

if (!function_exists('imagefilledroundedrectangle')) {
	function imagefilledroundedrectangle($image, $x1, $y1, $x2, $y2, $radius, $color) {
		imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
		imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
		imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
		imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
		imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
		imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
	}
}
