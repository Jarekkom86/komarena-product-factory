<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Keeps legacy simple products non-purchasable after they are migrated into
 * variable-product children. The legacy post itself stays published/hidden so
 * order history and SEO provenance are preserved until the new parent is live.
 */
final class KomArena_PF_Migrated_Source_Guard {
	public static function boot() {
		add_filter('woocommerce_is_purchasable', array(__CLASS__, 'filter_is_purchasable'), 10, 2);
		add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_add_to_cart'), 10, 6);
	}

	public static function filter_is_purchasable($purchasable, $product) {
		if (!$purchasable) {
			return false;
		}

		return self::is_migrated_source($product) ? false : $purchasable;
	}

	public static function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array()) {
		if (!$passed || !self::is_migrated_source($product_id)) {
			return $passed;
		}

		if (function_exists('wc_add_notice')) {
			wc_add_notice(
				__('Tento samostatný variant produktu bol presunutý pod výber farieb. Otvorte aktuálny produkt a zvoľte farbu.', 'komarena-product-factory'),
				'error'
			);
		}

		return false;
	}

	private static function is_migrated_source($product_or_id) {
		$product_id = 0;

		if (is_object($product_or_id) && method_exists($product_or_id, 'get_id')) {
			$product_id = absint($product_or_id->get_id());
		} else {
			$product_id = absint($product_or_id);
		}

		if (!$product_id || 'product' !== get_post_type($product_id)) {
			return false;
		}

		return absint(get_post_meta($product_id, '_komarena_pf_migrated_to_parent', true)) > 0;
	}
}

KomArena_PF_Migrated_Source_Guard::boot();
