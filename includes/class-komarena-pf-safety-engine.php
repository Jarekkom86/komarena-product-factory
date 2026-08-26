<?php

if (!defined('ABSPATH')) {
	exit;
}

class KomArena_PF_Safety_Engine {
	private $plugin;

	public function __construct($plugin) {
		$this->plugin = $plugin;
	}

	public function analyze($research) {
		$core_text = strtolower(remove_accents(implode(' ', array(
			$research['input_name'] ?? '',
			$research['normalized_name'] ?? '',
			$research['model'] ?? '',
			$research['chip'] ?? '',
			implode(' ', (array) ($research['categories'] ?? array())),
		))));
		$usage_text = strtolower(remove_accents((string) ($research['typical_use'] ?? '')));
		$text = trim($core_text . ' ' . $usage_text);

		$mains_terms = preg_match('/\b(230v|220v|230\s*v|220\s*v|ac\/dc|ac dc|hlk-pm|hi-link|siet|sietove)\b/', $text);
		$relay_product = preg_match('/\b(relay|rele|releovy|rel[eé])\b/', $core_text);
		$high_voltage = $mains_terms || $relay_product;

		if ($high_voltage) {
			return array(
				'level'             => 'high',
				'risk'              => 'mains_voltage',
				'required_phrase'   => 'odborna montaz',
				'warning'           => 'Produkt moze suvisiet so sietovym napatim. Vyhradit odbornu montaz a neposkytovat nebezpecne zjednodusene zapojenia.',
				'ready_blocker'     => false,
			);
		}

		return array(
			'level'         => 'standard',
			'risk'          => 'low_voltage_electronics',
			'warning'       => 'Standardne nizkonapatove odporucania.',
			'ready_blocker' => false,
		);
	}
}
