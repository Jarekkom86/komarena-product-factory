<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Research_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function research($product_name, $options = array()) {
		$name  = sanitize_text_field($product_name);
		$slug  = sanitize_title($name);
		$lower = strtolower(remove_accents($name));

		$profile     = $this->detect_profile($lower);
		$source_urls = $this->source_urls($options);
		if ($this->plugin->settings->get('agent_active_source_discovery', 1)) {
			$source_urls = array_values(array_unique(array_merge($source_urls, $this->active_source_urls($name, $profile))));
		}
		$memory      = $this->plugin && !empty($this->plugin->memory) ? $this->plugin->memory->match_product($name, 3) : array();
		$supplier    = !empty($options['supplier_data']) && is_array($options['supplier_data']) ? $options['supplier_data'] : array();
		$warnings    = array();
		$confidence  = $profile['confidence'];

		if (!empty($supplier)) {
			$source_urls = array_values(array_unique(array_merge($source_urls, array_filter(array(esc_url_raw($supplier['url'] ?? ''))))));
			if (!empty($supplier['name'])) {
				$profile['title'] = sanitize_text_field($supplier['name']);
			}
			if (!empty($supplier['description'])) {
				$profile['typical_use'] = $this->supplier_use_hint($supplier['description'], $profile['typical_use']);
			}
			$warnings[] = 'Dodavatelska URL bola nacitana automaticky. Technicke parametre treba stale overit v datasheete alebo dokumentacii.';
			$confidence = min(0.9, $confidence + 0.05);
		}

		$evidence = $this->source_evidence($source_urls, $name);

		if (empty($source_urls)) {
			$warnings[] = 'Nie su prilozene overene produktove zdroje; technicke udaje su konzervativne a musia sa overit pred publikovanim.';
			$confidence = min($confidence, 0.58);
		} elseif ('verified' === ($evidence['status'] ?? '')) {
			$confidence = min(0.96, $confidence + 0.24);
		} elseif ('weak' === ($evidence['status'] ?? '')) {
			$warnings[] = 'Prilozene zdroje sa podarilo nacitat, ale agent nenasiel dost silnu zhodu s konkretnym produktom.';
			$confidence = min($confidence + 0.08, 0.7);
		} else {
			$confidence = min(0.95, $confidence + 0.18);
		}

		if (!empty($memory)) {
			$profile = $this->merge_memory($profile, $memory);
			$confidence = min(0.97, $confidence + 0.08);
		}

		$fact_verification = $this->fact_verification($profile, $evidence, $name);
		if (empty($fact_verification['verified'])) {
			$warnings[] = $fact_verification['message'];
			$confidence = min($confidence, 0.64);
		}

		$image_sources = is_array($options['image_sources'] ?? null) ? $options['image_sources'] : array();
		if (empty($image_sources) && !empty($evidence['image_candidate_records'])) {
			foreach (array('main', 'angle', 'detail', 'technical') as $index => $variant) {
				if (!empty($evidence['image_candidate_records'][$index])) {
					$record = $evidence['image_candidate_records'][$index];
					$record['role'] = $variant;
					$image_sources[$variant] = $record;
				}
			}
		} elseif (empty($image_sources) && !empty($evidence['image_candidates'])) {
			foreach (array('main', 'angle', 'detail', 'technical') as $index => $variant) {
				if (!empty($evidence['image_candidates'][$index])) {
					$image_sources[$variant] = $evidence['image_candidates'][$index];
				}
			}
		}

		$result = array(
			'input_name'       => $name,
			'normalized_name'  => $profile['title'] ? $profile['title'] : $name,
			'slug'             => $slug,
			'model'            => $profile['model'],
			'chip'             => $profile['chip'],
			'ports'            => $profile['ports'],
			'connectors'       => $profile['connectors'],
			'pin_layout'       => $profile['pin_layout'],
			'appearance'       => $profile['appearance'],
			'typical_use'      => $profile['typical_use'],
			'categories'       => $profile['categories'],
			'specs'            => $profile['specs'],
			'compatibility'    => $profile['compatibility'],
			'package_contents' => $profile['package_contents'],
			'source_urls'      => $source_urls,
			'source_evidence'  => $evidence,
			'fact_verification'=> $fact_verification,
			'verified_facts_policy' => 'Technicke tvrdenia v popise musia byt naviazane na overeny zdroj; pri neistote sa produkt blokuje v needs_review.',
			'image_sources'    => $image_sources,
			'supplier_data'    => $supplier,
			'memory_matches'   => $this->memory_summary($memory),
			'warnings'         => $warnings,
			'confidence_score' => round($confidence, 2),
		);

		/**
		 * Integration point for a verified research provider. The provider should
		 * only enrich the result from manufacturer docs, datasheets, distributors,
		 * or KomArena internal references.
		 */
		$result = apply_filters('komarena_pf_research_result', $result, $name, $options);

		$this->plugin->logger->log('info', 'Research completed', 'research', array(
			'product_name' => $name,
			'sources'      => $result['source_urls'],
			'warnings'     => $result['warnings'],
			'confidence'   => $result['confidence_score'],
		), $options['queue_id'] ?? null);

		return $result;
	}

	private function source_urls($options) {
		$urls = array();

		if (!empty($options['source_urls']) && is_array($options['source_urls'])) {
			foreach ($options['source_urls'] as $url) {
				$url = esc_url_raw($url);
				if ($url) {
					$urls[] = $url;
				}
			}
		}

		$trusted = (string) $this->plugin->settings->get('trusted_source_urls', '');
		foreach (preg_split('/\r\n|\r|\n/', $trusted) as $url) {
			$url = esc_url_raw(trim($url));
			if ($url) {
				$urls[] = $url;
			}
		}

		return array_values(array_unique($urls));
	}

	private function source_evidence($source_urls, $product_name) {
		$source_urls = array_values(array_unique(array_filter(array_map('esc_url_raw', (array) $source_urls))));
		$evidence = array(
			'status'               => empty($source_urls) ? 'none' : 'unreachable',
			'checked_at'           => current_time('mysql'),
			'source_count'         => count($source_urls),
			'fetched'              => 0,
			'matched_sources'      => 0,
			'product_schema_count' => 0,
			'datasheet_urls'       => array(),
			'image_candidates'     => array(),
			'image_candidate_records' => array(),
			'sources'              => array(),
		);

		if (empty($source_urls)) {
			return $evidence;
		}

		foreach ($source_urls as $url) {
			$row = array(
				'url'              => $url,
				'status'           => 'unreachable',
				'http_code'        => 0,
				'type'             => 'reference',
				'title'            => '',
				'meta_description' => '',
				'match_score'      => 0,
				'matched_tokens'   => array(),
				'product_schema'   => false,
				'datasheet_urls'   => array(),
				'image_candidates' => array(),
				'error'            => '',
			);

			$response = wp_remote_get($url, array(
				'timeout'             => 12,
				'redirection'         => 4,
				'limit_response_size' => 700000,
				'user-agent'          => 'KomArena Produktovy Agent/' . KOMARENA_PF_VERSION,
			));

			if (is_wp_error($response)) {
				$row['error'] = $response->get_error_message();
				$evidence['sources'][] = $row;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code($response);
			$row['http_code'] = $code;
			if ($code < 200 || $code >= 400) {
				$row['error'] = 'HTTP ' . $code;
				$evidence['sources'][] = $row;
				continue;
			}

			$body = (string) wp_remote_retrieve_body($response);
			$content_type_header = wp_remote_retrieve_header($response, 'content-type');
			$content_type = is_array($content_type_header) ? implode(' ', $content_type_header) : (string) $content_type_header;
			$is_pdf = (bool) preg_match('/\.pdf(?:\?.*)?$/i', $url) || false !== stripos($content_type, 'pdf');
			$row['status'] = 'fetched';
			$evidence['fetched']++;

			if ($is_pdf) {
				$row['type'] = 'datasheet_or_pdf';
				$row['title'] = basename((string) wp_parse_url($url, PHP_URL_PATH));
				$row['datasheet_urls'] = array($url);
				$match = $this->token_match_score($product_name, $url . ' ' . $row['title']);
			} else {
				$product_schema = $this->extract_jsonld_product($body);
				$row['product_schema'] = !empty($product_schema['found']);
				$row['title'] = $this->extract_title($body);
				$row['meta_description'] = $this->extract_meta_description($body);
				$row['type'] = $this->source_type($url, $body, $row['product_schema']);
				$row['datasheet_urls'] = $this->extract_datasheet_links($body, $url);
				$row['image_candidates'] = $this->extract_source_images($body, $url);

				$haystack = implode(' ', array(
					$url,
					$row['title'],
					$row['meta_description'],
					$product_schema['text'] ?? '',
				));
				$match = $this->token_match_score($product_name, $haystack);
			}

			$row['match_score'] = $match['score'];
			$row['matched_tokens'] = $match['tokens'];
			$is_matched = $row['match_score'] >= 2
				|| ($row['product_schema'] && $row['match_score'] >= 1)
				|| ('datasheet_or_pdf' === $row['type'] && $row['match_score'] >= 1)
				|| ('internal_komarena' === $row['type'] && $row['match_score'] >= 1);

			if ($row['product_schema']) {
				$evidence['product_schema_count']++;
			}
			if ($is_matched) {
				$evidence['matched_sources']++;
			}

			$row['source_score'] = $this->source_rank_score($row, $is_matched);
			if ($is_matched) {
				$evidence['datasheet_urls'] = array_merge($evidence['datasheet_urls'], $row['datasheet_urls']);
				$evidence['image_candidates'] = array_merge($evidence['image_candidates'], $row['image_candidates']);
				$evidence['image_candidate_records'] = array_merge($evidence['image_candidate_records'], $this->image_candidate_records($row));
			}
			$evidence['sources'][] = $row;
		}

		usort($evidence['sources'], function($a, $b) {
			return (int) ($b['source_score'] ?? 0) <=> (int) ($a['source_score'] ?? 0);
		});
		$evidence['datasheet_urls'] = array_values(array_unique(array_filter($evidence['datasheet_urls'])));
		$evidence['image_candidates'] = array_values(array_slice(array_unique(array_filter($evidence['image_candidates'])), 0, 12));
		$evidence['image_candidate_records'] = $this->unique_image_candidate_records($evidence['image_candidate_records']);
		if ($evidence['matched_sources'] > 0) {
			$evidence['status'] = 'verified';
		} elseif ($evidence['fetched'] > 0) {
			$evidence['status'] = 'weak';
		}

		return $evidence;
	}

	private function fact_verification($profile, $evidence, $product_name) {
		$sources = (array) ($evidence['sources'] ?? array());
		$verified_sources = array();
		$has_reference_source = false;
		$has_listing_source = false;
		$allowed_types = array('internal_komarena', 'manufacturer_or_docs', 'datasheet_or_pdf', 'distributor_or_listing');

		foreach ($sources as $row) {
			$type = sanitize_key($row['type'] ?? 'reference');
			$status = sanitize_key($row['status'] ?? '');
			$match_score = (int) ($row['match_score'] ?? 0);
			if ('fetched' !== $status || $match_score < 1 || !in_array($type, $allowed_types, true)) {
				continue;
			}

			$verified_sources[] = array(
				'url'         => esc_url_raw($row['url'] ?? ''),
				'type'        => $type,
				'title'       => sanitize_text_field($row['title'] ?? ''),
				'match_score' => $match_score,
			);

			if (in_array($type, array('internal_komarena', 'manufacturer_or_docs', 'datasheet_or_pdf'), true)) {
				$has_reference_source = true;
			}
			if (in_array($type, array('internal_komarena', 'distributor_or_listing'), true)) {
				$has_listing_source = true;
			}
		}

		$is_clone = $this->is_clone_product($product_name, $profile);
		$verified = 'verified' === ($evidence['status'] ?? '') && !empty($verified_sources) && ($has_reference_source || $has_listing_source);
		$reasons = array();

		if ('verified' !== ($evidence['status'] ?? '')) {
			$verified = false;
			$reasons[] = 'zdroje nemaju verified status';
		}
		if (empty($verified_sources)) {
			$verified = false;
			$reasons[] = 'chyba aspon jeden nacitany a zhodny overeny zdroj';
		}
		if ($is_clone && !$has_listing_source) {
			$verified = false;
			$reasons[] = 'pri klone chyba zhodny realny klon/distributor listing';
		}

		return array(
			'verified'             => $verified,
			'status'               => $verified ? 'verified' : 'needs_review',
			'policy'               => 'No invented facts: every specific technical claim must be traceable to verified manufacturer documentation, datasheet, verified distributor/listing, or KomArena internal reference.',
			'message'              => $verified ? 'Technicke fakty su zdrojovo overene.' : 'Technicke fakty nie su 100 % zdrojovo overene (' . implode('; ', array_unique($reasons)) . '). Produkt ostava needs_review a neistoty musia byt oznacene opatrne.',
			'is_clone_or_variant'  => $is_clone,
			'has_reference_source' => $has_reference_source,
			'has_listing_source'   => $has_listing_source,
			'verified_sources'     => array_slice($verified_sources, 0, 8),
			'unverified_fields'    => $verified ? array() : array('model', 'chip', 'ports', 'connectors', 'pin_layout', 'specs', 'compatibility', 'appearance'),
		);
	}

	private function is_clone_product($product_name, $profile) {
		$haystack = strtolower(remove_accents((string) $product_name . ' ' . ($profile['title'] ?? '') . ' ' . ($profile['model'] ?? '') . ' ' . ($profile['pin_layout'] ?? '')));
		return (bool) preg_match('/\b(klon|clone|kompatibil|compatible|ch340|variant)\b/i', $haystack);
	}

	private function active_source_urls($product_name, $profile) {
		$urls = array();
		foreach ((array) ($profile['source_urls'] ?? array()) as $url) {
			$url = esc_url_raw($url);
			if ($url) {
				$urls[] = $url;
			}
		}

		foreach ($this->targeted_source_urls($product_name, $profile) as $url) {
			$url = esc_url_raw($url);
			if ($url) {
				$urls[] = $url;
			}
		}

		$limit = max(4, min(30, (int) $this->plugin->settings->get('agent_source_discovery_limit', 12)));
		return array_values(array_slice(array_unique($urls), 0, $limit));
	}

	private function targeted_source_urls($product_name, $profile) {
		$name = strtolower(remove_accents((string) $product_name));
		$model = strtolower(remove_accents((string) ($profile['model'] ?? '')));
		$haystack = $name . ' ' . $model;
		$urls = array();

		if (false !== strpos($haystack, 'mega') || false !== strpos($haystack, 'atmega2560')) {
			$urls[] = 'https://docs.arduino.cc/hardware/mega-2560/';
			$urls[] = 'https://docs.arduino.cc/resources/datasheets/A000067-datasheet.pdf';
			$urls[] = 'https://ww1.microchip.com/downloads/en/DeviceDoc/ATmega640-1280-1281-2560-2561-Datasheet-DS40002211A.pdf';
			$urls[] = 'https://techfun.sk/en/produkt/arduino-mega-precizny-klon/';
			$urls[] = 'https://dratek.cz/arduino-platforma/1313-eses-klon-arduino-mega-ch340.html';
			$urls[] = 'https://dratek.cz/docs/produkty/0/763/eses1464645394.pdf';
			$urls[] = 'https://opencircuit.nl/product/arduino-mega-2560-clone';
			$urls[] = 'https://electropeak.com/mega-2560-r3-arduino';
			$urls[] = 'https://www.aliexpress.com/w/wholesale-mega-2560-r3-clone.html';
		}
		if (false !== strpos($haystack, 'uno') || false !== strpos($haystack, 'atmega328')) {
			$urls[] = 'https://docs.arduino.cc/hardware/uno-rev3/';
			$urls[] = 'https://docs.arduino.cc/resources/datasheets/A000066-datasheet.pdf';
			$urls[] = 'https://ww1.microchip.com/downloads/en/DeviceDoc/ATmega328P-Data-Sheet-DS40002061B.pdf';
			$urls[] = 'https://dratek.cz/arduino/1258-eses-klon-arduino-uno-r3-ch340.html';
			$urls[] = 'https://dratek.cz/docs/produkty/0/761/eses1459967190.pdf';
			$urls[] = 'https://opencircuit.nl/product/arduino-uno-r3-clone';
			$urls[] = 'https://www.aliexpress.com/w/wholesale-uno-r3-ch340-clone.html';
		}
		if (false !== strpos($haystack, 'hc-sr04') || false !== strpos($haystack, 'hcsr04')) {
			$urls[] = 'https://cdn.sparkfun.com/datasheets/Sensors/Proximity/HCSR04.pdf';
			$urls[] = 'https://learn.sparkfun.com/tutorials/hc-sr04-ultrasonic-distance-sensor-hookup-guide/all';
			$urls[] = 'https://www.sparkfun.com/ultrasonic-distance-sensor-hc-sr04.html';
			$urls[] = 'https://esphome.io/components/sensor/ultrasonic.html';
		}
		if (false !== strpos($haystack, 'bme280')) {
			$urls[] = 'https://www.bosch-sensortec.com/products/environmental-sensors/humidity-sensors-bme280/';
			$urls[] = 'https://www.bosch-sensortec.com/media/boschsensortec/downloads/datasheets/bst-bme280-ds002.pdf';
			$urls[] = 'https://learn.adafruit.com/adafruit-bme280-humidity-barometric-pressure-temperature-sensor-breakout';
		}
		if (false !== strpos($haystack, 'bmp280')) {
			$urls[] = 'https://www.bosch-sensortec.com/products/environmental-sensors/pressure-sensors/bmp280/';
			$urls[] = 'https://www.bosch-sensortec.com/media/boschsensortec/downloads/datasheets/bst-bmp280-ds001.pdf';
			$urls[] = 'https://learn.adafruit.com/adafruit-bmp280-barometric-pressure-plus-temperature-sensor-breakout';
		}
		if (false !== strpos($haystack, 'esp32')) {
			$urls[] = 'https://www.espressif.com/en/products/socs/esp32';
			$urls[] = 'https://docs.espressif.com/projects/esp-idf/en/latest/esp32/';
			$urls[] = 'https://esphome.io/components/esp32.html';
		}
		if (false !== strpos($haystack, 'esp8266') || false !== strpos($haystack, 'nodemcu') || false !== strpos($haystack, 'd1 mini')) {
			$urls[] = 'https://www.espressif.com/en/products/socs/esp8266';
			$urls[] = 'https://docs.espressif.com/projects/esp8266-rtos-sdk/en/latest/';
			$urls[] = 'https://esphome.io/components/esp8266.html';
		}
		if (false !== strpos($haystack, 'lm2596')) {
			$urls[] = 'https://www.ti.com/lit/ds/symlink/lm2596.pdf';
			$urls[] = 'https://www.ti.com/product/LM2596';
		}
		if (false !== strpos($haystack, 'rp2040') || false !== strpos($haystack, 'pico')) {
			$urls[] = 'https://www.raspberrypi.com/documentation/microcontrollers/pico-series.html';
			$urls[] = 'https://datasheets.raspberrypi.com/pico/pico-datasheet.pdf';
			$urls[] = 'https://datasheets.raspberrypi.com/rp2040/rp2040-datasheet.pdf';
		}

		return $urls;
	}

	private function source_type($url, $html, $has_product_schema) {
		$host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
		$path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));

		if (false !== strpos($host, 'komarena.sk')) {
			return 'internal_komarena';
		}
		if (preg_match('/datasheet|data-sheet|manual|download|\.pdf$/i', $url . ' ' . $path)) {
			return 'datasheet_or_pdf';
		}
		if (preg_match('/docs|documentation|developer|support|wiki|learn|reference/i', $host . ' ' . $path)) {
			return 'manufacturer_or_docs';
		}
		if ($has_product_schema || preg_match('/eshop|shop|store|product|produkt|distributor|mouser|digikey|tme|farnell|rs-online|alza|techfun|dratek|opencircuit|electropeak|aliexpress|alitools|diolut|direnc|sumozade/i', $host . ' ' . $path)) {
			return 'distributor_or_listing';
		}

		return 'reference';
	}

	private function source_rank_score($row, $is_matched) {
		$score = $is_matched ? 20 : 0;
		$type = sanitize_key($row['type'] ?? 'reference');
		$weights = array(
			'internal_komarena'        => 35,
			'manufacturer_or_docs'     => 30,
			'datasheet_or_pdf'         => 28,
			'distributor_or_listing'   => 20,
			'reference'                => 8,
		);
		$score += $weights[$type] ?? 5;
		$score += min(20, (int) ($row['match_score'] ?? 0) * 4);
		if (!empty($row['product_schema'])) {
			$score += 8;
		}
		if (!empty($row['datasheet_urls'])) {
			$score += 6;
		}
		if (!empty($row['image_candidates'])) {
			$score += 4;
		}

		return $score;
	}

	private function image_candidate_records($row) {
		$out = array();
		$source_url = esc_url_raw($row['url'] ?? '');
		$source_type = sanitize_key($row['type'] ?? 'reference');
		foreach ((array) ($row['image_candidates'] ?? array()) as $url) {
			$url = esc_url_raw($url);
			if (!$url) {
				continue;
			}
			$out[] = array(
				'url'                => $url,
				'source_url'         => $source_url,
				'source_host'        => strtolower((string) wp_parse_url($source_url, PHP_URL_HOST)),
				'source_type'        => $this->image_source_type_from_row_type($source_type),
				'usage_permission'   => 'needs_rights_review',
				'watermark_status'   => 'clean',
				'seller_logo_status' => 'clean',
				'ai_generated'       => false,
				'generated'          => false,
				'notes'              => 'Automaticky najdeny kandidat realneho produktoveho obrazka zo zdroja: ' . $source_url,
			);
			if (count($out) >= 4) {
				break;
			}
		}

		return $out;
	}

	private function image_source_type_from_row_type($type) {
		$type = sanitize_key($type);
		if ('internal_komarena' === $type) {
			return 'internal_komarena_media';
		}
		if (in_array($type, array('manufacturer_or_docs', 'datasheet_or_pdf'), true)) {
			return 'manufacturer_or_official';
		}
		if ('distributor_or_listing' === $type) {
			return 'verified_distributor_or_listing';
		}

		return 'external_web';
	}

	private function unique_image_candidate_records($records) {
		$out = array();
		$seen = array();
		foreach ((array) $records as $record) {
			if (!is_array($record)) {
				continue;
			}
			$url = esc_url_raw($record['url'] ?? '');
			if (!$url || isset($seen[$url])) {
				continue;
			}
			$seen[$url] = true;
			$out[] = $record;
			if (count($out) >= 12) {
				break;
			}
		}

		return $out;
	}

	private function extract_title($html) {
		if (preg_match('/<title[^>]*>(.*?)<\/title>/is', (string) $html, $match)) {
			return sanitize_text_field(html_entity_decode(wp_strip_all_tags($match[1]), ENT_QUOTES));
		}

		return '';
	}

	private function extract_meta_description($html) {
		if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', (string) $html, $match)
			|| preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\']/i', (string) $html, $match)) {
			return sanitize_text_field(html_entity_decode($match[1], ENT_QUOTES));
		}

		return '';
	}

	private function extract_jsonld_product($html) {
		$result = array('found' => false, 'text' => '');
		if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', (string) $html, $matches)) {
			return $result;
		}

		foreach ($matches[1] as $json) {
			$data = json_decode(html_entity_decode(trim($json), ENT_QUOTES), true);
			if (!is_array($data)) {
				continue;
			}
			$text = $this->jsonld_product_text($data);
			if ('' !== $text) {
				$result['found'] = true;
				$result['text'] .= ' ' . $text;
			}
		}

		$result['text'] = trim($result['text']);
		return $result;
	}

	private function jsonld_product_text($data) {
		if (!is_array($data)) {
			return '';
		}

		$type = $data['@type'] ?? '';
		$types = is_array($type) ? array_map('strtolower', $type) : array(strtolower((string) $type));
		$text = '';
		if (in_array('product', $types, true)) {
			foreach (array('name', 'description', 'sku', 'mpn', 'model', 'brand') as $key) {
				if (!empty($data[$key])) {
					$text .= ' ' . (is_array($data[$key]) ? wp_json_encode($data[$key]) : $data[$key]);
				}
			}
		}

		foreach ($data as $value) {
			if (is_array($value)) {
				$text .= ' ' . $this->jsonld_product_text($value);
			}
		}

		return trim(wp_strip_all_tags((string) $text));
	}

	private function extract_source_images($html, $base_url) {
		$urls = array();
		if (preg_match_all('/<meta[^>]+(?:property|name)=["\'](?:og:image|twitter:image)["\'][^>]+content=["\']([^"\']+)["\']/i', (string) $html, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}
		if (preg_match_all('/"image"\s*:\s*(?:"([^"]+)"|\[([^\]]+)\])/i', (string) $html, $matches, PREG_SET_ORDER)) {
			foreach ($matches as $match) {
				if (!empty($match[1])) {
					$urls[] = $match[1];
				}
				if (!empty($match[2]) && preg_match_all('/"([^"]+)"/', $match[2], $nested)) {
					$urls = array_merge($urls, $nested[1]);
				}
			}
		}
		if (preg_match_all('/<img[^>]+(?:src|data-src|data-large_image)=["\']([^"\']+)["\']/i', (string) $html, $matches)) {
			$urls = array_merge($urls, $matches[1]);
		}

		$out = array();
		foreach ($urls as $url) {
			$url = $this->absolute_url($url, $base_url);
			if ($this->image_candidate_allowed($url)) {
				$out[] = $url;
			}
		}

		return array_values(array_unique($out));
	}

	private function extract_datasheet_links($html, $base_url) {
		$out = array();
		if (!preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', (string) $html, $matches, PREG_SET_ORDER)) {
			return $out;
		}

		foreach ($matches as $match) {
			$label = strtolower(remove_accents(wp_strip_all_tags($match[2])));
			$url = $this->absolute_url($match[1], $base_url);
			if ($url && (preg_match('/\.pdf(?:\?.*)?$/i', $url) || preg_match('/datasheet|data sheet|manual|documentation|pinout|schema|schematic/i', $label . ' ' . $url))) {
				$out[] = $url;
			}
		}

		return array_values(array_unique($out));
	}

	private function absolute_url($url, $base_url) {
		$url = trim(html_entity_decode((string) $url, ENT_QUOTES));
		if ('' === $url || 0 === strpos($url, 'data:') || 0 === strpos($url, '#')) {
			return '';
		}
		if (preg_match('/^https?:\/\//i', $url)) {
			return esc_url_raw($url);
		}

		$parts = wp_parse_url($base_url);
		$scheme = $parts['scheme'] ?? 'https';
		$host = $parts['host'] ?? '';
		if (!$host) {
			return '';
		}
		if (0 === strpos($url, '//')) {
			return esc_url_raw($scheme . ':' . $url);
		}
		if (0 === strpos($url, '/')) {
			return esc_url_raw($scheme . '://' . $host . $url);
		}

		$path = $parts['path'] ?? '/';
		$base_path = trailingslashit(dirname($path));

		return esc_url_raw($scheme . '://' . $host . $base_path . $url);
	}

	private function image_candidate_allowed($url) {
		if (!$url || !preg_match('/\.(jpe?g|png|webp)(?:\?.*)?$/i', $url)) {
			return false;
		}

		$normalized = strtolower(rawurldecode((string) $url));
		if (preg_match('/logo|icon|ico-|ico_|sprite|avatar|favicon|placeholder|banner|payment|loader|spinner|thumb-?small|facebook|instagram|social|share|badge|seal/i', $normalized)) {
			return false;
		}

		return true;
	}

	private function token_match_score($product_name, $text) {
		$text = strtolower(remove_accents(wp_strip_all_tags(html_entity_decode((string) $text, ENT_QUOTES))));
		$tokens = preg_split('/[^a-z0-9]+/', strtolower(remove_accents((string) $product_name)));
		$matched = array();
		$score = 0;
		foreach ($tokens as $token) {
			$token = trim($token);
			if (strlen($token) < 3 || in_array($token, array('pre', 'modul', 'doska', 'senzor', 'klon', 'r3'), true)) {
				continue;
			}
			if (false !== strpos($text, $token)) {
				$matched[] = $token;
				$score += strlen($token) >= 5 ? 2 : 1;
			}
		}

		return array(
			'score'  => $score,
			'tokens' => array_values(array_unique($matched)),
		);
	}

	private function merge_memory($profile, $memory_matches) {
		foreach ($memory_matches as $memory) {
			$data = $memory['data_decoded'] ?? array();
			if (empty($data) || !is_array($data)) {
				continue;
			}

			foreach (array('chip', 'model', 'ports', 'connectors', 'pin_layout') as $key) {
				if (!empty($data[$key]) && false !== strpos($profile[$key] ?? '', 'Podla konkretnej verzie')) {
					$profile[$key] = $data[$key];
				}
			}
			if (!empty($data['specs']) && is_array($data['specs'])) {
				$profile['specs'] = array_merge((array) $profile['specs'], $data['specs']);
			}
			if (!empty($data['compatibility']) && is_array($data['compatibility'])) {
				$profile['compatibility'] = array_values(array_unique(array_merge((array) $profile['compatibility'], $data['compatibility'])));
			}
			if (!empty($data['categories']) && is_array($data['categories'])) {
				$profile['categories'] = array_values(array_unique(array_merge((array) $profile['categories'], $data['categories'])));
			}
		}

		return $profile;
	}

	private function supplier_use_hint($description, $fallback) {
		$description = trim(wp_strip_all_tags((string) $description));
		if ('' === $description) {
			return $fallback;
		}

		return wp_trim_words($description, 28, '.');
	}

	private function memory_summary($memory_matches) {
		$out = array();
		foreach ((array) $memory_matches as $memory) {
			$out[] = array(
				'key'        => $memory['memory_key'] ?? '',
				'title'      => $memory['title'] ?? '',
				'confidence' => $memory['confidence'] ?? '',
				'score'      => $memory['match_score'] ?? '',
			);
		}

		return $out;
	}

	private function detect_profile($lower) {
		$default = array(
			'title'            => '',
			'model'            => 'Podla konkretnej verzie / dodavky sa moze lisit.',
			'chip'             => 'Podla konkretnej verzie / dodavky sa moze lisit.',
			'ports'            => 'Podla konkretnej verzie / dodavky sa moze lisit.',
			'connectors'       => 'Podla konkretnej verzie / dodavky sa moze lisit.',
			'pin_layout'       => 'Podla konkretnej verzie / dodavky sa moze lisit.',
			'appearance'       => 'Elektronicky modul alebo komponent; fyzicke vyhotovenie treba porovnat s konkretnou dodavkou.',
			'typical_use'      => 'Arduino, ESP, Home Assistant alebo DIY elektronicke projekty podla realnej specifikacie produktu.',
			'categories'       => array('Moduly', 'Vývojové dosky'),
			'specs'            => array(
				'Napajanie' => 'Podla konkretnej verzie / dodavky sa moze lisit.',
				'Rozhranie' => 'Podla konkretnej verzie / dodavky sa moze lisit.',
				'Logika'    => 'Podla konkretnej verzie / dodavky sa moze lisit.',
			),
			'compatibility'    => array('Arduino', 'ESPHome', 'Home Assistant podla zapojenia'),
			'package_contents' => array('1x produkt / modul'),
			'confidence'       => 0.5,
		);

		$profiles = array(
			'esp32' => array(
				'title'            => 'ESP32 vývojová doska',
				'model'            => 'ESP32 DevKit / kompatibilna vyvojova doska',
				'chip'             => 'ESP32, konkretna revizia podla dodavky',
				'ports'            => 'USB port podla verzie, GPIO piny',
				'connectors'       => 'GPIO header piny, napajacie piny, USB',
				'pin_layout'       => 'ESP32 DevKit pinout; presny layout overit podla dosky',
				'appearance'       => 'Kompaktna vyvojova doska s ESP32 modulom, USB konektorom a radmi pinov.',
				'typical_use'      => 'Wi-Fi/Bluetooth automatizacia, ESPHome nody, senzory a IoT prototypy.',
				'categories'       => array('Vývojové dosky', 'ESP / ESPHome', 'Home Assistant'),
				'specs'            => array('Napajanie' => 'USB alebo VIN podla dosky', 'Komunikacia' => 'Wi-Fi, Bluetooth', 'GPIO' => 'Podla konkretnej dosky', 'Logika' => '3.3 V'),
				'compatibility'    => array('ESPHome', 'Arduino IDE', 'PlatformIO', 'Home Assistant'),
				'package_contents' => array('1x ESP32 vyvojova doska'),
				'confidence'       => 0.74,
			),
			'esp8266|nodemcu|wemos|d1 mini' => array(
				'title'            => 'ESP8266 vývojová doska',
				'model'            => 'ESP8266 NodeMCU / Wemos D1 mini kompatibilna doska',
				'chip'             => 'ESP8266',
				'ports'            => 'USB port podla verzie, GPIO piny',
				'connectors'       => 'GPIO header piny, napajacie piny, USB',
				'pin_layout'       => 'ESP8266 pinout; presny layout overit podla konkretnej dosky',
				'appearance'       => 'Mala Wi-Fi vyvojova doska s ESP8266 modulom a pinmi po stranach.',
				'typical_use'      => 'Lacne Wi-Fi senzory, ESPHome projekty a jednoduche IoT spinanie.',
				'categories'       => array('Vývojové dosky', 'ESP / ESPHome', 'Home Assistant'),
				'specs'            => array('Napajanie' => 'USB alebo 5 V vstup podla dosky', 'Komunikacia' => 'Wi-Fi 2.4 GHz', 'GPIO' => 'Podla dosky', 'Logika' => '3.3 V'),
				'compatibility'    => array('ESPHome', 'Arduino IDE', 'Home Assistant'),
				'package_contents' => array('1x ESP8266 vyvojova doska'),
				'confidence'       => 0.72,
			),
			'bme280' => array(
				'title'            => 'BME280 senzor teploty, vlhkosti a tlaku',
				'model'            => 'BME280 senzorovy modul',
				'chip'             => 'Bosch BME280',
				'ports'            => 'I2C / SPI podla modulu',
				'connectors'       => 'Pinova lista alebo pady podla verzie',
				'pin_layout'       => 'VCC, GND, SDA, SCL a pripadne SPI piny podla modulu',
				'appearance'       => 'Maly senzorovy modul s cipom BME280 a pinmi alebo padmi na okraji.',
				'typical_use'      => 'Meranie teploty, vlhkosti a atmosferickeho tlaku v smart home projektoch.',
				'categories'       => array('Senzory', 'Home Assistant', 'ESP / ESPHome'),
				'specs'            => array('Meranie' => 'Teplota, vlhkost, tlak', 'Rozhranie' => 'I2C / SPI podla modulu', 'Napajanie' => 'Podla konkretnej dosky', 'Logika' => 'Podla konkretnej dosky'),
				'compatibility'    => array('ESPHome', 'Arduino', 'Raspberry Pi', 'Home Assistant'),
				'package_contents' => array('1x BME280 senzorovy modul'),
				'confidence'       => 0.78,
			),
			'bmp280' => array(
				'title'            => 'BMP280 senzor teploty a tlaku',
				'model'            => 'BMP280 senzorovy modul',
				'chip'             => 'Bosch BMP280',
				'ports'            => 'I2C / SPI podla modulu',
				'connectors'       => 'Pinova lista alebo pady podla verzie',
				'pin_layout'       => 'VCC, GND, SDA, SCL a pripadne SPI piny podla modulu',
				'appearance'       => 'Maly senzorovy modul s cipom BMP280 a pinmi alebo padmi na okraji.',
				'typical_use'      => 'Meranie atmosferickeho tlaku a teploty.',
				'categories'       => array('Senzory', 'ESP / ESPHome'),
				'specs'            => array('Meranie' => 'Teplota, tlak', 'Rozhranie' => 'I2C / SPI podla modulu', 'Napajanie' => 'Podla konkretnej dosky'),
				'compatibility'    => array('ESPHome', 'Arduino', 'Raspberry Pi'),
				'package_contents' => array('1x BMP280 senzorovy modul'),
				'confidence'       => 0.76,
			),
			'dht22|am2302' => array(
				'title'            => 'DHT22 / AM2302 senzor teploty a vlhkosti',
				'model'            => 'DHT22 / AM2302',
				'chip'             => 'DHT22 / AM2302 senzor',
				'ports'            => 'Digitalny signalovy pin',
				'connectors'       => '3 alebo 4 piny podla modulu',
				'pin_layout'       => 'VCC, DATA, GND; pri 4-pin verzii jeden pin byva NC',
				'appearance'       => 'Biely perforovany senzor alebo modul s pinmi.',
				'typical_use'      => 'Meranie teploty a vlhkosti v miestnosti, skrinke alebo technickom priestore.',
				'categories'       => array('Senzory', 'Home Assistant', 'ESP / ESPHome'),
				'specs'            => array('Meranie' => 'Teplota, vlhkost', 'Rozhranie' => '1-wire-like digitalny signal', 'Napajanie' => 'Podla verzie modulu'),
				'compatibility'    => array('ESPHome', 'Arduino', 'Home Assistant'),
				'package_contents' => array('1x DHT22 / AM2302 senzor'),
				'confidence'       => 0.75,
			),
			'ds18b20' => array(
				'title'            => 'DS18B20 digitálny teplotný senzor',
				'model'            => 'DS18B20',
				'chip'             => 'DS18B20',
				'ports'            => '1-Wire',
				'connectors'       => '3 vodice alebo puzdro TO-92 podla verzie',
				'pin_layout'       => 'VDD, DQ, GND; farby vodicov overit podla dodavky',
				'appearance'       => 'Vodotesna sonda alebo TO-92 teplotny senzor podla variantu.',
				'typical_use'      => 'Meranie teploty vody, vzduchu, bojlera alebo technickych systemov.',
				'categories'       => array('Senzory', 'Home Assistant'),
				'specs'            => array('Meranie' => 'Teplota', 'Rozhranie' => '1-Wire', 'Napajanie' => 'Parazitne alebo externe podla zapojenia'),
				'compatibility'    => array('ESPHome', 'Arduino', 'Home Assistant'),
				'package_contents' => array('1x DS18B20 senzor'),
				'confidence'       => 0.77,
			),
			'relay|relé|rele' => array(
				'title'            => 'Relé modul',
				'model'            => 'Rele modul podla poctu kanalov a napajania',
				'chip'             => 'Spinaci rele modul; konkretne rele podla dodavky',
				'ports'            => 'Signalovy vstup a svorkovnica vystupu',
				'connectors'       => 'Piny VCC/GND/IN a skrutkovacia svorkovnica',
				'pin_layout'       => 'VCC, GND, IN; svorky COM/NO/NC podla modulu',
				'appearance'       => 'Modul so spinacim rele, svorkovnicou a pinmi pre riadiaci signal.',
				'typical_use'      => 'Oddelene spinanie zariadeni v automatizacii pri dodrzani bezpecnostnych pravidiel.',
				'categories'       => array('Moduly', 'Home Assistant', 'Napájanie'),
				'specs'            => array('Vstup' => 'Podla verzie modulu', 'Vystup' => 'Podla rele a svorkovnice', 'Izolacia' => 'Podla konkretneho modulu'),
				'compatibility'    => array('Arduino', 'ESPHome cez vhodne napajanie a oddelenie', 'Home Assistant'),
				'package_contents' => array('1x rele modul'),
				'confidence'       => 0.66,
			),
			'arduino mega|mega 2560|atmega2560' => array(
				'title'            => 'Arduino Mega 2560 R3 klon - vyvojova doska s ATmega2560',
				'model'            => 'Arduino Mega 2560 R3 kompatibilna doska',
				'chip'             => 'ATmega2560 alebo kompatibilny MCU podla dodavky',
				'ports'            => 'USB port, 54 digitalnych I/O, 16 analogovych vstupov, UART/I2C/SPI podla Arduino Mega 2560 rozlozenia',
				'connectors'       => 'Arduino Mega header piny, napajaci konektor alebo USB podla verzie',
				'pin_layout'       => 'Arduino Mega 2560 R3 pinout; USB prevodnik a konektor sa pri klonoch mozu lisit',
				'appearance'       => 'Velka Arduino Mega kompatibilna doska s dlhymi radmi pinov, USB konektorom a napajacou castou.',
				'typical_use'      => 'Rozsiahle Arduino projekty, robotika, viac senzorov, displeje, rele moduly a edukacne prototypovanie.',
				'categories'       => array('Vývojové dosky', 'Moduly'),
				'specs'            => array('MCU' => 'ATmega2560 alebo kompatibilny', 'Digitalne I/O' => '54 podla Arduino Mega 2560 reference', 'Analogove vstupy' => '16', 'Logika' => 'Typicky 5 V, overit konkretnu dodavku', 'USB prevodnik' => 'CH340 / ATmega16U2 / iny podla klonu'),
				'compatibility'    => array('Arduino IDE', 'PlatformIO', 'Arduino kniznice kompatibilne s Mega 2560'),
				'package_contents' => array('1x Arduino Mega 2560 R3 kompatibilna doska'),
				'source_urls'      => array(
					'https://docs.arduino.cc/hardware/mega-2560/',
					'https://docs.arduino.cc/resources/datasheets/A000067-datasheet.pdf',
					'https://ww1.microchip.com/downloads/en/DeviceDoc/ATmega640-1280-1281-2560-2561-Datasheet-DS40002211A.pdf',
					'https://techfun.sk/en/produkt/arduino-mega-precizny-klon/',
					'https://dratek.cz/arduino-platforma/1313-eses-klon-arduino-mega-ch340.html',
					'https://opencircuit.nl/product/arduino-mega-2560-clone',
				),
				'confidence'       => 0.82,
			),
			'arduino uno|uno r3|atmega328p' => array(
				'title'            => 'UNO R3 klon s ATmega328P - vyvojova doska kompatibilna s Arduino',
				'model'            => 'Arduino UNO R3 kompatibilna doska',
				'chip'             => 'ATmega328P alebo kompatibilny MCU podla dodavky',
				'ports'            => 'USB port, digitalne a analogove piny podla UNO R3 rozlozenia',
				'connectors'       => 'Arduino UNO header piny, USB, napajaci konektor podla verzie',
				'pin_layout'       => 'Arduino UNO R3 pinout; USB prevodnik a konektor sa pri klonoch mozu lisit',
				'appearance'       => 'Arduino UNO kompatibilna doska s typickym UNO tvarom, USB konektorom a radmi pinov.',
				'typical_use'      => 'Arduino vyuka, prototypovanie, senzory, male roboticke a elektronicke projekty.',
				'categories'       => array('Vývojové dosky', 'Moduly'),
				'specs'            => array('MCU' => 'ATmega328P alebo kompatibilny', 'Logika' => 'Typicky 5 V, overit konkretnu dodavku', 'USB prevodnik' => 'CH340 / ATmega16U2 / iny podla klonu'),
				'compatibility'    => array('Arduino IDE', 'PlatformIO', 'Arduino UNO shieldy podla fyzickej kompatibility'),
				'package_contents' => array('1x Arduino UNO R3 kompatibilna doska'),
				'source_urls'      => array(
					'https://docs.arduino.cc/hardware/uno-rev3/',
					'https://docs.arduino.cc/resources/datasheets/A000066-datasheet.pdf',
					'https://ww1.microchip.com/downloads/en/DeviceDoc/ATmega328P-Data-Sheet-DS40002061B.pdf',
					'https://dratek.cz/arduino/1258-eses-klon-arduino-uno-r3-ch340.html',
					'https://opencircuit.nl/product/arduino-uno-r3-clone',
				),
				'confidence'       => 0.8,
			),
			'arduino nano|nano' => array(
				'title'            => 'Arduino Nano kompatibilná doska',
				'model'            => 'Arduino Nano kompatibilna doska',
				'chip'             => 'ATmega328P alebo kompatibilny MCU podla verzie',
				'ports'            => 'USB mini/micro/USB-C podla verzie, GPIO piny',
				'connectors'       => 'Pinove rady, USB, napajacie piny',
				'pin_layout'       => 'Arduino Nano pinout; USB prevodnik a konektor sa mozu lisit',
				'appearance'       => 'Uzka vyvojova doska s dvomi radmi pinov a USB konektorom.',
				'typical_use'      => 'Arduino prototypy, senzory, jednoduche riadenie a edukacne projekty.',
				'categories'       => array('Vývojové dosky', 'Moduly'),
				'specs'            => array('MCU' => 'ATmega328P alebo kompatibilny', 'USB prevodnik' => 'CH340 / FTDI / iny podla verzie', 'Logika' => 'Typicky 5 V, overit verziu'),
				'compatibility'    => array('Arduino IDE', 'PlatformIO'),
				'package_contents' => array('1x Arduino Nano kompatibilna doska'),
				'confidence'       => 0.7,
			),
			'rp2040|pico' => array(
				'title'            => 'RP2040 / Raspberry Pi Pico kompatibilná doska',
				'model'            => 'RP2040 vyvojova doska',
				'chip'             => 'RP2040',
				'ports'            => 'USB a GPIO piny podla verzie',
				'connectors'       => 'GPIO header/pady, USB',
				'pin_layout'       => 'RP2040/Pico pinout; presny layout overit podla dosky',
				'appearance'       => 'Uzka vyvojova doska s RP2040 cipom, USB konektorom a GPIO pinmi.',
				'typical_use'      => 'MicroPython, C/C++ prototypy, meranie a riadenie.',
				'categories'       => array('Vývojové dosky', 'Moduly'),
				'specs'            => array('MCU' => 'RP2040', 'Logika' => '3.3 V', 'Rozhrania' => 'GPIO/I2C/SPI/UART podla dosky'),
				'compatibility'    => array('MicroPython', 'CircuitPython', 'Arduino IDE podla core'),
				'package_contents' => array('1x RP2040 vyvojova doska'),
				'confidence'       => 0.74,
			),
			'oled|ssd1306' => array(
				'title'            => 'OLED displej modul',
				'model'            => 'OLED SSD1306 / kompatibilny displej',
				'chip'             => 'SSD1306 alebo kompatibilny radic podla verzie',
				'ports'            => 'I2C alebo SPI podla modulu',
				'connectors'       => 'Pinova lista alebo pady',
				'pin_layout'       => 'VCC, GND, SDA, SCL pri I2C verzii; SPI verzia sa lisi',
				'appearance'       => 'Maly OLED displej na ciernom alebo modrom module.',
				'typical_use'      => 'Zobrazenie stavov, merani a diagnostiky v DIY elektronike.',
				'categories'       => array('Moduly', 'ESP / ESPHome'),
				'specs'            => array('Displej' => 'OLED podla uhlopriecky', 'Rozhranie' => 'I2C / SPI podla modulu', 'Napajanie' => 'Podla konkretnej dosky'),
				'compatibility'    => array('ESPHome', 'Arduino', 'Raspberry Pi'),
				'package_contents' => array('1x OLED displej modul'),
				'confidence'       => 0.7,
			),
			'hc-sr04|hcsr04' => array(
				'title'            => 'HC-SR04 ultrazvukový senzor vzdialenosti',
				'model'            => 'HC-SR04',
				'chip'             => 'Ultrazvukovy meraci modul HC-SR04',
				'ports'            => 'Trig/Echo digitalne piny',
				'connectors'       => '4-pin header',
				'pin_layout'       => 'VCC, Trig, Echo, GND',
				'appearance'       => 'Modul s dvomi kruhovymi ultrazvukovymi menicmi a 4 pinmi.',
				'typical_use'      => 'Meranie vzdialenosti a hladiny pri hobby projektoch.',
				'categories'       => array('Senzory', 'Moduly'),
				'specs'            => array('Meranie' => 'Vzdialenost', 'Rozhranie' => 'Trig/Echo', 'Napajanie' => 'Typicky 5 V, overit verziu'),
				'compatibility'    => array('Arduino', 'ESPHome cez vhodne urovne logiky'),
				'package_contents' => array('1x HC-SR04 ultrazvukovy senzor'),
				'confidence'       => 0.74,
			),
			'hlk-pm|hlk pm|hi-link' => array(
				'title'            => 'HLK-PM napájací modul',
				'model'            => 'Hi-Link HLK-PM rad podla vystupneho napatia',
				'chip'             => 'AC/DC napajaci modul',
				'ports'            => 'AC vstup a DC vystup',
				'connectors'       => 'Piny alebo svorky podla verzie',
				'pin_layout'       => 'L/N vstup a +/- vystup; presne znacenie overit na module',
				'appearance'       => 'Zapuzdreny AC/DC modul na dosku.',
				'typical_use'      => 'Napajanie nizkonapatovych elektronickych projektov z AC siete len pri odbornej montazi.',
				'categories'       => array('Napájanie', 'Moduly'),
				'specs'            => array('Vstup' => 'AC siet podla modelu', 'Vystup' => 'Podla konkretneho HLK-PM modelu', 'Bezpecnost' => 'Vyhradne odborna montaz'),
				'compatibility'    => array('DIY elektronika pri dodrzani bezpecnostnych pravidiel'),
				'package_contents' => array('1x HLK-PM napajaci modul'),
				'confidence'       => 0.68,
			),
		);

		foreach ($profiles as $pattern => $profile) {
			$tokens = explode('|', $pattern);
			foreach ($tokens as $token) {
				if (false !== strpos($lower, $token)) {
					return wp_parse_args($profile, $default);
				}
			}
		}

		return $default;
	}
}
