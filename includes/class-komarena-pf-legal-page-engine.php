<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Legal_Page_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function create_draft($type = 'cookies', $args = array()) {
		$type = sanitize_key($type ? $type : 'cookies');
		$args = is_array($args) ? $args : array();
		$overwrite = !empty($args['overwrite']);

		if ('cookies' !== $type) {
			return new WP_Error('komarena_pf_legal_type_unknown', 'Neznamy typ informacnej stranky.');
		}

		$existing = $this->find_existing_page($type, true);
		if ($existing && 'publish' === get_post_status($existing)) {
			return array(
				'status'   => 'done',
				'message'  => 'Stranka Cookies uz existuje a je publikovana.',
				'page_id'  => (int) $existing->ID,
				'edit_url' => get_edit_post_link($existing->ID, 'raw'),
				'view_url' => get_permalink($existing->ID),
			);
		}

		if ($existing && !$overwrite) {
			return array(
				'status'   => 'needs_review',
				'message'  => 'Koncept stranky Cookies uz existuje. Agent ho neprepise bez volby overwrite.',
				'page_id'  => (int) $existing->ID,
				'edit_url' => get_edit_post_link($existing->ID, 'raw'),
				'view_url' => get_permalink($existing->ID),
			);
		}

		$draft = $this->cookies_draft();
		$postarr = array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => $draft['title'],
			'post_name'    => $draft['slug'],
			'post_content' => wp_kses_post($draft['content']),
		);

		if ($existing && $overwrite) {
			$postarr['ID'] = (int) $existing->ID;
			$page_id = wp_update_post($postarr, true);
		} else {
			$page_id = wp_insert_post($postarr, true);
		}

		if (is_wp_error($page_id)) {
			return $page_id;
		}

		update_post_meta($page_id, '_komarena_pf_legal_draft_type', $type);
		update_post_meta($page_id, '_komarena_pf_legal_draft_status', 'needs_review');
		update_post_meta($page_id, '_komarena_pf_legal_draft_sources', wp_json_encode($draft['sources']));
		update_post_meta($page_id, '_komarena_pf_generated_at', current_time('mysql'));
		update_post_meta($page_id, '_komarena_pf_agent_version', KOMARENA_PF_VERSION);

		$result = array(
			'status'   => 'needs_review',
			'message'  => $existing ? 'Koncept stranky Cookies bol aktualizovany a caka na kontrolu.' : 'Koncept stranky Cookies bol vytvoreny a caka na kontrolu.',
			'page_id'  => (int) $page_id,
			'edit_url' => get_edit_post_link($page_id, 'raw'),
			'view_url' => get_permalink($page_id),
			'sources'  => $draft['sources'],
			'warnings' => array(
				'Pred publikovanim skontrolovat realne cookies, analytiku, reklamne nastroje, platobne brany, dopravcov a aktivny cookie banner.',
				'Koncept nie je pravne poradenstvo a nenahradza kontrolu odbornikom.',
			),
		);

		update_option('komarena_pf_last_legal_page_draft', $result, false);
		if (!empty($this->plugin->logger)) {
			$this->plugin->logger->log('info', 'Koncept informacnej stranky bol vytvoreny.', 'informacne-stranky', $result);
		}

		return $result;
	}

	public function find_existing_page($type = 'cookies', $include_drafts = true) {
		$type = sanitize_key($type ? $type : 'cookies');
		$statuses = $include_drafts ? array('publish', 'draft', 'pending', 'private') : array('publish');
		$terms = 'cookies' === $type ? array('cookies', 'cookie', 'subory-cookie', 'zasady-cookies') : array($type);

		$pages = get_pages(array(
			'post_status' => $statuses,
			'sort_column' => 'post_title',
		));

		foreach ((array) $pages as $page) {
			$identity = $this->normalize($page->post_title . ' ' . $page->post_name);
			foreach ($terms as $term) {
				if (false !== strpos($identity, $this->normalize($term))) {
					return $page;
				}
			}
		}

		return null;
	}

	private function cookies_draft() {
		$sources = array(
			'https://dataprotection.gov.sk/sk/cookies/',
			'https://www.mhsr.sk/obchod/ochrana-spotrebitela/najcastejsie-otazky/najcastejsie-otazky-spotrebitelov',
			'https://eur-lex.europa.eu/SK/legal-content/summary/consumer-information-right-of-withdrawal-and-other-consumer-rights.html',
		);

		$content = '<div style="max-width:980px;margin:0 auto;color:#102027;line-height:1.65;">';
		$content .= '<div style="border:1px solid rgba(0,151,157,.24);border-radius:18px;background:#f3ffff;padding:22px;margin:0 0 18px;">';
		$content .= '<p style="margin:0 0 8px;color:#00777c;font-weight:800;">Návrh pripravil KomArena agent</p>';
		$content .= '<h1 style="margin:0 0 12px;font-size:30px;line-height:1.2;">Cookies</h1>';
		$content .= '<p style="margin:0;">Táto stránka vysvetľuje, ako môže web KomArena.sk používať súbory cookies a podobné technológie. Pred publikovaním je potrebné skontrolovať reálne zapnuté nástroje na webe, cookie lištu, analytiku, marketingové skripty, platobné a dopravné integrácie.</p>';
		$content .= '</div>';

		$content .= '<h2>Čo sú cookies</h2>';
		$content .= '<p>Cookies sú malé textové súbory, ktoré si webová stránka môže uložiť v prehliadači návštevníka. Pomáhajú zabezpečiť základné fungovanie webu, nákupného košíka, prihlásenia, objednávky alebo nastavení používateľa.</p>';

		$content .= '<h2>Ako ich používa KomArena.sk</h2>';
		$content .= '<p>KomArena.sk môže používať nevyhnutné cookies potrebné na fungovanie WordPressu, WooCommerce, košíka, objednávky, bezpečnosti a správy relácie. Voliteľné analytické, marketingové alebo preferenčné cookies sa majú používať iba vtedy, ak sú na webe reálne zapnuté a návštevník s nimi súhlasí, ak sa súhlas vyžaduje.</p>';

		$content .= '<h2>Typy cookies</h2>';
		$content .= '<table style="width:100%;border-collapse:collapse;border:1px solid rgba(0,151,157,.22);">';
		$content .= '<tbody>';
		$content .= '<tr><td style="padding:12px;border:1px solid rgba(0,151,157,.18);font-weight:800;">Nevyhnutné cookies</td><td style="padding:12px;border:1px solid rgba(0,151,157,.18);">Zabezpečujú základné fungovanie webu, košíka, objednávky, prihlásenia a bezpečnosti.</td></tr>';
		$content .= '<tr><td style="padding:12px;border:1px solid rgba(0,151,157,.18);font-weight:800;">Analytické cookies</td><td style="padding:12px;border:1px solid rgba(0,151,157,.18);">Použiť iba vtedy, ak je analytika reálne zapnutá. Pred publikovaním doplniť konkrétny nástroj a režim súhlasu.</td></tr>';
		$content .= '<tr><td style="padding:12px;border:1px solid rgba(0,151,157,.18);font-weight:800;">Marketingové cookies</td><td style="padding:12px;border:1px solid rgba(0,151,157,.18);">Použiť iba vtedy, ak sú reálne aktívne reklamné alebo remarketingové nástroje. Pred publikovaním doplniť poskytovateľov.</td></tr>';
		$content .= '<tr><td style="padding:12px;border:1px solid rgba(0,151,157,.18);font-weight:800;">Preferenčné cookies</td><td style="padding:12px;border:1px solid rgba(0,151,157,.18);">Môžu si pamätať používateľské voľby, ak sú takéto funkcie na webe zapnuté.</td></tr>';
		$content .= '</tbody></table>';

		$content .= '<h2>Správa súhlasu</h2>';
		$content .= '<p>Ak web používa voliteľné cookies, návštevník má mať možnosť ich prijať, odmietnuť alebo zmeniť nastavenie cez cookie lištu alebo nastavenia súhlasu. Presný text tejto časti treba zosúladiť s reálne použitým cookie nástrojom.</p>';

		$content .= '<h2>Tretie strany</h2>';
		$content .= '<p>Pred publikovaním treba preveriť, či web používa služby tretích strán, napríklad analytiku, reklamné siete, platobnú bránu, mapy, vložené videá, dopravcov, live chat alebo bezpečnostné nástroje. Ak áno, doplniť ich názov, účel a odkaz na ich pravidlá ochrany osobných údajov alebo cookies.</p>';

		$content .= '<h2>Ako cookies obmedziť</h2>';
		$content .= '<p>Návštevník môže cookies spravovať aj v nastaveniach svojho prehliadača. Obmedzenie nevyhnutných cookies môže spôsobiť, že niektoré funkcie webu, košíka alebo objednávky nebudú fungovať správne.</p>';

		$content .= '<h2>Kontakt</h2>';
		$content .= '<p>Otázky k ochrane osobných údajov a používaniu cookies je možné smerovať cez kontaktnú stránku KomArena.sk alebo na kontaktné údaje prevádzkovateľa uvedené v obchodných a GDPR dokumentoch webu.</p>';

		$content .= '<h2>Kontrola pred publikovaním</h2>';
		$content .= '<ul>';
		$content .= '<li>Skontrolovať reálny zoznam cookies v prehliadači a v použitom cookie nástroji.</li>';
		$content .= '<li>Doplniť konkrétnych poskytovateľov tretích strán, ak existujú.</li>';
		$content .= '<li>Overiť, či cookie lišta umožňuje odmietnutie a zmenu súhlasu, ak sa používa voliteľné sledovanie.</li>';
		$content .= '<li>Skontrolovať text s odborníkom alebo právnikom pred publikovaním.</li>';
		$content .= '</ul>';

		$content .= '<h2>Zdroje na kontrolu</h2>';
		$content .= '<ul>';
		foreach ($sources as $source) {
			$content .= '<li><a href="' . esc_url($source) . '" target="_blank" rel="noopener" style="color:#00777c;font-weight:800;text-decoration:underline;">' . esc_html($source) . '</a></li>';
		}
		$content .= '</ul>';
		$content .= '</div>';

		return array(
			'title'   => 'Cookies',
			'slug'    => 'cookies',
			'content' => $content,
			'sources' => $sources,
		);
	}

	private function normalize($text) {
		$text = remove_accents((string) $text);
		$text = strtolower($text);
		$text = preg_replace('/[^a-z0-9]+/', ' ', $text);
		return trim($text);
	}
}
