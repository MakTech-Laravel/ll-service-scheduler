<?php
defined( 'ABSPATH' ) || exit;

class LL_Sched_Frontend {

    public function __construct() {
        add_shortcode( 'll_schedule_service', array( $this, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

        // WooCommerce hooks
        add_action( 'woocommerce_before_calculate_totals',      array( $this, 'price_override' ), 20 );
        add_filter( 'woocommerce_get_item_data',                array( $this, 'cart_item_display' ), 10, 2 );
        add_filter( 'woocommerce_cart_item_name',               array( $this, 'cart_item_name' ), 10, 3 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_meta' ), 10, 4 );
        add_action( 'woocommerce_checkout_order_processed',        array( $this, 'save_order_booking_link' ), 10, 1 );
    }

    public function enqueue() {
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'll_schedule_service' ) ) return;

        wp_enqueue_style(  'll-sched', LL_SCHED_URL . 'assets/css/scheduler.css', array(), LL_SCHED_VER );
        wp_enqueue_script( 'll-sched', LL_SCHED_URL . 'assets/js/scheduler.js',   array(), LL_SCHED_VER, true );

        wp_localize_script( 'll-sched', 'llSched', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'll_sched_booking' ),
            'blockedDays' => array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) ),
            'timeSlots'   => (array) get_option( 'll_sched_time_slots', array() ),
            'selectionMode' => get_option( 'll_sched_selection_mode', 'multiple' ),
            // Per-service data (blocked days, time slots, days off) as JSON keyed by post ID
            'serviceData' => $this->build_service_data(),
        ) );
    }

    /**
     * Build a map of service_id => {blockedDays, timeSlots, daysOff, useCustom}
     * for the JS calendar to use per-service overrides.
     */
    private function build_service_data() {
        $services = get_posts( array(
            'post_type'      => 'services',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );
        $data = array();
        foreach ( $services as $id ) {
            $use_custom = get_post_meta( $id, '_ll_svc_use_custom', true ) === '1';
            $entry      = array(
                'useCustom'      => $use_custom,
                'propertySizes'  => array_values( (array) get_post_meta( $id, '_ll_svc_property_sizes', true ) ),
                'cities'         => array_values( (array) get_post_meta( $id, '_ll_svc_cities', true ) ),
            );

            if ( $use_custom ) {
                $entry['scheduleMode']  = get_post_meta( $id, '_ll_svc_schedule_mode', true ) ?: 'combine';
                $entry['availableDays'] = ll_sched_get_service_available_days( $id );
                $entry['timeSlots']     = (array) get_post_meta( $id, '_ll_svc_time_slots', true );
                $entry['daysOff']       = (array) get_post_meta( $id, '_ll_svc_days_off', true );
            }

            $data[ $id ] = $entry;
        }
        return $data;
    }

    /* ─────────────────────────────────────────────
       Shortcode renderer
    ───────────────────────────────────────────── */
    public function shortcode( $atts ) {
        $sizes  = (array) get_option( 'll_sched_property_sizes', array() );
        $cities = (array) get_option( 'll_sched_cities', array() );
        $cats   = get_terms( array( 'taxonomy' => 'service-category', 'hide_empty' => true, 'orderby' => 'name' ) );

        ob_start();
        include LL_SCHED_DIR . 'templates/scheduler.php';
        return ob_get_clean();
    }

    /* ─────────────────────────────────────────────
       WooCommerce: set price from booking data
    ───────────────────────────────────────────── */
    public function price_override( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        foreach ( $cart->get_cart() as $item ) {
            if ( isset( $item['ll_price'] ) ) {
                $item['data']->set_price( floatval( $item['ll_price'] ) );
            }
        }
    }

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
                $item_data[] = array( 'key' => $label, 'value' => esc_html( $cart_item[ $key ] ) );
            }
        }
        return $item_data;
    }

    public function cart_item_name( $name, $cart_item, $key ) {
        if ( ! empty( $cart_item['ll_services'] ) ) {
            return esc_html( $cart_item['ll_services'] );
        }
        return $name;
    }

    public function save_order_meta( $item, $cart_key, $cart_item, $order ) {
        $keys = array(
            'll_service_ids', 'll_services', 'll_date', 'll_time', 'll_start', 'll_end',
            'll_property_size', 'll_city', 'll_address', 'll_notes', 'll_price',
            'll_booking_id', 'll_jet_apt_id',
        );
        foreach ( $keys as $k ) {
            if ( isset( $cart_item[ $k ] ) ) {
                $item->add_meta_data( $k, $cart_item[ $k ], true );
            }
        }
    }

    /**
     * Store booking ID on the order itself as a backup link.
     */
    public function save_order_booking_link( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $booking_id = (int) $item->get_meta( 'll_booking_id' );
            if ( $booking_id ) {
                update_post_meta( $order_id, '_ll_booking_id', $booking_id );
                $jet_id = (int) $item->get_meta( 'll_jet_apt_id' );
                if ( $jet_id ) {
                    update_post_meta( $order_id, '_ll_jet_apt_id', $jet_id );
                }
                break;
            }
        }
    }
}
