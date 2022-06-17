<?php
/**
 * Plugin Name: SMARTy Pay Payment Gateway
 * Description: Accept cryptocurrencies on your WooCommerce website
 * Author: smartypay.io
 * Author URI: https://github.com/smarty-pay/smartypay-woocommerce
 * License: Apache License 2.0
 * License URI: https://raw.githubusercontent.com/smarty-pay/smartypay-woocommerce/main/LICENSE
 * Version: 1.0.0
 * WC tested up to: 5.8.2
 * WC requires at least: 2.6
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Detection
 */
if ( ! function_exists( 'is_woocommerce_active' ) ) {
    function is_woocommerce_active() {
        return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) );
    }
}

/**
 * Initialize the gateway.
 * @since 1.0.0
 */
function woocommerce_smartypayio_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	define( 'WC_GATEWAY_SMARTYPAYIO_VERSION', '1.0.0' );

	require_once( plugin_basename( 'includes/class-wc-smartypayio-payment-gateway.php' ) );
	load_plugin_textdomain( 'smartypayio-payment-gateway', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) );
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_smartypayio_add_gateway' );


}
add_action( 'plugins_loaded', 'woocommerce_smartypayio_init', 0 );

function woocommerce_smartypayio_plugin_links( $links ) {
	$settings_url = add_query_arg(
		array(
			'page' => 'wc-settings',
			'tab' => 'checkout',
			'section' => 'wc_smartypayio_payment_gateway',
		),
		admin_url( 'admin.php' )
	);

	$plugin_links = array(
		'<a href="' . esc_url( $settings_url ) . '">' . __( 'Settings', 'smartypayio-payment-gateway' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woocommerce_smartypayio_plugin_links' );


function smartypayio_add_currencies( $currencies ) {

	$nCurrencies = require( plugin_basename( 'includes/currencies.php' ) );

	if (is_array($nCurrencies) || is_object($nCurrencies)){
		foreach($nCurrencies as $k => $nCur){
			$currencies[$k] = $nCur['des'];
		}
	}

	return $currencies;
}
add_filter( 'woocommerce_currencies', 'smartypayio_add_currencies' );

function smartypayio_add_symbol( $smartypayio_symbol, $smartypayio_code) {

	$nCurrencies = require( plugin_basename( 'includes/currencies.php' ) );

	if (is_array($nCurrencies) || is_object($nCurrencies)){
		foreach($nCurrencies as $k => $nCur){
			if($smartypayio_code == $k){
				$smartypayio_symbol = $nCur['symbol'];
				break;
			}
		}
	}


	return $smartypayio_symbol;
}
add_filter('woocommerce_currency_symbol',  'smartypayio_add_symbol', 10, 2 );

/**
 * Add the gateway to WooCommerce
 * @since 1.0.0
 */
function woocommerce_smartypayio_add_gateway( $methods ) {
	$methods[] = 'WC_SmartyPayIo_Payment_Gateway';
	return $methods;
}
