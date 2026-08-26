<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Content_Engine {
	const STANDARD_VERSION = '1.1';
	const CANONICAL_TEMPLATE = 'arduino-mega-2560-product-2978';
	const CANONICAL_URL = 'https://komarena.sk/produkt/arduino-mega-2560-r3-klon-vyvojova-doska-s-atmega2560-2/';

	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function short_description($research) {
		if ($this->plugin && $this->plugin->settings->get('require_verified_product_facts', 1) && empty($research['fact_verification']['verified'])) {
			$research = $this->mask_unverified_research($research);
		}
		$name = esc_html($this->value($research, 'normalized_name', $this->value($research, 'input_name', 'Produkt')));
		$chip = esc_html($this->value($research, 'chip', 'podľa konkrétnej verzie / dodávky sa môže líšiť'));
		$use = esc_html($this->value($research, 'typical_use', 'vývoj, testovanie, výučbu, hobby alebo prototypové použitie'));
		$compatibility = $this->join_values((array) ($research['compatibility'] ?? array()), 3);

		return wp_kses_post(sprintf(
			'<p><strong>%s</strong> je technický produkt pre elektronické, IoT alebo automatizačné projekty. Hlavný čip alebo verzia: <strong>%s</strong>. Typické použitie: %s. Kompatibilita: %s.</p>',
			$name,
			$chip,
			$use,
			esc_html($compatibility ? $compatibility : 'podľa konkrétnej verzie a zapojenia')
		));
	}

	public function long_description($research, $images, $price_data) {
		$image_urls = $images['image_urls'] ?? $images;
		return $this->canonical_description($research, $image_urls, $price_data);
	}

	public function canonical_description($research, $image_urls, $price_data = array(), $categories = array()) {
		$image_urls = $this->normalize_image_urls($image_urls);
		$facts_verified = !empty($research['fact_verification']['verified']);
		if ($this->plugin && $this->plugin->settings->get('require_verified_product_facts', 1) && !$facts_verified) {
			$research = $this->mask_unverified_research($research);
		}
		$name = esc_html($this->value($research, 'normalized_name', $this->value($research, 'input_name', 'Produkt')));
		$model = esc_html($this->value($research, 'model', 'Podľa konkrétnej verzie / dodávky sa môže líšiť.'));
		$chip = esc_html($this->value($research, 'chip', 'Podľa konkrétnej verzie / dodávky sa môže líšiť.'));
		$ports = esc_html($this->value($research, 'ports', 'Podľa konkrétnej verzie / dodávky sa môže líšiť.'));
		$connectors = esc_html($this->value($research, 'connectors', 'Podľa konkrétnej verzie / dodávky sa môže líšiť.'));
		$pin_layout = esc_html($this->value($research, 'pin_layout', 'Podľa konkrétnej verzie / dodávky sa môže líšiť.'));
		$appearance = esc_html($this->value($research, 'appearance', 'Fyzické vyhotovenie treba overiť podľa konkrétnej dodávky.'));
		$typical_use = esc_html($this->value($research, 'typical_use', 'DIY elektronika, vývoj, testovanie alebo prototypovanie.'));
		$package = (array) ($research['package_contents'] ?? array('1x produkt / modul'));
		$compatibility = (array) ($research['compatibility'] ?? array('podľa konkrétnej verzie a zapojenia'));
		$links = $this->inline_links();
		$related = $this->related_terms($research, $categories);
		$alts = $this->alt_texts($research, $image_urls);
		$fact_note = $facts_verified
			? 'Technické tvrdenia v tomto popise sú naviazané na overené zdroje uložené v produktovom manifeste.'
			: 'Toto je pracovný návrh: konkrétne technické tvrdenia čakajú na 100 % zdrojové overenie a produkt nemôže prejsť medzi produkty pripravené na publikovanie.';

		$html = '<div class="komarena-product-description" data-komarena-standard="' . esc_attr(self::STANDARD_VERSION) . '" data-komarena-template="' . esc_attr(self::CANONICAL_TEMPLATE) . '" data-komarena-template-url="' . esc_url(self::CANONICAL_URL) . '" style="font-family:inherit;color:#334347;background:#ffffff;width:100%;max-width:100%;overflow:hidden;line-height:1.65;">';

		$html .= '<section data-komarena-section="Úvodný technický blok" style="border:1px solid rgba(0,151,157,.24);border-radius:18px;padding:18px;margin-bottom:14px;background:linear-gradient(90deg,#ffffff 0%,#f3ffff 100%);box-shadow:0 10px 26px rgba(15,35,45,.06);max-width:100%;overflow:hidden;">';
		$html .= '<div style="display:inline-flex;align-items:center;gap:8px;padding:7px 11px;border-radius:999px;background:#e9fbfb;border:1px solid rgba(0,151,157,.24);color:#00777c;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;max-width:100%;flex-wrap:wrap;"><span style="width:8px;height:8px;border-radius:50%;background:#00979D;display:inline-block;"></span>Úvodný technický blok</div>';
		$html .= '<div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;">';
		$html .= '<div style="flex:1 1 420px;min-width:0;">';
		$html .= '<h2 style="margin:0 0 10px;color:#102027;font-size:28px;line-height:1.16;font-weight:900;max-width:100%;overflow-wrap:break-word;">' . $name . '</h2>';
		$html .= '<p style="margin:0 0 10px;font-size:14.5px;line-height:1.65;max-width:100%;">' . $name . ' je technicky overovaný produkt pre KomArena projekty. Parametre, pinout a fyzické vyhotovenie sa pri klonoch a dodávkach môžu líšiť, preto sú neisté údaje označené opatrne.</p>';
		$html .= '<p style="margin:0 0 10px;font-size:13.5px;line-height:1.6;color:#00777c;font-weight:800;">' . esc_html($fact_note) . '</p>';
		$html .= '<p style="margin:0;font-size:13.5px;line-height:1.6;color:#6f7f84;">Model: <strong>' . $model . '</strong> | Čip/verzia: <strong>' . $chip . '</strong> | Typické použitie: ' . $typical_use . '</p>';
		$html .= '</div>';
		$html .= '<div style="flex:0 1 260px;min-width:220px;">' . $this->image_html($image_urls['main'], $alts['main'], true) . '</div>';
		$html .= '</div></section>';

		$html .= '<section data-komarena-section="3 KPI karty" style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px;">';
		$html .= $this->kpi_card($this->first_kpi_value($research), $this->first_kpi_label($research), 'hlavný technický parameter');
		$html .= $this->kpi_card($model, 'Model', 'stabilná identifikácia produktu');
		$html .= $this->kpi_card($this->join_values($compatibility, 2), 'Kompatibilita', 'podľa verzie a zapojenia');
		$html .= '</section>';

		$html .= $this->section('Prečo si vybrať ' . $name, '<ul style="margin:0;padding-left:18px;"><li>Jednotný KomArena popis podľa pevného produktového štandardu.</li><li>Technické parametre sú písané opatrne, bez vymýšľania a len podľa overených zdrojov.</li><li>Produkt je pripravený na projekty s vývojovými doskami, senzormi, modulmi a vhodným napájaním.</li><li>V popise sú priamo zapracované obrázky, bezpečnostné odporúčanie a odporúčané doplnky.</li></ul>');

		$html .= '<section data-komarena-section="Produktové obrázky" style="display:flex;flex-wrap:wrap;gap:14px;margin:0 0 18px;">';
		$html .= $this->image_panel('Pohľad z uhla', 'Lepšie viditeľné konektory, pinové lišty, porty alebo montážne prvky.', $image_urls['angle'], $alts['angle']);
		$html .= $this->image_panel('Detail produktu', 'Detail osadenia, pinov, čipu, konektorov alebo aktívnej časti modulu.', $image_urls['detail'], $alts['detail']);
		$html .= '</section>';

		$html .= $this->section('Pre koho je určený', '<ul style="margin:0;padding-left:18px;"><li>Pre školy, krúžky a výučbu elektroniky.</li><li>Pre makerov, vývojárov, servis a hobby prototypovanie.</li><li>Pre projekty s Arduino-kompatibilnými klonmi, ' . $links['esphome'] . ', ' . $links['home_assistant'] . ' alebo vlastnými elektronickými zapojeniami.</li><li>Pre používateľov, ktorí chcú rýchlo otestovať senzor, modul, výstup alebo riadiacu časť projektu.</li></ul>');
		$html .= $this->section('Praktický príklad použitia', '<p style="margin:0;">Produkt môžete použiť ako súčasť meracieho, riadiaceho alebo automatizačného zapojenia. Typický scenár: vývojová doska číta vstupy zo senzora alebo modulu, spracuje údaje v programe a následne ovláda výstup, displej, relé, motorový driver alebo odosiela dáta do dashboardu.</p>');
		$html .= $this->section('Ako to spolu funguje', '<p style="margin:0 0 10px;">Najprv overíte napájanie, logické úrovne, pinout a fyzické označenie na produkte. Potom produkt pripojíte k riadiacej elektronike, otestujete základnú funkciu a až následne ho zapojíte do reálneho projektu.</p><div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;"><span style="border-radius:999px;background:#e9fbfb;border:1px solid rgba(0,151,157,.24);padding:7px 10px;color:#00777c;font-weight:800;">Produkt</span><span style="padding:7px 0;color:#6f7f84;">→</span><span style="border-radius:999px;background:#e9fbfb;border:1px solid rgba(0,151,157,.24);padding:7px 10px;color:#00777c;font-weight:800;">Vývojová doska</span><span style="padding:7px 0;color:#6f7f84;">→</span><span style="border-radius:999px;background:#e9fbfb;border:1px solid rgba(0,151,157,.24);padding:7px 10px;color:#00777c;font-weight:800;">Spracovanie</span><span style="padding:7px 0;color:#6f7f84;">→</span><span style="border-radius:999px;background:#e9fbfb;border:1px solid rgba(0,151,157,.24);padding:7px 10px;color:#00777c;font-weight:800;">Výstup / automatizácia</span></div>');

		$html .= $this->project_usage_section($research, $links);
		$html .= $this->print_3d_section($research, $links);

		$html .= '<section data-komarena-section="Technické parametre" style="display:flex;flex-wrap:wrap;gap:18px;margin:0 0 18px;">';
		$html .= '<div style="flex:2 1 420px;min-width:0;"><h3 style="margin:0 0 8px;color:#102027;font-size:20px;line-height:1.25;font-weight:900;">Technické parametre</h3><p style="margin:0 0 10px;color:#6f7f84;">Prehľad základných parametrov pre návrh zapojenia a výber vhodných doplnkov.</p>' . $this->spec_table($research, $model, $chip, $ports, $connectors, $pin_layout, $appearance) . '</div>';
		$html .= '<div style="flex:1 1 260px;min-width:220px;">' . $this->image_html($image_urls['technical'], $alts['technical'], true) . '</div>';
		$html .= '</section>';

		$html .= $this->section('Kompatibilita', '<p style="margin:0;">' . esc_html($this->join_values($compatibility, 8)) . '. Presná kompatibilita závisí od konkrétnej verzie produktu, napájania, knižnice, firmvéru a spôsobu zapojenia. Užitočné kategórie: ' . $links['vyvojove'] . ', ' . $links['senzory'] . ', ' . $links['napajanie'] . '.</p>');
		$html .= $this->section('Typická kombinácia', '<p style="margin:0 0 10px;">Najčastejšie sa kombinuje s vývojovou doskou, prepojovacími vodičmi, vhodným napájaním a podľa projektu aj so senzormi, relé modulom, displejom alebo krabičkou.</p>' . $this->pills_html(array('vývojové dosky', 'prepojovacie vodiče', 'napájanie', 'senzory', 'moduly')));
		$html .= $this->section('Obsah balenia', $this->list_html($package));
		$html .= $this->section('Bezpečnostné odporúčanie', '<p style="margin:0;">Pracujte len v odporúčanom napätí a neprekračujte prúdové limity. Pri výkonových záťažiach používajte relé, MOSFET, driver alebo externý zdroj. Pri batériách a vyšších prúdoch používajte vhodné istenie. Trvalá elektroinštalácia patrí kvalifikovanej osobe.</p>', true);
		$html .= $this->section('Záruka a vrátenie', '<p style="margin:0;">Na nový tovar sa vzťahuje zákonná záruka 24 mesiacov. Pri online nákupe má zákazník štandardne 14 dní na odstúpenie od zmluvy. Produkt je určený na vývoj, testovanie, výučbu, hobby alebo prototypové použitie.</p>');
		$html .= $this->section('Poznámka ku klonu / variantom', '<p style="margin:0;">Ak ide o kompatibilný klon alebo modul od rôznych dodávateľov, nejde o originálny produkt výrobcu. USB prevodník, konektor, potlač, farba PCB, pinové lišty alebo drobné osadenie sa podľa konkrétnej verzie / dodávky môžu líšiť. Pred použitím porovnajte fyzický produkt s popisom na doske a overeným zdrojom.</p>');
		$html .= $this->section('Súvisiace produkty a odporúčané doplnky', '<p style="margin:0 0 10px;">Odporúčané doplnky sú generované ako interné KomArena odkazy, aby zákazník vedel rýchlo zostaviť použiteľnú projektovú kombináciu.</p>' . $this->pills_html($related));
		$html .= '</div>';

		return $this->sanitize_inline_html($html);
	}

	private function section($title, $body, $warning = false) {
		$bg = $warning ? '#fffdf7' : '#ffffff';
		$border = $warning ? 'rgba(214,144,0,.28)' : 'rgba(0,151,157,.18)';
		$color = $warning ? '#8a5a00' : '#00777c';

		return '<section data-komarena-section="' . esc_attr($title) . '" style="background:' . esc_attr($bg) . ';border:1px solid ' . esc_attr($border) . ';border-radius:16px;padding:18px;margin:0 0 18px;"><h3 style="color:' . esc_attr($color) . ';margin:0 0 10px;font-size:20px;line-height:1.25;font-weight:900;">' . esc_html($title) . '</h3>' . $body . '</section>';
	}

	private function kpi_card($value, $label, $note) {
		return '<div style="flex:1 1 190px;border:1px solid rgba(0,151,157,.22);border-radius:16px;padding:14px;background:#ffffff;"><span style="font-size:23px;font-weight:900;color:#00979D;margin-bottom:4px;display:block;">' . esc_html($value) . '</span><strong style="display:block;color:#102027;font-size:14px;">' . esc_html($label) . '</strong><span style="display:block;color:#6f7f84;font-size:13px;line-height:1.4;">' . esc_html($note) . '</span></div>';
	}

	private function image_panel($title, $text, $url, $alt) {
		return '<div style="flex:1 1 260px;border:1px solid rgba(0,151,157,.18);border-radius:16px;background:#ffffff;padding:12px;"><h3 style="margin:0 0 8px;color:#00777c;font-size:18px;">' . esc_html($title) . '</h3>' . $this->image_html($url, $alt, false) . '<p style="margin:8px 0 0;color:#6f7f84;font-size:13px;line-height:1.45;">' . esc_html($text) . '</p></div>';
	}

	private function project_usage_section($research, $links) {
		$scenarios = $this->project_scenarios($research);
		$terms = $this->project_terms($research);
		$cards = '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:12px 0 0;">';
		foreach ($scenarios as $scenario) {
			$cards .= '<div style="flex:1 1 210px;border:1px solid rgba(0,151,157,.20);border-radius:14px;background:#f8ffff;padding:13px;">';
			$cards .= '<strong style="display:block;color:#102027;font-size:14px;margin:0 0 5px;">' . esc_html($scenario['title']) . '</strong>';
			$cards .= '<span style="display:block;color:#334347;font-size:13px;line-height:1.5;">' . esc_html($scenario['text']) . '</span>';
			$cards .= '</div>';
		}
		$cards .= '</div>';

		$body = '<p style="margin:0 0 10px;">Tento produkt nie je len samostatná súčiastka. Agent k nemu pripraví aj praktické návody, projektové scenáre, odporúčané moduly, kompatibilné doplnky a prepojenia na články alebo smart-home použitie. Pri vhodných produktoch počíta aj s napojením na ' . $links['home_assistant'] . ' a ' . $links['esphome'] . '.</p>';
		$body .= $cards;
		$body .= '<p style="margin:12px 0 0;color:#6f7f84;">Ak presný návod alebo projektová stránka ešte neexistuje, odkaz vedie na interné vyhľadávanie KomArena.sk. Takto vie zákazník rýchlo nájsť súvisiace projekty, moduly, senzory, napájanie alebo budúce články k danému produktu.</p>';
		$body .= $this->pills_html($terms);

		return $this->section('Ako produkt využiť v projekte', $body);
	}

	private function project_scenarios($research) {
		$name = strtolower(remove_accents($this->value($research, 'input_name', $this->value($research, 'normalized_name', ''))));
		if (false !== strpos($name, 'hc-sr04') || false !== strpos($name, 'ultrazvuk')) {
			return array(
				array('title' => 'Meranie vzdialenosti', 'text' => 'Arduino alebo ESP doska odošle trigger impulz, modul vráti echo signál a program prepočíta čas na vzdialenosť. Pri 3,3 V ESP doskách treba riešiť 5 V echo signál cez vhodné prispôsobenie úrovní.'),
				array('title' => 'Home Assistant projekt', 'text' => 'S ESPHome sa dá pripraviť jednoduchý senzor vzdialenosti pre garáž, nádrž, robotiku alebo prehľadový dashboard.'),
				array('title' => 'Projektový balík', 'text' => 'Typicky sa kombinuje s ESP vývojovou doskou, prepojovacími vodičmi, 5 V napájaním, krabičkou a prípadne displejom. Konkrétny typ ESP zvoľte podľa projektu.'),
			);
		}
		if (false !== strpos($name, 'arduino') || false !== strpos($name, 'mega') || false !== strpos($name, 'uno')) {
			return array(
				array('title' => 'Riadiaca jednotka projektu', 'text' => 'Doska môže čítať senzory, ovládať výstupy, relé, displeje alebo motorové drivery a slúžiť ako základ výučbového alebo prototypového projektu.'),
				array('title' => 'Návod s modulmi', 'text' => 'Agent k produktu odporučí vhodné senzory, vodiče, napájanie a moduly, aby vznikol použiteľný projektový postup.'),
				array('title' => 'Rozšírenie do smart-home', 'text' => 'Pri vhodnom zapojení môže projekt posielať dáta ďalej do ESPHome, MQTT alebo Home Assistant scenára.'),
			);
		}
		if (false !== strpos($name, 'esp') || false !== strpos($name, 'esphome')) {
			return array(
				array('title' => 'WiFi senzor alebo ovládač', 'text' => 'ESP modul je vhodný na lokálne meranie, ovládanie alebo komunikáciu cez WiFi v domácej automatizácii.'),
				array('title' => 'ESPHome konfigurácia', 'text' => 'Pri kompatibilných moduloch vie agent pripraviť smerovanie na ESPHome návody, YAML príklady a Home Assistant integráciu.'),
				array('title' => 'Projektový doplnok', 'text' => 'Najčastejšie sa dopĺňa o napájanie, senzor, relé modul, krabičku alebo prepojovacie vodiče.'),
			);
		}
		if (false !== strpos($name, 'rele') || false !== strpos($name, 'relay')) {
			return array(
				array('title' => 'Bezpečné ovládanie výstupu', 'text' => 'Relé modul sa používa na oddelenie riadiacej elektroniky od záťaže. Pri sieťovom napätí patrí montáž kvalifikovanej osobe.'),
				array('title' => 'Automatizácia', 'text' => 'V spojení s ESPHome alebo Home Assistant môže slúžiť ako ovládač svetla, čerpadla, ventilátora alebo iného zariadenia podľa parametrov modulu.'),
				array('title' => 'Doplnky k projektu', 'text' => 'Odporúča sa vhodný zdroj, svorkovnica, poistka, krabička a vodiče zodpovedajúce záťaži.'),
			);
		}

		return array(
			array('title' => 'Rýchly laboratórny test', 'text' => 'Produkt najprv otestujte mimo finálneho zapojenia s vhodným napájaním, meraním a jednoduchým ukážkovým kódom.'),
			array('title' => 'Projekt s modulmi', 'text' => 'Agent odporučí kompatibilnú vývojovú dosku, napájanie, vodiče a súvisiace senzory alebo moduly.'),
			array('title' => 'Návod alebo článok', 'text' => 'Pri vhodnom produkte sa doplní cesta na KomArena návod, projektový článok, Home Assistant alebo ESPHome použitie.'),
		);
	}

	private function project_terms($research) {
		$name = strtolower(remove_accents($this->value($research, 'input_name', $this->value($research, 'normalized_name', ''))));
		if (false !== strpos($name, 'hc-sr04') || false !== strpos($name, 'ultrazvuk')) {
			return array('HC-SR04 ESPHome', 'meranie vzdialenosti', 'ESP HC-SR04', 'Home Assistant senzor vzdialenosti', 'Arduino kompatibilny HC-SR04 projekt', 'napájanie 5V');
		}
		if (false !== strpos($name, 'arduino') || false !== strpos($name, 'mega') || false !== strpos($name, 'uno')) {
			return array('Arduino kompatibilny klon navod', 'Arduino kompatibilny projekt', 'senzory pre Arduino kompatibilne dosky', 'rele modul pre Arduino kompatibilny klon', 'OLED displej pre kompatibilne dosky', 'prepojovacie vodiče');
		}
		if (false !== strpos($name, 'esp') || false !== strpos($name, 'esphome')) {
			return array('ESPHome návod', 'Home Assistant ESP', 'ESP projekt', 'senzory ESPHome', 'napájanie 5V', 'krabička pre senzor');
		}
		if (false !== strpos($name, 'rele') || false !== strpos($name, 'relay')) {
			return array('relé modul návod', 'ESPHome relé', 'Home Assistant relé', 'MOSFET modul', 'napájací zdroj', 'svorkovnica');
		}

		return array('KomArena návod', 'projekt s modulom', 'Home Assistant projekt', 'ESPHome návod', 'Arduino kompatibilny projekt', 'prepojovacie vodiče');
	}

	private function print_3d_section($research, $links) {
		$items = $this->print_3d_items($research);
		$terms = $this->print_3d_terms($research);
		$cards = '<div style="display:flex;flex-wrap:wrap;gap:10px;margin:12px 0 0;">';
		foreach ($items as $item) {
			$cards .= '<div style="flex:1 1 210px;border:1px solid rgba(0,151,157,.20);border-radius:14px;background:#f8ffff;padding:13px;">';
			$cards .= '<strong style="display:block;color:#102027;font-size:14px;margin:0 0 5px;">' . esc_html($item['title']) . '</strong>';
			$cards .= '<span style="display:block;color:#334347;font-size:13px;line-height:1.5;">' . esc_html($item['text']) . '</span>';
			$cards .= '</div>';
		}
		$cards .= '</div>';

		$body = '<p style="margin:0 0 10px;">K tomuto produktu sa dá pripraviť aj samostatný KomArena 3D tlačený doplnok: držiak, stojan, krabička, panelový adaptér alebo montážny diel priradený ku konkrétnemu senzoru, modulu alebo projektu. Zákazník tak nemusí hľadať model ani riešiť tlač – na e-shope môže dostať hotový fyzický doplnok navrhnutý pre praktické použitie.</p>';
		$body .= $cards;
		$body .= '<p style="margin:12px 0 0;color:#6f7f84;">K doplnku má vzniknúť aj súvisiaci blog alebo projektový návod: ako diel vyzerá, ku ktorému senzoru alebo modulu patrí, ako sa produkt osádza, aké skrutky alebo káble sa hodia a ako zapojenie pracuje s ' . $links['home_assistant'] . ', ' . $links['esphome'] . ' alebo vývojovou doskou, ak je to relevantné.</p>';
		$body .= $this->print_3d_download_links($research);
		$body .= $this->pills_html($terms);

		return $this->section('3D tlačený doplnok k produktu', $body);
	}

	private function print_3d_items($research) {
		$name = strtolower(remove_accents($this->value($research, 'input_name', $this->value($research, 'normalized_name', ''))));
		if (false !== strpos($name, 'hc-sr04') || false !== strpos($name, 'ultrazvuk')) {
			return array(
				array('title' => 'Držiak senzora', 'text' => 'Predný otvor musí sedieť na dvojicu ultrazvukových meničov a nesmie zakrývať trigger/echo piny ani montážne otvory. Pri ESP projekte s 3,3 V logikou rátajte aj s prevodom 5 V echo signálu.'),
				array('title' => 'Projektová krabička', 'text' => 'Vhodná je krabička pre ESP modul alebo Arduino-kompatibilnú dosku s miestom na káble, napájanie a senzor orientovaný smerom von. Diel treba upraviť podľa reálneho rozmeru dosky a konektorov.'),
				array('title' => 'Panelový adaptér', 'text' => 'Pre nádrž, garáž alebo robotiku sa hodí panel s čelným osadením senzora a skrytým vedením káblov. Pred tlačou overte vzdialenosť otvorov a hrúbku panelu.'),
			);
		}
		if (false !== strpos($name, 'esp') || false !== strpos($name, 'esphome')) {
			return array(
				array('title' => 'Krabička pre ESP', 'text' => 'Krabička musí mať priestor na USB konektor, reset/boot tlačidlá, anténu a vetranie. Pri WiFi moduloch nezakrývajte anténnu časť kovom ani hustou výplňou.'),
				array('title' => 'Držiak na stenu alebo DIN', 'text' => 'Pre smart-home inštalácie je praktický stenový alebo DIN adaptér s možnosťou vyvedenia napájania a senzorových káblov.'),
				array('title' => 'Servisný prístup', 'text' => 'Model má umožniť vybrať dosku, pripojiť USB a skontrolovať piny bez poškodenia kabeláže. Rozmery klonov sa podľa dodávky môžu líšiť.'),
			);
		}
		if (false !== strpos($name, 'arduino') || false !== strpos($name, 'mega') || false !== strpos($name, 'uno')) {
			return array(
				array('title' => 'Držiak vývojovej dosky', 'text' => 'Držiak musí rešpektovať montážne otvory, USB konektor, napájací jack a pinové lišty. Pri klonoch sa presná poloha konektorov môže líšiť.'),
				array('title' => 'Projektový panel', 'text' => 'Panel spojí dosku, breadboard, svorkovnice a senzory do prehľadnej testovacej zostavy pre výučbu alebo prototypovanie.'),
				array('title' => 'Ochrana pred skratom', 'text' => 'Krabička alebo distančný rám pomáha oddeliť spodnú stranu PCB od kovovej plochy a znižuje riziko nechceného kontaktu.'),
			);
		}
		if (false !== strpos($name, 'rele') || false !== strpos($name, 'relay')) {
			return array(
				array('title' => 'Bezpečná krabička', 'text' => 'Relé modul vyžaduje oddelenie nízkeho a vyššieho napätia, mechanickú ochranu svoriek a pri sieťovom napätí odbornú montáž.'),
				array('title' => 'Montážny rám', 'text' => 'Rám alebo DIN adaptér pomôže uchytiť modul tak, aby sa svorky neuvoľnili a káble neťahali za dosku.'),
				array('title' => 'Popis vodičov', 'text' => 'Pri tlačenom boxe je vhodné doplniť štítky vstupov/výstupov a priestor na poistku alebo svorkovnicu podľa projektu.'),
			);
		}

		return array(
			array('title' => 'Montážny diel', 'text' => 'Držiak alebo rám musí fyzicky sedieť na reálny produkt a nesmie zakrývať piny, konektory, chladiace plochy ani meraciu časť modulu.'),
			array('title' => 'Projektová krabička', 'text' => 'Krabička má chrániť elektroniku, ale zároveň ponechať prístup ku káblom, napájaniu a prípadným tlačidlám alebo nastavovacím prvkom.'),
			array('title' => 'Overenie pred tlačou', 'text' => 'Pred tlačou porovnajte rozmery modelu s konkrétnou dodávkou produktu, orientáciu otvorov a licenciu modelu na použitie.'),
		);
	}

	private function print_3d_terms($research) {
		$name = strtolower(remove_accents($this->value($research, 'input_name', $this->value($research, 'normalized_name', ''))));
		if (false !== strpos($name, 'hc-sr04') || false !== strpos($name, 'ultrazvuk')) {
			return array('HC-SR04 držiak', 'stojan pre ultrazvukový senzor', 'ESP krabička HC-SR04', 'senzor vzdialenosti držiak', 'KomArena 3D doplnok', 'Home Assistant projekt s HC-SR04');
		}
		if (false !== strpos($name, 'esp') || false !== strpos($name, 'esphome')) {
			return array('ESP krabička', 'ESPHome krabička', 'ESP DIN držiak', 'ESP senzorová krabička', 'KomArena 3D doplnok', 'Home Assistant ESP projekt');
		}
		if (false !== strpos($name, 'arduino') || false !== strpos($name, 'mega') || false !== strpos($name, 'uno')) {
			return array('Arduino kompatibilny Mega klon krabicka', 'drziak pre Arduino kompatibilny klon', 'projektovy panel pre vyvojovu dosku', 'UNO kompatibilny klon krabicka', 'KomArena 3D doplnok', 'Arduino kompatibilny navod');
		}
		if (false !== strpos($name, 'rele') || false !== strpos($name, 'relay')) {
			return array('relé modul krabička', 'DIN držiak relé modul', 'relé modul box', 'ESPHome relé projekt', 'KomArena 3D doplnok', 'bezpečná krabička elektroniky');
		}

		return array('3D doplnok elektronika', 'krabička pre modul', 'držiak senzora', 'projektová krabička', 'KomArena 3D doplnok', 'hotový tlačený držiak');
	}

	private function print_3d_download_links($research) {
		$name = $this->value($research, 'input_name', $this->value($research, 'normalized_name', 'KomArena produkt'));
		$komarena = add_query_arg(array('s' => '3D doplnok ' . $name), home_url('/'));
		$accessory = add_query_arg(array('s' => 'držiak ' . $name), home_url('/'));
		$project = add_query_arg(array('s' => 'projekt ' . $name), home_url('/'));
		$blog = add_query_arg(array('s' => $name . ' návod projekt'), home_url('/'));

		$links = array(
			array('label' => 'KomArena 3D doplnky', 'url' => $komarena),
			array('label' => 'Držiak alebo stojan', 'url' => $accessory),
			array('label' => 'Projekt s doplnkom', 'url' => $project),
			array('label' => 'KomArena projektový návod', 'url' => $blog),
		);

		$html = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 0;">';
		foreach ($links as $link) {
			$html .= '<a href="' . esc_url($link['url']) . '" target="_blank" rel="nofollow noopener noreferrer" style="display:inline-block;border:1px solid #00979D;background:#ffffff;color:#00777c;border-radius:999px;padding:8px 12px;text-decoration:none;font-weight:800;font-size:13px;">' . esc_html($link['label']) . '</a>';
		}
		$html .= '</div>';

		return $html;
	}

	private function image_html($url, $alt, $framed) {
		if (!$url) {
			return '';
		}

		$style = $framed
			? 'display:block;width:100%;height:auto;object-fit:contain;border:1px solid rgba(0,151,157,.20);border-radius:16px;background:#ffffff;padding:10px;box-shadow:0 10px 24px rgba(15,35,45,.07);'
			: 'display:block;width:100%;height:auto;object-fit:contain;border-radius:14px;background:#ffffff;';

		return '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" loading="lazy" style="' . esc_attr($style) . '">';
	}

	private function spec_table($research, $model, $chip, $ports, $connectors, $pin_layout, $appearance) {
		$rows = array(
			'Typ produktu' => $this->value($research, 'normalized_name', $this->value($research, 'input_name', 'Produkt')),
			'Model' => $model,
			'Čip / verzia' => $chip,
			'Porty' => $ports,
			'Konektory' => $connectors,
			'Rozloženie pinov' => $pin_layout,
			'Fyzický vzhľad' => $appearance,
		);

		foreach ((array) ($research['specs'] ?? array()) as $key => $value) {
			$key = sanitize_text_field((string) $key);
			if ('' !== $key) {
				$rows[$key] = $value;
			}
		}

		$html = '<table style="width:100%;border-collapse:collapse;background:#ffffff;border:1px solid rgba(0,151,157,.24);"><tbody>';
		foreach ($rows as $key => $value) {
			$html .= '<tr><th style="text-align:left;padding:10px;border-bottom:1px solid rgba(0,151,157,.16);color:#334347;width:34%;vertical-align:top;">' . esc_html($key) . '</th><td style="padding:10px;border-bottom:1px solid rgba(0,151,157,.16);vertical-align:top;">' . esc_html((string) $value) . '</td></tr>';
		}
		$html .= '</tbody></table>';

		return $html;
	}

	private function list_html($items) {
		$html = '<ul style="margin:0;padding-left:18px;">';
		foreach ((array) $items as $item) {
			$html .= '<li>' . esc_html((string) $item) . '</li>';
		}
		$html .= '</ul>';

		return $html;
	}

	private function inline_links() {
		return array(
			'vyvojove' => $this->link('https://komarena.sk/kategoria-produktu/elektronika/vyvojove-dosky/', 'vývojové dosky'),
			'senzory' => $this->link('https://komarena.sk/kategoria-produktu/senzory/', 'senzory'),
			'napajanie' => $this->link('https://komarena.sk/kategoria-produktu/napajanie/', 'napájanie'),
			'home_assistant' => $this->link('https://komarena.sk/home-assistant/', 'Home Assistant'),
			'esphome' => $this->link('https://komarena.sk/esp-esphome/', 'ESP / ESPHome'),
		);
	}

	private function link($url, $label) {
		return '<a href="' . esc_url($url) . '" style="color:#00777c;font-weight:800;text-decoration:underline;">' . esc_html($label) . '</a>';
	}

	private function pills_html($terms) {
		$html = '<div style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 0;">';
		foreach (array_values(array_unique((array) $terms)) as $term) {
			$term = trim((string) $term);
			if ('' === $term) {
				continue;
			}
			$url = add_query_arg(array('s' => $term), home_url('/'));
			$html .= '<a href="' . esc_url($url) . '" style="display:inline-block;border:1px solid #00979D;background:#e9fbfb;color:#00777c;border-radius:999px;padding:8px 12px;text-decoration:none;font-weight:800;font-size:13px;">' . esc_html($term) . '</a>';
		}
		$html .= '</div>';

		return $html;
	}

	private function related_terms($research, $categories) {
		$name = strtolower(remove_accents($this->value($research, 'input_name', $this->value($research, 'normalized_name', ''))));
		if (false !== strpos($name, 'arduino') || false !== strpos($name, 'mega') || false !== strpos($name, 'uno')) {
			return array('prepojovacie vodiče', 'napájací adaptér', 'relé modul', 'OLED displej', 'motorový driver', 'senzor HC-SR04');
		}
		if (false !== strpos($name, 'esp') || false !== strpos($name, 'esphome')) {
			return array('ESPHome', 'Home Assistant', 'prepojovacie vodiče', 'napájanie 5V', 'senzory', 'krabička');
		}
		if (false !== strpos($name, 'senzor') || false !== strpos($name, 'bme') || false !== strpos($name, 'dht') || false !== strpos($name, 'hc-sr04')) {
			return array('ESP vývojová doska', 'UNO kompatibilný klon', 'prepojovacie vodiče', 'napájanie', 'Home Assistant', 'ESPHome');
		}

		return array_merge((array) $categories, array('ESP', 'Arduino kompatibilné klony', 'prepojovacie vodiče', 'napájanie', 'senzory', 'vývojové dosky'));
	}

	private function normalize_image_urls($image_urls) {
		$defaults = array('main' => '', 'angle' => '', 'detail' => '', 'technical' => '');
		if (!is_array($image_urls)) {
			return $defaults;
		}

		$variants = array('main', 'angle', 'detail', 'technical');
		foreach ($variants as $index => $variant) {
			if (!empty($image_urls[$variant])) {
				$defaults[$variant] = esc_url_raw($image_urls[$variant]);
			} elseif (!empty($image_urls[$index])) {
				$defaults[$variant] = esc_url_raw($image_urls[$index]);
			}
		}

		return $defaults;
	}

	private function mask_unverified_research($research) {
		$note = 'Čaká na 100 % zdrojové overenie z technického listu, dokumentácie, overeného distribútorského listingu alebo internej KomArena referencie.';
		$research['model'] = $note;
		$research['chip'] = $note;
		$research['ports'] = $note;
		$research['connectors'] = $note;
		$research['pin_layout'] = $note;
		$research['appearance'] = 'Fyzický vzhľad sa nesmie tvrdiť bez reálneho obrázka alebo overeného produktového zdroja.';
		$research['typical_use'] = 'Pracovný návrh; praktické použitie sa potvrdí až po overení zdrojov.';
		$research['specs'] = array(
			'Zdrojový stav' => 'na kontrolu',
			'Technické parametre' => $note,
		);
		$research['compatibility'] = array('Overiť podľa technického listu, dokumentácie alebo distribútorského listingu');
		$research['package_contents'] = array('Obsah balenia overiť podľa konkrétnej dodávky');

		return $research;
	}

	private function alt_texts($research, $image_urls) {
		$name = $this->value($research, 'normalized_name', $this->value($research, 'input_name', 'KomArena produkt'));
		return array(
			'main' => $name . ' – hlavný produktový pohľad',
			'angle' => $name . ' – pohľad z uhla na konektory a rozloženie',
			'detail' => $name . ' – detail pinov, čipu alebo konektorov',
			'technical' => $name . ' – technický pohľad na layout produktu',
		);
	}

	private function first_kpi_label($research) {
		$specs = (array) ($research['specs'] ?? array());
		foreach ($specs as $key => $value) {
			if ('' !== trim((string) $key) && '' !== trim((string) $value)) {
				return (string) $key;
			}
		}
		return 'Technický parameter';
	}

	private function first_kpi_value($research) {
		$specs = (array) ($research['specs'] ?? array());
		foreach ($specs as $value) {
			if ('' !== trim((string) $value)) {
				return wp_trim_words((string) $value, 5, '');
			}
		}
		return wp_trim_words($this->value($research, 'chip', $this->value($research, 'model', 'Overené')), 5, '');
	}

	private function join_values($items, $limit) {
		$items = array_values(array_filter(array_map('trim', array_map('strval', (array) $items))));
		return implode(', ', array_slice($items, 0, $limit));
	}

	private function value($array, $key, $default = '') {
		return isset($array[$key]) && '' !== (string) $array[$key] ? (string) $array[$key] : $default;
	}

	private function sanitize_inline_html($html) {
		$allowed = wp_kses_allowed_html('post');
		$tags = array('div', 'section', 'h2', 'h3', 'p', 'strong', 'span', 'ul', 'ol', 'li', 'table', 'tbody', 'tr', 'th', 'td', 'figure', 'img', 'a');
		foreach ($tags as $tag) {
			if (!isset($allowed[$tag])) {
				$allowed[$tag] = array();
			}
			$allowed[$tag]['style'] = true;
			$allowed[$tag]['class'] = true;
			$allowed[$tag]['data-komarena-standard'] = true;
			$allowed[$tag]['data-komarena-template'] = true;
			$allowed[$tag]['data-komarena-template-url'] = true;
			$allowed[$tag]['data-komarena-section'] = true;
		}
		$allowed['a']['href'] = true;
		$allowed['a']['target'] = true;
		$allowed['a']['rel'] = true;
		$allowed['img']['src'] = true;
		$allowed['img']['alt'] = true;
		$allowed['img']['width'] = true;
		$allowed['img']['height'] = true;
		$allowed['img']['loading'] = true;

		return wp_kses($html, $allowed);
	}
}
