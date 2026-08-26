<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Image_Recovery_Engine {
	private $plugin;

	private $variants = array('main', 'angle', 'detail', 'technical');

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function counts() {
		if (!$this->plugin->has_woocommerce()) {
			return array(
				'without_four_images' => 0,
				'blocked_by_images'   => 0,
				'ready_images'        => 0,
				'needs_review_images' => 0,
			);
		}

		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => 1000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		));

		$out = array(
			'without_four_images' => 0,
			'blocked_by_images'   => 0,
			'ready_images'        => 0,
			'needs_review_images' => 0,
		);

		foreach ((array) $query->posts as $product_id) {
			$product_id = absint($product_id);
			if ($this->image_count($product_id) < 4) {
				$out['without_four_images']++;
			}
			$status = sanitize_key(get_post_meta($product_id, '_komarena_pf_image_status', true));
			if ('blocked_by_images' === $status) {
				$out['blocked_by_images']++;
			} elseif ('ready' === $status || 'uploaded' === $status) {
				$out['ready_images']++;
			} elseif ($status) {
				$out['needs_review_images']++;
			}
		}

		return $out;
	}

	public function find_product_images($product_id, $options = array()) {
		$product_id = absint($product_id);
		$product = wc_get_product($product_id);
		if (!$product) {
			return new WP_Error('komarena_pf_image_recovery_product_missing', 'Produkt neexistuje.');
		}

		$research = $this->research_from_product($product_id);
		$candidates = $this->plugin->images->candidate_sources($product_id, $research);
		$summary = $this->candidate_summary($candidates);
		$manifest = $this->manifest($product_id);
		$manifest['image_recovery'] = array(
			'status'       => $summary['final_candidates'] >= 4 ? 'candidates_found' : 'blocked_by_images',
			'phase'        => 'find_product_images',
			'product_id'   => $product_id,
			'product_name' => $product->get_name(),
			'candidates'   => $summary,
			'candidate_records' => $this->sanitize_candidates_for_manifest($candidates),
			'reviewed_at'  => current_time('mysql'),
			'reviewed_by'  => 'agent',
		);

		$status = $summary['final_candidates'] >= 4 ? 'candidates_found' : 'blocked_by_images';
		$reason = $this->image_block_reason($summary, $candidates);
		$this->save_manifest_meta($product_id, $manifest);
		$this->save_image_state($product_id, array(
			'image_status'            => $status,
			'image_block_reason'      => $reason,
			'image_source_count'      => $summary['total_candidates'],
			'image_exact_match_score' => $summary['exact_match_score'],
			'image_rights_confidence' => $summary['rights_confidence'],
			'image_watermark_check'   => $summary['watermark_check'],
			'image_logo_check'        => $summary['logo_check'],
			'image_ai_check'          => $summary['ai_check'],
			'image_last_reviewed_at'  => current_time('mysql'),
			'image_last_reviewed_by'  => 'agent',
			'publish_gate_status'     => $summary['final_candidates'] >= 4 ? 'candidate_ready' : 'blocked',
			'publish_gate_reason'     => $reason,
		));

		$this->plugin->logger->log(
			$summary['final_candidates'] >= 4 ? 'info' : 'warning',
			$summary['final_candidates'] >= 4 ? 'Agent našiel použiteľných kandidátov obrázkov.' : 'Agent nenašiel 4 finálne použiteľné obrázky.',
			'obrázková obnova',
			array('product_id' => $product_id, 'summary' => $summary, 'reason' => $reason),
			null,
			$product_id
		);

		return array(
			'status'     => $status,
			'product_id' => $product_id,
			'title'      => $product->get_name(),
			'candidates' => $summary,
			'items'      => $this->sanitize_candidates_for_manifest($candidates),
			'reason'     => $reason,
		);
	}

	public function fetch_product_images($product_id, $options = array()) {
		$result = $this->find_product_images($product_id, $options);
		if (is_wp_error($result)) {
			return $result;
		}

		$result['phase'] = 'fetch_product_images';
		$result['message'] = 'Kandidáti obrázkov boli načítaní a posúdení. Neoverené externé obrázky sa nesťahujú do médií, kým neprejdú finálnou bránou.';
		return $result;
	}

	public function qa_product_images($product_id, $options = array()) {
		$product_id = absint($product_id);
		$manifest = $this->manifest($product_id);
		$qa = $this->plugin->qa->run($product_id, $manifest);
		$image_failures = $this->image_failures_from_qa($qa);
		$ready = empty($image_failures);
		$reason = $ready ? 'Obrázková časť kontroly kvality prešla.' : implode(' ', $image_failures);

		$manifest['image_recovery']['last_image_qa'] = array(
			'status'      => $ready ? 'ready' : 'blocked_by_images',
			'failures'    => $image_failures,
			'checked_at'  => current_time('mysql'),
			'checked_by'  => 'agent',
		);
		$manifest['publish_gate_status'] = !empty($qa['ready_to_publish']) ? 'pass' : 'blocked';
		$manifest['publish_gate_reason'] = !empty($qa['ready_to_publish']) ? 'Produkt prešiel kompletnou publikačnou bránou.' : implode(' ', (array) ($qa['missing'] ?? array()));
		$this->save_manifest_meta($product_id, $manifest);

		$this->save_image_state($product_id, array(
			'image_status'           => $ready ? 'ready' : 'blocked_by_images',
			'image_block_reason'     => $reason,
			'image_last_reviewed_at' => current_time('mysql'),
			'image_last_reviewed_by' => 'agent',
			'publish_gate_status'    => $manifest['publish_gate_status'],
			'publish_gate_reason'    => $manifest['publish_gate_reason'],
		));

		if (!$ready) {
			update_post_meta($product_id, '_komarena_pf_status', 'blocked_by_images');
			update_post_meta($product_id, '_komarena_pf_ready_to_publish', '0');
		} elseif (!empty($qa['ready_to_publish'])) {
			update_post_meta($product_id, '_komarena_pf_status', 'ready_to_publish');
			update_post_meta($product_id, '_komarena_pf_ready_to_publish', '1');
		}

		return array(
			'status'           => !empty($qa['ready_to_publish']) ? 'done' : ($ready ? 'needs_review' : 'blocked_by_images'),
			'product_id'       => $product_id,
			'image_failures'   => $image_failures,
			'ready_to_publish' => !empty($qa['ready_to_publish']),
			'qa'               => $qa,
			'reason'           => $reason,
		);
	}

	public function standardize_product_images($product_id, $options = array()) {
		$product_id = absint($product_id);
		$manifest = $this->manifest($product_id);
		$provenance = (array) ($manifest['image_provenance'] ?? array());
		if ($this->image_count($product_id) >= 4 && $this->provenance_final_eligible($provenance)) {
			$manifest['image_recovery']['standardized_at'] = current_time('mysql');
			$manifest['image_recovery']['standardized_by'] = 'agent';
			$manifest['image_mode'] = $manifest['image_mode'] ?? 'approved_original_media';
			$this->save_manifest_meta($product_id, $manifest);
			$this->save_image_state($product_id, array(
				'image_status'           => 'ready',
				'image_block_reason'     => '',
				'image_last_reviewed_at' => current_time('mysql'),
				'image_last_reviewed_by' => 'agent',
				'publish_gate_status'    => 'image_ready',
				'publish_gate_reason'    => 'Produkt má 4 finálne použiteľné obrázky s pôvodom.',
			));

			return $this->qa_product_images($product_id, $options);
		}

		return $this->upload_product_images($product_id, $options);
	}

	public function upload_product_images($product_id, $options = array()) {
		$product_id = absint($product_id);
		$product = wc_get_product($product_id);
		if (!$product) {
			return new WP_Error('komarena_pf_image_recovery_product_missing', 'Produkt neexistuje.');
		}

		$research = $this->research_from_product($product_id);
		$slug = $product->get_slug() ? $product->get_slug() : sanitize_title($product->get_name());
		$images = $this->plugin->images->prepare_images($product_id, $research, $slug, array(
			'attach_to_product'  => true,
			'require_exact_match'=> true,
		));

		$manifest = $this->manifest($product_id);
		$manifest['image_ids'] = array_values(array_map('absint', (array) ($images['image_ids'] ?? array())));
		$manifest['image_urls'] = array_values(array_map('esc_url_raw', (array) ($images['image_urls'] ?? array())));
		$manifest['image_mode'] = sanitize_key($images['image_mode'] ?? 'real_images_missing');
		$manifest['image_provenance'] = (array) ($images['image_provenance'] ?? array());
		$manifest['warnings'] = array_values(array_unique(array_merge((array) ($manifest['warnings'] ?? array()), (array) ($images['warnings'] ?? array()))));
		$manifest['image_recovery']['upload_result'] = array(
			'image_count'  => count((array) ($images['image_ids'] ?? array())),
			'image_mode'   => $manifest['image_mode'],
			'warnings'     => (array) ($images['warnings'] ?? array()),
			'uploaded_at'  => current_time('mysql'),
			'uploaded_by'  => 'agent',
		);
		$this->save_manifest_meta($product_id, $manifest);

		if (count((array) ($images['image_ids'] ?? array())) < 4 || !$this->provenance_final_eligible($manifest['image_provenance'])) {
			$reason = 'Agent nedokázal nahrať 4 finálne použiteľné originálne/reálne obrázky. ' . implode(' ', (array) ($images['warnings'] ?? array()));
			$this->save_image_state($product_id, array(
				'image_status'           => 'blocked_by_images',
				'image_block_reason'     => $reason,
				'image_source_count'     => count((array) ($manifest['image_recovery']['candidate_records'] ?? array())),
				'image_last_reviewed_at' => current_time('mysql'),
				'image_last_reviewed_by' => 'agent',
				'publish_gate_status'    => 'blocked',
				'publish_gate_reason'    => $reason,
			));
			update_post_meta($product_id, '_komarena_pf_status', 'blocked_by_images');
			update_post_meta($product_id, '_komarena_pf_ready_to_publish', '0');

			return array(
				'status'     => 'blocked_by_images',
				'product_id' => $product_id,
				'title'      => $product->get_name(),
				'images'     => $images,
				'reason'     => trim($reason),
			);
		}

		$this->save_image_state($product_id, array(
			'image_status'           => 'uploaded',
			'image_block_reason'     => '',
			'image_source_count'     => count((array) ($manifest['image_recovery']['candidate_records'] ?? array())),
			'image_last_reviewed_at' => current_time('mysql'),
			'image_last_reviewed_by' => 'agent',
			'publish_gate_status'    => 'image_ready',
			'publish_gate_reason'    => 'Obrázky boli nahraté a priradené.',
		));

		$qa = $this->qa_product_images($product_id, $options);

		return array(
			'status'     => !empty($qa['ready_to_publish']) ? 'done' : 'needs_review',
			'product_id' => $product_id,
			'title'      => $product->get_name(),
			'images'     => $images,
			'qa'         => $qa,
		);
	}

	public function repair_product_images($product_id, $options = array()) {
		$product_id = absint($product_id);
		$found = $this->find_product_images($product_id, $options);
		if (is_wp_error($found)) {
			return $found;
		}

		if (($found['candidates']['final_candidates'] ?? 0) < 4) {
			return array(
				'status'     => 'blocked_by_images',
				'product_id' => $product_id,
				'find'       => $found,
				'reason'     => $found['reason'] ?? 'Nie sú dostupné 4 finálne použiteľné obrázky.',
			);
		}

		$uploaded = $this->upload_product_images($product_id, $options);
		if (is_wp_error($uploaded)) {
			return $uploaded;
		}

		return $uploaded;
	}

	public function image_recovery_run($limit = 10, $options = array()) {
		$limit = max(1, min(100, absint($limit)));
		$ids = $this->products_needing_recovery($limit);
		$items = array();
		$recovered = 0;
		$blocked = 0;
		$failed = 0;

		foreach ($ids as $product_id) {
			$result = $this->repair_product_images($product_id, $options);
			if (is_wp_error($result)) {
				$failed++;
				$items[] = array(
					'product_id' => $product_id,
					'title'      => get_the_title($product_id),
					'status'     => 'failed',
					'message'    => $result->get_error_message(),
				);
				continue;
			}

			$status = sanitize_key($result['status'] ?? 'needs_review');
			if ('done' === $status || 'ready' === $status) {
				$recovered++;
			} elseif ('blocked_by_images' === $status) {
				$blocked++;
			}
			$items[] = array(
				'product_id' => $product_id,
				'title'      => get_the_title($product_id),
				'status'     => $status,
				'reason'     => (string) ($result['reason'] ?? ''),
			);
		}

		$out = array(
			'status'      => $failed ? 'needs_review' : 'done',
			'checked'     => count($ids),
			'recovered'   => $recovered,
			'blocked'     => $blocked,
			'failed'      => $failed,
			'items'       => $items,
			'finished_at' => current_time('mysql'),
		);
		update_option('komarena_pf_last_image_recovery_run', $out, false);
		$this->plugin->logger->log($blocked || $failed ? 'warning' : 'info', 'Obrázková obnova produktov dokončená.', 'obrázková obnova', $out);

		return $out;
	}

	private function products_needing_recovery($limit) {
		$query = new WP_Query(array(
			'post_type'      => 'product',
			'post_status'    => array('publish', 'draft', 'pending', 'private'),
			'posts_per_page' => min(500, max($limit * 8, 50)),
			'fields'         => 'ids',
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		));

		$out = array();
		foreach ((array) $query->posts as $product_id) {
			$product_id = absint($product_id);
			$image_status = sanitize_key(get_post_meta($product_id, '_komarena_pf_image_status', true));
			if ($this->image_count($product_id) < 4 || in_array($image_status, array('', 'blocked_by_images', 'needs_review', 'candidates_found'), true)) {
				$out[] = $product_id;
			}
			if (count($out) >= $limit) {
				break;
			}
		}

		return $out;
	}

	private function research_from_product($product_id) {
		$product = wc_get_product($product_id);
		$manifest = $this->manifest($product_id);
		$name = $product ? $product->get_name() : get_the_title($product_id);
		$source_urls = $this->collect_source_urls($product_id, $manifest);
		$image_sources = $this->collect_image_sources($product_id, $manifest);

		return array(
			'input_name'      => $name,
			'normalized_name' => $name,
			'slug'            => $product && $product->get_slug() ? $product->get_slug() : sanitize_title($name),
			'model'           => $this->guess_model_token($name, $manifest),
			'chip'            => $manifest['chip'] ?? '',
			'ports'           => $manifest['ports'] ?? '',
			'connectors'      => $manifest['connectors'] ?? '',
			'pin_layout'      => $manifest['pin_layout'] ?? '',
			'appearance'      => $manifest['appearance'] ?? '',
			'specs'           => is_array($manifest['specs'] ?? null) ? $manifest['specs'] : array(),
			'source_urls'     => $source_urls,
			'image_sources'   => $image_sources,
		);
	}

	private function collect_source_urls($product_id, $manifest) {
		$urls = array();
		foreach (array('source_urls', 'sourceUrls', 'sources', 'verified_sources') as $key) {
			foreach ((array) ($manifest[$key] ?? array()) as $url) {
				if (is_array($url)) {
					$url = $url['url'] ?? $url['source_url'] ?? '';
				}
				$url = esc_url_raw($url);
				if ($url) {
					$urls[] = $url;
				}
			}
		}

		$content = get_post_field('post_content', $product_id);
		if ($content && preg_match_all('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $content, $matches)) {
			foreach ($matches[1] as $url) {
				$url = esc_url_raw($url);
				if ($url && false === strpos($url, home_url())) {
					$urls[] = $url;
				}
			}
		}

		return array_values(array_unique(array_filter($urls)));
	}

	private function collect_image_sources($product_id, $manifest) {
		$sources = array();
		foreach ($this->current_product_image_sources($product_id) as $variant => $record) {
			$sources[$variant] = $record;
		}

		$manifest_sources = array();
		foreach (array('image_sources', 'imageSources', 'real_image_sources', 'realImageSources') as $key) {
			if (!empty($manifest[$key]) && is_array($manifest[$key])) {
				$manifest_sources = array_merge($manifest_sources, $manifest[$key]);
			}
		}
		foreach ($this->image_sources_from_provenance((array) ($manifest['image_provenance'] ?? array())) as $variant => $record) {
			$manifest_sources[$variant] = $record;
		}

		foreach ($manifest_sources as $key => $record) {
			$variant = is_string($key) && in_array($key, $this->variants, true) ? $key : sanitize_key(is_array($record) ? ($record['role'] ?? '') : '');
			if (!$variant || !in_array($variant, $this->variants, true)) {
				$variant = $this->variants[min(count($sources), 3)];
			}
			$sources[$variant] = $record;
		}

		return $sources;
	}

	private function current_product_image_sources($product_id) {
		$ids = array();
		$thumb = get_post_thumbnail_id($product_id);
		if ($thumb) {
			$ids[] = absint($thumb);
		}
		$product = wc_get_product($product_id);
		if ($product) {
			foreach ((array) $product->get_gallery_image_ids() as $id) {
				$ids[] = absint($id);
			}
		}

		$out = array();
		foreach (array_slice(array_values(array_unique(array_filter($ids))), 0, 4) as $index => $id) {
			$url = wp_get_attachment_url($id);
			if (!$url) {
				continue;
			}
			$variant = $this->variants[$index] ?? 'technical';
			$out[$variant] = array(
				'role'                => $variant,
				'url'                 => $url,
				'image_url'           => $url,
				'source_url'          => $url,
				'source_type'         => 'internal_komarena_media',
				'usage_permission'    => 'needs_rights_review',
				'watermark_status'    => 'unknown',
				'seller_logo_status'  => 'unknown',
				'ai_generated'        => false,
				'generated'           => false,
				'is_original_or_real' => false,
				'title'               => get_the_title($id),
				'alt'                 => get_post_meta($id, '_wp_attachment_image_alt', true),
				'filename'            => basename((string) get_attached_file($id)),
				'notes'               => 'Existujúci obrázok v knižnici médií potrebuje schválenie pôvodu alebo manifest s právami.',
			);
		}

		return $out;
	}

	private function image_sources_from_provenance($provenance) {
		$out = array();
		foreach ((array) $provenance as $key => $item) {
			if (!is_array($item)) {
				continue;
			}
			$variant = sanitize_key($item['role'] ?? (is_string($key) ? $key : ''));
			if (!$variant || !in_array($variant, $this->variants, true)) {
				continue;
			}
			$url = esc_url_raw($item['source_image_url'] ?? $item['url'] ?? '');
			if (!$url) {
				continue;
			}
			$out[$variant] = array(
				'role'                => $variant,
				'url'                 => $url,
				'image_url'           => $url,
				'source_url'          => esc_url_raw($item['source_url'] ?? $url),
				'source_type'         => sanitize_key($item['source_type'] ?? 'unknown'),
				'usage_permission'    => sanitize_key($item['usage_permission'] ?? 'needs_rights_review'),
				'watermark_status'    => sanitize_key($item['watermark_status'] ?? 'unknown'),
				'seller_logo_status'  => sanitize_key($item['seller_logo_status'] ?? 'unknown'),
				'ai_generated'        => !empty($item['ai_generated']),
				'generated'           => !empty($item['generated']),
				'is_original_or_real' => !empty($item['is_original_or_real']),
				'is_final_eligible'   => !empty($item['is_final_eligible']),
				'notes'               => sanitize_text_field($item['notes'] ?? 'Zdroj z predchádzajúceho manifestu.'),
			);
		}

		return $out;
	}

	private function candidate_summary($candidates) {
		$total = count((array) $candidates);
		$exact = 0;
		$final = 0;
		$watermark = array();
		$logo = array();
		$ai = array();
		foreach ((array) $candidates as $record) {
			if (!empty($record['_komarena_exact_image_match']['pass'])) {
				$exact++;
			}
			if (!empty($record['_komarena_final_eligible'])) {
				$final++;
			}
			$watermark[] = sanitize_key($record['_komarena_final_preflight']['watermark_status'] ?? $record['watermark_status'] ?? 'unknown');
			$logo[] = sanitize_key($record['_komarena_final_preflight']['seller_logo_status'] ?? $record['seller_logo_status'] ?? 'unknown');
			$ai[] = !empty($record['ai_generated']) || !empty($record['generated']) ? 'blocked' : 'not_suspected';
		}

		return array(
			'total_candidates'  => $total,
			'exact_matches'     => $exact,
			'final_candidates'  => $final,
			'exact_match_score' => $total ? round(($exact / $total) * 100, 2) : 0,
			'rights_confidence' => $final >= 4 ? 'high' : ($total ? 'needs_review' : 'missing'),
			'watermark_check'   => $this->rollup_status($watermark),
			'logo_check'        => $this->rollup_status($logo),
			'ai_check'          => in_array('blocked', $ai, true) ? 'blocked' : ($total ? 'not_suspected' : 'missing'),
		);
	}

	private function sanitize_candidates_for_manifest($candidates) {
		$out = array();
		foreach ((array) $candidates as $variant => $record) {
			$out[$variant] = array(
				'role'                  => sanitize_key($variant),
				'source_url'            => esc_url_raw($record['source_url'] ?? $record['url'] ?? ''),
				'source_image_url'      => esc_url_raw($record['image_url'] ?? $record['url'] ?? ''),
				'source_domain'         => $this->source_domain($record['source_url'] ?? $record['url'] ?? ''),
				'source_type'           => sanitize_key($record['source_type'] ?? 'unknown'),
				'product_match_reason'  => sanitize_text_field($record['_komarena_exact_image_match']['reason'] ?? ''),
				'visual_match_checks'   => array_values(array_map('sanitize_text_field', (array) ($record['_komarena_exact_image_match']['matched_tokens'] ?? array()))),
				'rights_confidence'     => !empty($record['_komarena_final_eligible']) ? 'high' : 'needs_review',
				'watermark_detected'    => 'clean' !== sanitize_key($record['_komarena_final_preflight']['watermark_status'] ?? $record['watermark_status'] ?? 'unknown'),
				'seller_logo_detected'  => 'clean' !== sanitize_key($record['_komarena_final_preflight']['seller_logo_status'] ?? $record['seller_logo_status'] ?? 'unknown'),
				'ai_generated_suspected'=> !empty($record['ai_generated']) || !empty($record['generated']),
				'exact_model_match'     => !empty($record['_komarena_exact_image_match']['pass']),
				'downloaded_at'         => '',
				'local_file_path'       => '',
				'wp_attachment_id'      => absint($record['attachment_id'] ?? 0),
				'final_eligible'        => !empty($record['_komarena_final_eligible']),
				'rejection_reasons'     => array_values(array_map('sanitize_text_field', (array) ($record['_komarena_rejection_reasons'] ?? array()))),
				'reviewed_at'           => current_time('mysql'),
			);
		}

		return $out;
	}

	private function image_block_reason($summary, $candidates) {
		if (empty($summary['total_candidates'])) {
			return 'Agent nenašiel žiadne kandidátske obrázky v overených zdrojoch, manifeste ani existujúcej galérii produktu.';
		}
		if (($summary['final_candidates'] ?? 0) < 4) {
			$reasons = array();
			foreach ((array) $candidates as $variant => $record) {
				if (!empty($record['_komarena_rejection_reasons'])) {
					$reasons[] = $variant . ': ' . implode(', ', (array) $record['_komarena_rejection_reasons']);
				}
			}
			return sprintf(
				'Nájdené kandidáty: %d, finálne použiteľné: %d. Produkt potrebuje 4 originálne/reálne obrázky bez AI, vodoznaku a cudzieho loga. %s',
				(int) ($summary['total_candidates'] ?? 0),
				(int) ($summary['final_candidates'] ?? 0),
				implode(' | ', array_slice($reasons, 0, 6))
			);
		}

		return 'Obrázkové kandidáty sú pripravené na nahratie.';
	}

	private function image_failures_from_qa($qa) {
		$image_needles = array('obráz', 'obraz', 'galér', 'galer', 'pôvod obrázkov', 'povod obrazkov', 'vodoznak', 'logo', 'AI', 'zhodu', 'fotografie');
		$out = array();
		foreach ((array) ($qa['missing'] ?? array()) as $missing) {
			$normalized = strtolower(remove_accents((string) $missing));
			foreach ($image_needles as $needle) {
				if (false !== strpos($normalized, strtolower(remove_accents($needle)))) {
					$out[] = (string) $missing;
					break;
				}
			}
		}

		return array_values(array_unique($out));
	}

	private function provenance_final_eligible($provenance) {
		if (!is_array($provenance) || count($provenance) < 4) {
			return false;
		}
		foreach ($this->variants as $variant) {
			$item = $provenance[$variant] ?? null;
			if (!is_array($item) || empty($item['is_final_eligible']) || empty($item['is_original_or_real']) || !empty($item['ai_generated']) || !empty($item['generated'])) {
				return false;
			}
		}

		return true;
	}

	private function image_count($product_id) {
		$product = wc_get_product($product_id);
		if (!$product) {
			return 0;
		}

		return (has_post_thumbnail($product_id) ? 1 : 0) + count((array) $product->get_gallery_image_ids());
	}

	private function manifest($product_id) {
		$manifest = json_decode((string) get_post_meta($product_id, '_komarena_pf_manifest', true), true);
		return is_array($manifest) ? $manifest : array();
	}

	private function save_manifest_meta($product_id, $manifest) {
		$options = defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES : 0;
		$encoded = wp_json_encode($manifest, $options);
		if (false === $encoded) {
			$encoded = wp_json_encode($manifest);
		}
		update_post_meta($product_id, '_komarena_pf_manifest', wp_slash((string) $encoded));
	}

	private function save_image_state($product_id, $state) {
		$keys = array(
			'image_status',
			'image_block_reason',
			'image_source_count',
			'image_exact_match_score',
			'image_rights_confidence',
			'image_watermark_check',
			'image_logo_check',
			'image_ai_check',
			'image_last_reviewed_at',
			'image_last_reviewed_by',
			'publish_gate_status',
			'publish_gate_reason',
		);
		foreach ($keys as $key) {
			if (!array_key_exists($key, $state)) {
				continue;
			}
			$value = is_scalar($state[$key]) ? (string) $state[$key] : wp_json_encode($state[$key]);
			update_post_meta($product_id, $key, wp_kses_post($value));
			update_post_meta($product_id, '_komarena_pf_' . $key, wp_kses_post($value));
		}
	}

	private function rollup_status($statuses) {
		$statuses = array_values(array_unique(array_filter((array) $statuses)));
		if (empty($statuses)) {
			return 'missing';
		}
		if (count($statuses) === 1) {
			return $statuses[0];
		}
		if (in_array('suspected', $statuses, true) || in_array('blocked', $statuses, true)) {
			return 'suspected';
		}
		if (in_array('unknown', $statuses, true)) {
			return 'unknown';
		}

		return 'mixed';
	}

	private function source_domain($url) {
		$host = wp_parse_url((string) $url, PHP_URL_HOST);
		return $host ? strtolower((string) $host) : '';
	}

	private function guess_model_token($name, $manifest) {
		foreach (array('model', 'chip') as $key) {
			if (!empty($manifest[$key]) && is_scalar($manifest[$key])) {
				return sanitize_text_field((string) $manifest[$key]);
			}
		}
		if (preg_match('/\b(?:[A-Z]{1,5}[-_ ]?\d{2,5}[A-Z0-9-]*|ATMEGA\d+[A-Z]*|NODEMCU|DEVKIT|WEMOS\s+D1\s+MINI)\b/i', (string) $name, $matches)) {
			return sanitize_text_field($matches[0]);
		}

		return sanitize_text_field($name);
	}
}
