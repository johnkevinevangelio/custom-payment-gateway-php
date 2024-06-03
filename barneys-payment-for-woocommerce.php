<?php
/**
 * Class WC_Gateway_Barneys file.
 *
 * @package WooCommerce\Gateways
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Plugin Name: Barneys Payment for Woocommerce
 * Plugin URI: Custom
 * Author Name: John Kevin Evangelio
 * Author URI: 
 * Description: This plugin allows for local content payment systems.
 * Version: 0.1.0
 * License: 0.1.0
 * License URL: http://www.gnu.org/licenses/gpl-2.0.txt
 * text-domain: barneysmagicpot
*/

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_action( 'plugins_loaded', 'barneys_payment_init', 11 );

function barneys_payment_init() {
    if( class_exists( 'WC_Payment_Gateway' ) ) {
        require_once plugin_dir_path( __FILE__ ) . '/includes/class-wc-payment-barneys.php';
        require_once plugin_dir_path( __FILE__ ) . '/includes/barneys-checkout-confirmation.php';
    }
}


add_filter( 'woocommerce_payment_gateways', 'add_to_woo_barneys_payment_gateway');

function add_to_woo_barneys_payment_gateway( $gateways ) {
    $gateways[] = 'WC_Gateway_Barneys';
    return $gateways;
}