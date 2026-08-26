<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Attribute_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function apply($product_id, $research) {
		if (!class_exists('WC_Product_Attribute')) {
			return array();
		}

		$product = wc_get_product($product_id);
		if (!$product) {
			return array();
		}

		$values = array(
			'Čip / prvok'   => $research['chip'] ?? '',
			'Porty'         => $research['ports'] ?? '',
			'Konektory'     => $research['connectors'] ?? '',
			'Pin layout'    => $research['pin_layout'] ?? '',
			'Kompatibilita' => implode(', ', (array) ($research['compatibility'] ?? array())),
		);

		foreach ((array) ($research['specs'] ?? array()) as $key => $value) {
			if (in_array($key, array('Napajanie', 'Napájanie', 'Rozhranie', 'Logika', 'Meranie', 'Komunikacia'), true)) {
				$values[$key] = $value;
			}
		}

		$attributes = $product->get_attributes();
		$position = count($attributes);
		foreach ($values as $name => $value) {
			$value = trim(wp_strip_all_tags((string) $value));
			if ('' === $value) {
				continue;
			}

			$attribute = new WC_Product_Attribute();
			$attribute->set_id(0);
			$attribute->set_name($name);
			$attribute->set_options(array($value));
			$attribute->set_position($position++);
			$attribute->set_visible(true);
			$attribute->set_variation(false);
			$attributes[sanitize_title($name)] = $attribute;
		}

		$product->set_attributes($attributes);
		$product->save();

		update_post_meta($product_id, '_komarena_pf_attributes_applied', '1');
		return array_keys($values);
	}
}
