<?php
/**
 * Plugin Name: KomArena produktový agent
 * Description: Agentný WooCommerce systém na tvorbu, audit a prebudovanie produktov podľa KomArena štandardu.
 * Version: 2.6.1
 * Author: KomArena
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: komarena-product-factory
 */

if (!defined('ABSPATH')) {
	exit;
}

if (class_exists('KomArena_Product_Factory')) {
	add_action('admin_notices', function() {
		echo '<div class="notice notice-warning"><p>KomArena produktový agent je už načítaný z iného priečinka. Nechajte aktívnu iba jednu inštaláciu doplnku.</p></div>';
	});
	return;
}

if (!defined('KOMARENA_PF_VERSION')) {
	define('KOMARENA_PF_VERSION', '2.6.1');
}
if (!defined('KOMARENA_PF_FILE')) {
	define('KOMARENA_PF_FILE', __FILE__);
}
if (!defined('KOMARENA_PF_PATH')) {
	define('KOMARENA_PF_PATH', plugin_dir_path(__FILE__));
}
if (!defined('KOMARENA_PF_URL')) {
	define('KOMARENA_PF_URL', plugin_dir_url(__FILE__));
}

require_once KOMARENA_PF_PATH . 'includes/class-komarena-product-factory-activator.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-pf-migrated-source-guard.php';
require_once KOMARENA_PF_PATH . 'includes/class-komarena-product-factory.php';

register_activation_hook(__FILE__, array('KomArena_Product_Factory_Activator', 'activate'));
register_deactivation_hook(__FILE__, array('KomArena_Product_Factory_Activator', 'deactivate'));

add_action('plugins_loaded', array('KomArena_Product_Factory', 'instance'));
