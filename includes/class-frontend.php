<?php
defined( 'ABSPATH' ) || exit;

class LL_Sched_Frontend {

    public function __construct() {
        add_shortcode( 'll_schedule_service', array( $this, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

        // WooCommerce: override the virtual product's price with booking total
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'price_override' ), 20 );

        // WooCommerce: show booking details in cart
        add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_display' ), 10, 2 );

        // WooCommerce: rename "Service Booking" product line to actual service names
        add_filter( 'woocommerce_cart_item_name', array( $this, 'cart_item_name' ), 10, 3 );

        // WooCommerce: save booking meta to order line item
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_meta' ), 10, 4 );
    }

    /* ─────────────────────────────────────────────
       Enqueue assets only on pages using our shortcode
    ───────────────────────────────────────────── */
    public function enqueue() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'll_schedule_service' ) ) {
            return;
        }

        wp_enqueue_style(
            'll-sched',
            LL_SCHED_URL . 'assets/css/scheduler.css',
            array(),
            LL_SCHED_VER
        );

        wp_enqueue_script(
            'll-sched',
            LL_SCHED_URL . 'assets/js/scheduler.js',
            array(),
            LL_SCHED_VER,
            true // load in footer
        );

        // Pass PHP config to JS
        wp_localize_script( 'll-sched', 'llSched', array(
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'll_sched_booking' ),
            'blockedDays'   => array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) ),
            'timeSlots'     => (array) get_option( 'll_sched_time_slots', array() ),
            'selectionMode' => get_option( 'll_sched_selection_mode', 'multiple' ),
        ) );
    }

    /* ─────────────────────────────────────────────
       Shortcode: [ll_schedule_service]
    ───────────────────────────────────────────── */
    public function shortcode( $atts ) {
        $sizes  = (array) get_option( 'll_sched_property_sizes', array() );
        $cities = (array) get_option( 'll_sched_cities', array() );

        // Fetch all non-empty service categories
        $cats = get_terms( array(
            'taxonomy'   => 'service-category',
            'hide_empty' => true,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );

        ob_start();
        include LL_SCHED_DIR . 'templates/scheduler.php';
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────────
       WooCommerce: set cart item price = booking total
    ───────────────────────────────────────────── */
    public function price_override( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $item ) {
            if ( isset( $item['ll_price'] ) ) {
                $item['data']->set_price( floatval( $item['ll_price'] ) );
            }
        }
    }

    /* ─────────────────────────────────────────────
       WooCommerce: display booking details in cart / checkout
    ───────────────────────────────────────────── */
    public function cart_item_display( $item_data, $cart_item ) {
        $map = array(
            'll_services'      => 'Services',
            'll_date'          => 'Booking Date',
            'll_time'          => 'Time Slot',
            'll_property_size' => 'Property Size',
            'll_city'          => 'City',
            'll_address'       => 'Address',
            'll_notes'         => 'Notes',
        );
        foreach ( $map as $key => $label ) {
            if ( ! empty( $cart_item[ $key ] ) ) {
                $item_data[] = array(
                    'key'   => $label,
                    'value' => esc_html( $cart_item[ $key ] ),
                );
            }
        }
        return $item_data;
    }

    /* ─────────────────────────────────────────────
       WooCommerce: show service names instead of
       "Service Booking" as the product title in cart
    ───────────────────────────────────────────── */
    public function cart_item_name( $name, $cart_item, $cart_item_key ) {
        if ( ! empty( $cart_item['ll_services'] ) ) {
            return esc_html( $cart_item['ll_services'] );
        }
        return $name;
    }

    /* ─────────────────────────────────────────────
       WooCommerce: persist booking data as order line-item meta
    ───────────────────────────────────────────── */
    public function save_order_line_meta( $item, $cart_item_key, $cart_item, $order ) {
        $keys = array(
            'll_service_ids',
            'll_services',
            'll_date',
            'll_time',
            'll_start',
            'll_end',
            'll_property_size',
            'll_city',
            'll_address',
            'll_notes',
            'll_price',
        );
        foreach ( $keys as $k ) {
            if ( isset( $cart_item[ $k ] ) ) {
                $item->add_meta_data( $k, $cart_item[ $k ], true );
            }
        }
    }
}
