<?php
/**
 * Plugin Name:       LL Service Scheduler
 * Plugin URI:        https://listinglenspro.com
 * Description:       Multi-service booking page with category grouping, calendar picker, time slots, and WooCommerce checkout. Admin-controlled settings.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ListingLens Dev
 * License:           GPL v2 or later
 * Text Domain:       ll-scheduler
 */

defined( 'ABSPATH' ) || exit;

define( 'LL_SCHED_VER',  '1.0.0' );
define( 'LL_SCHED_DIR',  plugin_dir_path( __FILE__ ) );
define( 'LL_SCHED_URL',  plugin_dir_url( __FILE__ ) );
define( 'LL_SCHED_FILE', __FILE__ );

/* ─────────────────────────────────────────────
   Boot all classes after all plugins are loaded
───────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
    require_once LL_SCHED_DIR . 'includes/class-admin.php';
    require_once LL_SCHED_DIR . 'includes/class-frontend.php';
    require_once LL_SCHED_DIR . 'includes/class-ajax.php';

    new LL_Sched_Admin();
    new LL_Sched_Frontend();
    new LL_Sched_Ajax();
} );

/* ─────────────────────────────────────────────
   Activation: seed default options
───────────────────────────────────────────── */
register_activation_hook( __FILE__, 'll_sched_activate' );

function ll_sched_activate() {

    if ( ! get_option( 'll_sched_property_sizes' ) ) {
        update_option( 'll_sched_property_sizes', array(
            '0-3000 Sq. Ft',
            '3001-6000 Sq. Ft',
            '6001-9000 Sq. Ft',
            '9001-12000 Sq. Ft',
        ) );
    }

    if ( ! get_option( 'll_sched_cities' ) ) {
        update_option( 'll_sched_cities', array( 'Montreal', 'Toronto', 'Vancouver' ) );
    }

    if ( ! get_option( 'll_sched_selection_mode' ) ) {
        update_option( 'll_sched_selection_mode', 'multiple' );
    }

    // 0 = Sunday … 6 = Saturday  (JS / PHP date convention)
    if ( ! get_option( 'll_sched_available_days' ) ) {
        update_option( 'll_sched_available_days', array( 0, 6 ) ); // Sat + Sun open by default
    }

    if ( ! get_option( 'll_sched_time_slots' ) ) {
        update_option( 'll_sched_time_slots', array(
            array( 'label' => '8:00 AM – 9:00 AM',   'start' => '08:00', 'end' => '09:00' ),
            array( 'label' => '9:00 AM – 10:00 AM',  'start' => '09:00', 'end' => '10:00' ),
            array( 'label' => '10:00 AM – 11:00 AM', 'start' => '10:00', 'end' => '11:00' ),
            array( 'label' => '11:00 AM – 12:00 PM', 'start' => '11:00', 'end' => '12:00' ),
            array( 'label' => '1:00 PM – 2:00 PM',   'start' => '13:00', 'end' => '14:00' ),
            array( 'label' => '2:00 PM – 3:00 PM',   'start' => '14:00', 'end' => '15:00' ),
            array( 'label' => '3:00 PM – 4:00 PM',   'start' => '15:00', 'end' => '16:00' ),
        ) );
    }

    // Create WooCommerce booking product (virtual, hidden from shop)
    if ( class_exists( 'WooCommerce' ) ) {
        ll_sched_ensure_woo_product();
    }
}

/* ─────────────────────────────────────────────
   Helper: create (or verify) the virtual product
   used to push the booking through WooCommerce
───────────────────────────────────────────── */
function ll_sched_ensure_woo_product() {
    $existing_id = (int) get_option( 'll_sched_woo_product_id', 0 );

    if ( $existing_id && get_post_status( $existing_id ) !== false ) {
        return $existing_id;
    }

    if ( ! class_exists( 'WC_Product_Simple' ) ) {
        return 0;
    }

    $product = new WC_Product_Simple();
    $product->set_name( 'Service Booking' );
    $product->set_status( 'publish' );
    $product->set_virtual( true );
    $product->set_regular_price( '0' );
    $product->set_catalog_visibility( 'hidden' );
    $product->set_sold_individually( true );
    $new_id = $product->save();

    update_option( 'll_sched_woo_product_id', $new_id );
    return $new_id;
}
