<?php
defined( 'ABSPATH' ) || exit;

class LL_Sched_Frontend {

    public function __construct() {
        add_shortcode( 'll_schedule_service', array( $this, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

        // WooCommerce hooks
        add_action( 'woocommerce_before_calculate_totals',         array( $this, 'price_override' ), 20 );
        add_filter( 'woocommerce_get_cart_item_from_session',    array( $this, 'restore_cart_item_session' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data',                 array( $this, 'cart_item_display' ), 10, 2 );
        add_filter( 'woocommerce_cart_item_name',                array( $this, 'cart_item_name' ), 10, 3 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_meta' ), 10, 4 );
        add_action( 'woocommerce_checkout_order_processed',        array( $this, 'save_order_booking_link' ), 10, 1 );
        add_filter( 'woocommerce_order_item_display_meta_key',   array( $this, 'order_item_meta_label' ), 10, 3 );
        add_filter( 'woocommerce_hidden_order_itemmeta',         array( $this, 'hide_internal_order_meta' ) );
        add_action( 'woocommerce_before_checkout_form',            array( $this, 'render_checkout_booking_summary' ), 5 );
    }

    /**
     * Custom booking keys stored on cart line items.
     */
    private function booking_cart_keys() {
        return array(
            'll_price', 'll_service_ids', 'll_services', 'll_date', 'll_time', 'll_start', 'll_end',
            'll_property_size', 'll_city', 'll_address', 'll_notes', 'll_booking_id', 'll_jet_apt_id',
        );
    }

    /**
     * Restore booking data when the cart is loaded from session (required for checkout display).
     */
    public function restore_cart_item_session( $cart_item, $values, $key ) {
        foreach ( $this->booking_cart_keys() as $booking_key ) {
            if ( isset( $values[ $booking_key ] ) ) {
                $cart_item[ $booking_key ] = $values[ $booking_key ];
            }
        }
        return $cart_item;
    }

    public function enqueue() {
        global $post;
        $on_scheduler = $post && has_shortcode( $post->post_content, 'll_schedule_service' );
        $on_checkout  = function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() );

        if ( ! $on_scheduler && ! $on_checkout ) {
            return;
        }

        if ( $on_checkout ) {
            wp_enqueue_style( 'll-sched-checkout', LL_SCHED_URL . 'assets/css/scheduler.css', array(), LL_SCHED_VER );
        }

        if ( ! $on_scheduler ) {
            return;
        }

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
                'addresses'      => array_values( (array) get_post_meta( $id, '_ll_svc_addresses', true ) ),
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
        $sizes     = (array) get_option( 'll_sched_property_sizes', array() );
        $cities    = (array) get_option( 'll_sched_cities', array() );
        $addresses = (array) get_option( 'll_sched_addresses', array() );
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

    /**
     * Human-readable booking details for cart and checkout line items.
     */
    private function format_booking_details_html( $cart_item ) {
        $lines = array();

        $map = array(
            'll_property_size' => __( 'Property Size', 'll-service-scheduler' ),
            'll_city'          => __( 'City', 'll-service-scheduler' ),
            'll_address'       => __( 'Address', 'll-service-scheduler' ),
            'll_date'          => __( 'Booking Date', 'll-service-scheduler' ),
            'll_time'          => __( 'Time Slot', 'll-service-scheduler' ),
        );

        foreach ( $map as $key => $label ) {
            if ( ! empty( $cart_item[ $key ] ) ) {
                $lines[] = '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $cart_item[ $key ] );
            }
        }

        if ( empty( $lines ) ) {
            return '';
        }

        return '<div class="ll-checkout-booking-meta">' . implode( '<br>', $lines ) . '</div>';
    }

    /**
     * Booking summary box above the checkout form (classic checkout).
     */
    public function render_checkout_booking_summary() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( empty( $cart_item['ll_services'] ) ) {
                continue;
            }

            echo '<div class="ll-checkout-booking-summary woocommerce-info">';
            echo '<p><strong>' . esc_html__( 'Booking Details', 'll-service-scheduler' ) . '</strong></p>';
            echo '<p>' . esc_html( $cart_item['ll_services'] ) . '</p>';
            echo $this->format_booking_details_html( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</div>';
            break;
        }
    }

    /**
     * Friendly labels for booking meta on order confirmation and emails.
     */
    public function order_item_meta_label( $display_key, $meta, $item ) {
        $map = array(
            'll_property_size' => __( 'Property Size', 'll-service-scheduler' ),
            'll_city'          => __( 'City', 'll-service-scheduler' ),
            'll_address'       => __( 'Address', 'll-service-scheduler' ),
            'll_date'          => __( 'Booking Date', 'll-service-scheduler' ),
            'll_time'          => __( 'Time Slot', 'll-service-scheduler' ),
            'll_services'      => __( 'Services', 'll-service-scheduler' ),
            'll_notes'         => __( 'Notes', 'll-service-scheduler' ),
        );

        return $map[ $meta->key ] ?? $display_key;
    }

    /**
     * Hide internal booking keys from customer-facing order views.
     */
    public function hide_internal_order_meta( $hidden ) {
        return array_merge( (array) $hidden, array(
            'll_service_ids',
            'll_start',
            'll_end',
            'll_price',
            'll_booking_id',
            'll_jet_apt_id',
        ) );
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
