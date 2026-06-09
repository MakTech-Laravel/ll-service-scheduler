<?php
defined( 'ABSPATH' ) || exit;

class LL_Sched_Ajax {

    public function __construct() {
        // Booking AJAX: works for both logged-in and guest users
        add_action( 'wp_ajax_ll_sched_book',        array( $this, 'handle_booking' ) );
        add_action( 'wp_ajax_nopriv_ll_sched_book', array( $this, 'handle_booking' ) );

        // After WooCommerce payment: create Jet Appointment record
        add_action( 'woocommerce_payment_complete',        array( $this, 'on_payment_complete' ) );
        add_action( 'woocommerce_order_status_processing', array( $this, 'on_payment_complete' ) );
        add_action( 'woocommerce_order_status_completed',  array( $this, 'on_payment_complete' ) );
    }

    /* ═══════════════════════════════════════════
       HANDLE BOOKING SUBMISSION
    ═══════════════════════════════════════════ */
    public function handle_booking() {
        // 1. Security check
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'll_sched_booking' ) ) {
            wp_send_json_error( 'Security check failed. Please refresh the page and try again.' );
        }

        // 2. Sanitise inputs
        $raw_services  = isset( $_POST['services'] )      ? (array) $_POST['services']                          : array();
        $date          = isset( $_POST['date'] )          ? sanitize_text_field( $_POST['date'] )                : '';
        $time_raw      = isset( $_POST['time'] )          ? sanitize_text_field( $_POST['time'] )                : '';
        $property_size = isset( $_POST['property_size'] ) ? sanitize_text_field( $_POST['property_size'] )      : '';
        $city          = isset( $_POST['city'] )          ? sanitize_text_field( $_POST['city'] )                : '';
        $address       = isset( $_POST['address'] )       ? sanitize_text_field( $_POST['address'] )            : '';
        $notes         = isset( $_POST['notes'] )         ? sanitize_textarea_field( $_POST['notes'] )          : '';

        // 3. Validate required fields
        if ( empty( $raw_services ) ) {
            wp_send_json_error( 'Please select at least one service.' );
        }
        if ( empty( $date ) ) {
            wp_send_json_error( 'Please select a booking date.' );
        }
        if ( empty( $time_raw ) ) {
            wp_send_json_error( 'Please select a time slot.' );
        }

        // 4. Validate date format (Y-m-d)
        $date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
        if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $date ) {
            wp_send_json_error( 'Invalid date format.' );
        }

        // 5. Resolve service IDs → titles + prices
        $valid_ids    = array();
        $titles       = array();
        $total_price  = 0.0;

        foreach ( $raw_services as $raw_id ) {
            $sid     = absint( $raw_id );
            $post    = get_post( $sid );
            if ( ! $post || $post->post_type !== 'services' ) {
                continue;
            }
            $price         = floatval( get_post_meta( $sid, 'price', true ) );
            $valid_ids[]   = $sid;
            $titles[]      = $post->post_title;
            $total_price  += $price;
        }

        if ( empty( $valid_ids ) ) {
            wp_send_json_error( 'The selected services could not be found.' );
        }

        // 6. Parse time slot (format: "start|end|label")
        $tp    = explode( '|', $time_raw, 3 );
        $start = isset( $tp[0] ) ? $tp[0] : '';
        $end   = isset( $tp[1] ) ? $tp[1] : '';
        $label = isset( $tp[2] ) ? $tp[2] : $time_raw;

        // 7. WooCommerce availability check
        if ( ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( 'WooCommerce is not active. Please contact the site administrator.' );
        }

        // 8. Get / create the virtual booking product
        $product_id = ll_sched_ensure_woo_product();
        if ( ! $product_id ) {
            wp_send_json_error( 'Booking product is not set up. Please contact the site administrator.' );
        }

        // 9. Ensure WooCommerce cart/session is ready (important in AJAX context)
        $this->ensure_wc_cart();

        // 10. Clear existing cart and add booking
        WC()->cart->empty_cart();

        $cart_item_key = WC()->cart->add_to_cart(
            $product_id,
            1,   // quantity
            0,   // variation id
            array(), // variations
            array(   // extra cart item data (persisted in session)
                'll_price'       => $total_price,
                'll_service_ids' => implode( ',', $valid_ids ),
                'll_services'    => implode( ', ', $titles ),
                'll_date'        => $date,
                'll_time'        => $label,
                'll_start'       => $start,
                'll_end'         => $end,
                'll_property_size' => $property_size,
                'll_city'        => $city,
                'll_address'     => $address,
                'll_notes'       => $notes,
            )
        );

        if ( ! $cart_item_key ) {
            wp_send_json_error( 'Could not add booking to cart. Please try again.' );
        }

        // 11. Return checkout URL for JS redirect
        wp_send_json_success( array(
            'redirect' => wc_get_checkout_url(),
            'message'  => 'Redirecting to checkout...',
        ) );
    }

    /* ═══════════════════════════════════════════
       AFTER PAYMENT: Create Jet Appointment
    ═══════════════════════════════════════════ */
    public function on_payment_complete( $order_id ) {
        // Prevent duplicate appointment creation
        if ( get_post_meta( $order_id, '_ll_apt_created', true ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            $booking_date = $item->get_meta( 'll_date' );
            if ( empty( $booking_date ) ) {
                continue; // Not a LL booking item
            }

            // Gather booking details from order line item meta
            $service_ids = array_filter( array_map( 'intval', explode( ',', $item->get_meta( 'll_service_ids' ) ) ) );
            $start_time  = $item->get_meta( 'll_start' );
            $end_time    = $item->get_meta( 'll_end' );
            $time_label  = $item->get_meta( 'll_time' );
            $services    = $item->get_meta( 'll_services' );
            $prop_size   = $item->get_meta( 'll_property_size' );
            $city        = $item->get_meta( 'll_city' );
            $address     = $item->get_meta( 'll_address' );
            $notes       = $item->get_meta( 'll_notes' );

            // Customer info from WooCommerce billing
            $first      = $order->get_billing_first_name();
            $last       = $order->get_billing_last_name();
            $cust_name  = trim( $first . ' ' . $last );
            $cust_email = $order->get_billing_email();
            $cust_phone = $order->get_billing_phone();
            $user_id    = $order->get_customer_id();

            // Insert into Jet Appointments table for primary service
            $primary_svc = ! empty( $service_ids ) ? reset( $service_ids ) : 0;

            $result = $this->insert_jet_appointment( array(
                'service_id'  => $primary_svc,
                'date'        => $booking_date,
                'start'       => $start_time,
                'end'         => $end_time,
                'user_id'     => $user_id ? $user_id : 0,
                'user_name'   => $cust_name,
                'user_email'  => $cust_email,
                'user_phone'  => $cust_phone,
                'order_id'    => $order_id,
                'status'      => 'paid',
            ) );

            // Add a human-readable note to the order regardless of Jet result
            $order->add_order_note( sprintf(
                'LL Scheduler — Appointment Created%s | Services: %s | Date: %s | Time: %s (%s – %s) | Property: %s | City: %s | Address: %s%s',
                $result ? '' : ' (Jet APB insert failed — see order meta)',
                $services,
                $booking_date,
                $time_label,
                $start_time,
                $end_time,
                $prop_size,
                $city,
                $address,
                $notes ? ' | Notes: ' . $notes : ''
            ) );

            // Mark as done to avoid duplicates
            update_post_meta( $order_id, '_ll_apt_created', 1 );
            update_post_meta( $order_id, '_ll_apt_jet_result', $result ? 'ok' : 'failed' );

            break; // Only one booking item per cart
        }
    }

    /* ═══════════════════════════════════════════
       INSERT INTO JET APB APPOINTMENTS TABLE
       Uses dynamic column detection for compatibility
       across different versions of Jet APB.
    ═══════════════════════════════════════════ */
    private function insert_jet_appointment( $data ) {
        global $wpdb;

        // ─ Try Jet APB's own API first ─
        if ( class_exists( '\Jet_APB\Appointments_DB' ) ) {
            try {
                $db = \Jet_APB\Appointments_DB::instance();
                foreach ( array( 'insert_appointment', 'insert', 'create' ) as $method ) {
                    if ( method_exists( $db, $method ) ) {
                        return call_user_func( array( $db, $method ), $data );
                    }
                }
            } catch ( \Exception $e ) {
                // Fall through to direct insert
            }
        }

        // ─ Direct DB insert with column detection ─
        $table = $wpdb->prefix . 'jet_apb_appointments';

        // Check table exists
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return false; // Jet APB table not found
        }

        // Get actual column names from DB
        $columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 ); // phpcs:ignore

        $row    = array();
        $slot   = $data['start'] . '-' . $data['end'];

        // Map our data to possible column names used by different Jet APB versions
        $candidates = array(
            'service_id'  => $data['service_id'],
            'provider_id' => 0,
            'date'        => $data['date'],
            'slot'        => $slot,
            'start_time'  => $data['start'],
            'end_time'    => $data['end'],
            'user_id'     => $data['user_id'],
            'user_name'   => $data['user_name'],
            'user_email'  => $data['user_email'],
            'user_phone'  => isset( $data['user_phone'] ) ? $data['user_phone'] : '',
            'phone'       => isset( $data['user_phone'] ) ? $data['user_phone'] : '',
            'order_id'    => $data['order_id'],
            'status'      => $data['status'],
        );

        foreach ( $candidates as $col => $val ) {
            if ( in_array( $col, $columns, true ) ) {
                $row[ $col ] = $val;
            }
        }

        if ( empty( $row ) ) {
            return false;
        }

        $inserted = $wpdb->insert( $table, $row ); // phpcs:ignore
        return $inserted !== false ? $wpdb->insert_id : false;
    }

    /* ─────────────────────────────────────────────
       Ensure WC cart/session is initialised in AJAX
    ───────────────────────────────────────────── */
    private function ensure_wc_cart() {
        // wc_load_cart() was added in WC 3.6.4 — site runs 10.x so always available
        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
            return;
        }

        // Fallback for very old WC (shouldn't hit this on 10.x)
        if ( is_null( WC()->session ) ) {
            $session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
            WC()->session  = new $session_class();
            WC()->session->init();
        }
        if ( is_null( WC()->customer ) ) {
            WC()->customer = new WC_Customer( get_current_user_id(), true );
        }
        if ( is_null( WC()->cart ) ) {
            WC()->cart = new WC_Cart();
        }
    }
}
