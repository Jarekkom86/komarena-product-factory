<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Pricing_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function price($research) {
		$name  = strtolower(remove_accents($research['input_name'] ?? ''));
		$supplier_price = !empty($research['supplier_data']['price']) ? (float) $research['supplier_data']['price'] : 0;
		$base  = $supplier_price > 0 ? $supplier_price : $this->base_price($name);
		$markup_percent = (int) $this->plugin->settings->get('price_markup_percent', 45);
		$competitor_prices = apply_filters('komarena_pf_competitor_prices', array(), $research);

		$target = $base * (1 + ($markup_percent / 100));
		if (!empty($competitor_prices)) {
			$valid = array_filter(array_map('floatval', $competitor_prices));
			if (!empty($valid)) {
				$avg = array_sum($valid) / count($valid);
				$target = max($target, $avg * 0.92);
			}
		}

		$target = max(1.99, $target);
		$price  = $this->ending_99($target);

		$reasoning = array(
			'base_estimate'       => round($base, 2),
			'supplier_price'      => $supplier_price,
			'markup_percent'      => $markup_percent,
			'competitor_prices'   => $competitor_prices,
			'final_rule'          => 'Cena je zaokruhlena tak, aby koncila na .99 a neklesla pod obchodne zdravy odhad.',
			'addon_recommendation'=> $price < 5 ? 'Pri lacnom produkte odporucit kable, piny, napajanie alebo projektovy balik.' : '',
		);

		$this->plugin->logger->log('info', 'Cena odporúčaná.', 'cena', array(
			'price'     => $price,
			'reasoning' => $reasoning,
		));

		return array(
			'price'     => function_exists('wc_format_decimal') ? wc_format_decimal($price, 2) : number_format($price, 2, '.', ''),
			'reasoning' => $reasoning,
		);
	}

	private function base_price($name) {
		$map = array(
			'esp32'      => 5.5,
			'esp8266'    => 3.2,
			'nodemcu'    => 3.8,
			'wemos'      => 3.4,
			'd1 mini'    => 3.4,
			'bme280'     => 3.2,
			'bmp280'     => 2.2,
			'dht22'      => 2.8,
			'am2302'     => 2.8,
			'ds18b20'    => 2.1,
			'relay'      => 1.8,
			'rele'       => 1.8,
			'arduino nano' => 4.0,
			'nano'       => 4.0,
			'rp2040'     => 4.2,
			'pico'       => 4.2,
			'oled'       => 2.8,
			'ssd1306'    => 2.8,
			'hc-sr04'    => 1.6,
			'hcsr04'     => 1.6,
			'hlk-pm'     => 5.8,
			'cable'      => 1.1,
			'kabel'      => 1.1,
		);

		foreach ($map as $needle => $price) {
			if (false !== strpos($name, $needle)) {
				return $price;
			}
		}

		return 3.5;
	}

	private function ending_99($amount) {
		$price = floor((float) $amount) + 0.99;
		if ($price < $amount) {
			$price += 1;
		}

		return round($price, 2);
	}
}
