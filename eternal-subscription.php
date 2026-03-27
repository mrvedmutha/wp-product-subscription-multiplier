<?php
/**
 * Plugin Name:       Eternal Subscription
 * Plugin URI:        https://github.com/mrvedmutha/wp-product-subscription-multiplier
 * Description:       Adds supply plan purchase options (3/6/9/12-month tiers) to WooCommerce products. One-time orders only — no recurring billing.
 * Version:           1.0.0
 * Author:            Eternal Labs
 * Text Domain:       eternal-subscription
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * WC requires at least: 8.0
 * WC tested up to:   9.0
 *
 * @package EternalSubscription
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESP_VERSION', '1.0.0' );
define( 'ESP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESP_URL', plugin_dir_url( __FILE__ ) );

/**
 * Initialise plugin after WooCommerce is loaded.
 *
 * @return void
 */
function esp_init(): void {
	require_once ESP_PATH . 'inc/class-esp-product-fields.php';
	require_once ESP_PATH . 'inc/class-esp-frontend.php';
	require_once ESP_PATH . 'inc/class-esp-cart.php';

	new ESP_Product_Fields();
	new ESP_Frontend();
	new ESP_Cart();
}
add_action( 'woocommerce_loaded', 'esp_init' );
