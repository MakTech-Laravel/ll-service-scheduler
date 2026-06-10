<?php
defined( 'ABSPATH' ) || exit;

/**
 * KEY FIX for appointment status:
 *
 * OLD (broken) flow:
 *   Add to Cart → Checkout → Payment → CREATE appointment (status: "paid")
 *   Problem: appointment never appears as "Pending Payment" first
 *
 * NEW (correct) flow:
 *   Add to Cart → CREATE appointment (status: "pending_payment") → store ID in cart
 *   → Checkout → Payment Complete → UPDATE appointment status to "paid"/"processing"
 *   This matches how Jet APB's own JetFormBuilder integration works.
 */
class LL_Sched_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_ll_sched_book',        array( $this, 'handle_booking' ) );
        add_action( 'wp_ajax_nopriv_ll_sched_book', array( $this, 'handle_booking' ) );

        if ( class_exists( 'WooCommerce' ) ) {
            // WooCommerce hooks → link booking to order and sync payment status
            add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout_order_processed' ), 20, 1 );
            add_action( 'woocommerce_payment_complete',         array( $this, 'on_order_paid' ), 20 );
            add_action( 'woocommerce_order_status_processing',  array( $this, 'on_order_paid' ), 20 );
            add_action( 'woocommerce_order_status_completed',   array( $this, 'on_order_paid' ), 20 );
            add_action( 'woocommerce_order_status_cancelled',   array( $this, 'on_order_cancelled' ), 20 );
            add_action( 'woocommerce_order_status_refunded',    array( $this, 'on_order_refunded' ), 20 );
        }
    }

    /* ═══════════════════════════════════════════════
       STEP 1 — Handle "Add to Cart" AJAX call
       Creates Jet APB appointment with pending_payment
       BEFORE going to checkout.
    ═══════════════════════════════════════════════ */
    public function handle_booking() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'll_sched_booking' ) ) {
            wp_send_json_error( 'Security check failed. Please refresh and try again.' );
        }

        /* ── 1. Sanitise ── */
        $raw_services  = isset( $_POST['services'] )      ? (array) $_POST['services']                     : array();
        $date          = sanitize_text_field( $_POST['date']          ?? '' );
        $time_raw      = sanitize_text_field( $_POST['time']          ?? '' );
        $property_size = sanitize_text_field( $_POST['property_size'] ?? '' );
        $city          = sanitize_text_field( $_POST['city']          ?? '' );
        $address       = sanitize_text_field( $_POST['address']       ?? '' );
        $notes         = sanitize_textarea_field( $_POST['notes']     ?? '' );

        /* ── 2. Validate required ── */
        if ( empty( $raw_services ) ) wp_send_json_error( 'Please select at least one service.' );
        if ( empty( $date ) )         wp_send_json_error( 'Please select a booking date.' );
        if ( empty( $time_raw ) )     wp_send_json_error( 'Please select a time slot.' );

        /* ── 3. Validate date ── */
        $date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
        if ( ! $date_obj || $date_obj->format( 'Y-m-d' ) !== $date ) {
            wp_send_json_error( 'Invalid date.' );
        }

        $today = new DateTime( 'today', wp_timezone() );
        if ( $date_obj < $today ) {
            wp_send_json_error( 'Past dates cannot be booked.' );
        }

        $global_blocked = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );

        /* ── 4. Resolve services ── */
        $valid_ids   = array();
        $titles      = array();
        $total_price = 0.0;

        foreach ( $raw_services as $raw_id ) {
            $sid  = absint( $raw_id );
            $post = get_post( $sid );
            if ( ! $post || $post->post_type !== 'services' ) continue;

            if ( ! ll_sched_service_matches_filters( $sid, $property_size, $city, $address ) ) {
                wp_send_json_error( '"' . $post->post_title . '" is not available for the selected filters (property size, city, or address).' );
            }

            if ( ! ll_sched_service_allows_date( $sid, $date, $global_blocked ) ) {
                wp_send_json_error( '"' . $post->post_title . '" is not available on the selected date.' );
            }

            $price        = floatval( get_post_meta( $sid, 'price', true ) );
            $valid_ids[]  = $sid;
            $titles[]     = $post->post_title;
            $total_price += $price;
        }

        if ( empty( $valid_ids ) ) wp_send_json_error( 'No valid services found.' );

        /* ── 5. Parse time slot ── */
        $tp    = explode( '|', $time_raw, 3 );
        $start = $tp[0] ?? '';
        $end   = $tp[1] ?? '';
        $label = $tp[2] ?? $time_raw;

        /* ── 6. WooCommerce check ── */
        if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error( 'WooCommerce not active.' );
        $product_id = ll_sched_ensure_woo_product();
        if ( ! $product_id ) wp_send_json_error( 'Booking product missing. Please contact admin.' );

        /* ── 7. Create Jet APB appointment NOW (pending_payment) ── */
        $jet_apt_id = $this->create_jet_appointment_pending( array(
            'service_id' => $valid_ids[0],
            'date'       => $date,
            'start'      => $start,
            'end'        => $end,
            'user_id'    => get_current_user_id(),
            'status'     => 'pending_payment',
        ) );

        /* ── 8. Insert into our custom bookings table ── */
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'll_sched_bookings', array(
            'order_id'      => 0,
            'jet_apt_id'    => $jet_apt_id ?: 0,
            'service_ids'   => implode( ',', $valid_ids ),
            'service_names' => implode( ', ', $titles ),
            'booking_date'  => $date,
            'start_time'    => $start,
            'end_time'      => $end,
            'time_label'    => $label,
            'property_size' => $property_size,
            'city'          => $city,
            'address'       => $address,
            'notes'         => $notes,
            'user_id'       => get_current_user_id(),
            'total_price'   => $total_price,
            'status'        => 'pending',
        ) );
        $ll_booking_id = $wpdb->insert_id;

        /* ── 9. Add to WooCommerce cart ── */
        $this->ensure_wc_cart();
        WC()->cart->empty_cart();

        $cart_key = WC()->cart->add_to_cart(
            $product_id, 1, 0, array(), array(
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
                'll_jet_apt_id'  => $jet_apt_id ?: 0,   // ← KEY: carry Jet APB ID to order
                'll_booking_id'  => $ll_booking_id,      // ← carry LL booking ID to order
            )
        );

        if ( ! $cart_key ) wp_send_json_error( 'Could not add to cart. Please try again.' );

        wp_send_json_success( array( 'redirect' => wc_get_checkout_url() ) );
    }

    /* ═══════════════════════════════════════════════
       STEP 2 — After checkout / payment: link order & sync status
    ═══════════════════════════════════════════════ */

    /** Checkout completed — link order ID and customer info (status stays pending until paid). */
    public function on_checkout_order_processed( $order_id ) {
        $this->sync_booking_from_order( $order_id, null, null, false );
    }

    /** Payment complete / processing / completed */
    public function on_order_paid( $order_id ) {
        $this->sync_booking_from_order( $order_id, 'paid', 'paid', true );
    }

    /** Order cancelled */
    public function on_order_cancelled( $order_id ) {
        $this->sync_booking_from_order( $order_id, 'cancelled', 'cancelled', true );
    }

    /** Order refunded */
    public function on_order_refunded( $order_id ) {
        $this->sync_booking_from_order( $order_id, 'refunded', 'refunded', true );
    }

    /**
     * Resolve booking ID from order line item or matching pending record.
     */
    private function resolve_booking_id( $item, $order_id = 0 ) {
        $booking_id = (int) $item->get_meta( 'll_booking_id' );
        if ( $booking_id ) {
            return $booking_id;
        }

        if ( $order_id ) {
            $from_order = (int) get_post_meta( $order_id, '_ll_booking_id', true );
            if ( $from_order ) {
                return $from_order;
            }
        }

        return ll_sched_find_booking_for_order_item( $item );
    }

    /**
     * Sync LL booking + Jet APB with WooCommerce order.
     *
     * @param int         $order_id
     * @param string|null $ll_status   Booking status (null = link only, keep current)
     * @param string|null $jet_status  Jet APB status
     * @param bool        $add_note    Add order note on status change
     */
    private function sync_booking_from_order( $order_id, $ll_status, $jet_status, $add_note ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'll_sched_bookings';

        foreach ( $order->get_items() as $item ) {
            if ( ! $item->get_meta( 'll_date' ) && ! $item->get_meta( 'll_booking_id' ) ) {
                continue;
            }

            $ll_booking_id = $this->resolve_booking_id( $item, $order_id );
            $jet_apt_id      = (int) $item->get_meta( 'll_jet_apt_id' );
            if ( ! $jet_apt_id ) {
                $jet_apt_id = (int) get_post_meta( $order_id, '_ll_jet_apt_id', true );
            }

            if ( ! $ll_booking_id ) {
                continue;
            }

            $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
            $email = $order->get_billing_email();
            $phone = $order->get_billing_phone();

            $update = array(
                'order_id'       => $order_id,
                'customer_name'  => $name,
                'customer_email' => $email,
                'customer_phone' => $phone,
            );

            if ( $jet_apt_id ) {
                $update['jet_apt_id'] = $jet_apt_id;
            }

            if ( $ll_status ) {
                $update['status'] = $ll_status;
            }

            $wpdb->update( $table, $update, array( 'id' => $ll_booking_id ) );

            if ( $ll_status && $jet_apt_id ) {
                $this->update_jet_appointment_status( $jet_apt_id, $jet_status ?: $ll_status );
                $this->update_jet_appointment_order_id( $jet_apt_id, $order_id );
            }

            if ( $add_note && $ll_status ) {
                $note_key = '_ll_sched_noted_' . $ll_status;
                if ( ! get_post_meta( $order_id, $note_key, true ) ) {
                    $order->add_order_note( sprintf(
                        'LL Scheduler: Booking #%d → %s | Services: %s | Date: %s | Time: %s',
                        $ll_booking_id,
                        strtoupper( $ll_status ),
                        $item->get_meta( 'll_services' ),
                        $item->get_meta( 'll_date' ),
                        $item->get_meta( 'll_time' )
                    ) );
                    update_post_meta( $order_id, $note_key, 1 );
                }
            }

            update_post_meta( $order_id, '_ll_booking_id', $ll_booking_id );
            break;
        }
    }

    /* ═══════════════════════════════════════════════
       Jet APB Integration helpers
    ═══════════════════════════════════════════════ */

    /**
     * Create a new Jet APB appointment with "pending_payment" status.
     * Returns the Jet APB appointment ID on success, 0 on failure.
     */
    private function create_jet_appointment_pending( $data ) {
        global $wpdb;

        // Try Jet APB API first
        if ( class_exists( '\Jet_APB\Appointments_DB' ) ) {
            try {
                $db = \Jet_APB\Appointments_DB::instance();
                foreach ( array( 'insert_appointment', 'insert', 'create' ) as $m ) {
                    if ( method_exists( $db, $m ) ) {
                        $result = call_user_func( array( $db, $m ), $data );
                        if ( $result ) return (int) $result;
                    }
                }
            } catch ( \Exception $e ) { /* fall through */ }
        }

        // Direct DB insert
        $table = $wpdb->prefix . 'jet_apb_appointments';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return 0;

        $columns    = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
        $slot       = $data['start'] . '-' . $data['end'];

        $candidates = array(
            'service_id'  => $data['service_id'],
            'provider_id' => 0,
            'date'        => $data['date'],
            'slot'        => $slot,
            'start_time'  => $data['start'],
            'end_time'    => $data['end'],
            'user_id'     => $data['user_id'],
            'user_name'   => '',
            'user_email'  => '',
            'order_id'    => 0,
            'status'      => 'pending_payment',
        );

        $row = array();
        foreach ( $candidates as $col => $val ) {
            if ( in_array( $col, $columns, true ) ) {
                $row[ $col ] = $val;
            }
        }

        if ( empty( $row ) ) return 0;

        $result = $wpdb->insert( $table, $row );
        return $result ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Update the status of an existing Jet APB appointment.
     */
    private function update_jet_appointment_status( $jet_apt_id, $status ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jet_apb_appointments';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return false;
        }

        return $wpdb->update(
            $table,
            array( 'status' => sanitize_text_field( $status ) ),
            array( 'ID'     => (int) $jet_apt_id )
        );
    }

    private function update_jet_appointment_order_id( $jet_apt_id, $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jet_apb_appointments';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return false;
        }

        $columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
        if ( ! in_array( 'order_id', $columns, true ) ) {
            return false;
        }

        return $wpdb->update(
            $table,
            array( 'order_id' => (int) $order_id ),
            array( 'ID'       => (int) $jet_apt_id )
        );
    }

    /* ─────────────────────────────────────────────
       Ensure WC cart is ready in AJAX context
    ───────────────────────────────────────────── */
    private function ensure_wc_cart() {
        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
            return;
        }
        if ( is_null( WC()->session ) ) {
            $cls = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
            WC()->session = new $cls();
            WC()->session->init();
        }
        if ( is_null( WC()->customer ) ) WC()->customer = new WC_Customer( get_current_user_id(), true );
        if ( is_null( WC()->cart ) )     WC()->cart = new WC_Cart();
    }
}
