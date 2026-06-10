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

        // WooCommerce order status hooks → UPDATE appointment (not create)
        add_action( 'woocommerce_payment_complete',          array( $this, 'on_order_paid' ) );
        add_action( 'woocommerce_order_status_processing',   array( $this, 'on_order_paid' ) );
        add_action( 'woocommerce_order_status_completed',    array( $this, 'on_order_completed' ) );
        add_action( 'woocommerce_order_status_cancelled',    array( $this, 'on_order_cancelled' ) );
        add_action( 'woocommerce_order_status_refunded',     array( $this, 'on_order_refunded' ) );
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

        $blocked_days = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );
        if ( in_array( (int) $date_obj->format( 'w' ), $blocked_days, true ) ) {
            wp_send_json_error( 'The selected date is not available for booking.' );
        }

        /* ── 4. Resolve services ── */
        $valid_ids   = array();
        $titles      = array();
        $total_price = 0.0;

        foreach ( $raw_services as $raw_id ) {
            $sid  = absint( $raw_id );
            $post = get_post( $sid );
            if ( ! $post || $post->post_type !== 'services' ) continue;

            // Check per-service blocked days
            $use_custom = get_post_meta( $sid, '_ll_svc_use_custom', true ) === '1';
            if ( $use_custom ) {
                $svc_blocked = array_map( 'intval', (array) get_post_meta( $sid, '_ll_svc_blocked_days', true ) );
                if ( in_array( (int) $date_obj->format( 'w' ), $svc_blocked, true ) ) {
                    wp_send_json_error( '"' . $post->post_title . '" is not available on the selected date.' );
                }
                // Check days off for this service
                $days_off = (array) get_post_meta( $sid, '_ll_svc_days_off', true );
                foreach ( $days_off as $off ) {
                    $off_start = $off['start'] ?? '';
                    $off_end   = $off['end']   ?? $off_start;
                    if ( $off_start && $date >= $off_start && $date <= $off_end ) {
                        $lbl = ! empty( $off['label'] ) ? ' (' . $off['label'] . ')' : '';
                        wp_send_json_error( '"' . $post->post_title . '" is unavailable on the selected date' . $lbl . '.' );
                    }
                }
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
       STEP 2 — After payment: UPDATE status (not create)
    ═══════════════════════════════════════════════ */

    /** Payment complete / order processing */
    public function on_order_paid( $order_id ) {
        $this->update_booking_from_order( $order_id, 'paid', 'paid' );
    }

    /** Order completed */
    public function on_order_completed( $order_id ) {
        $this->update_booking_from_order( $order_id, 'confirmed', 'completed' );
    }

    /** Order cancelled */
    public function on_order_cancelled( $order_id ) {
        $this->update_booking_from_order( $order_id, 'cancelled', 'cancelled' );
    }

    /** Order refunded */
    public function on_order_refunded( $order_id ) {
        $this->update_booking_from_order( $order_id, 'refunded', 'refunded' );
    }

    /**
     * Update LL booking table AND Jet APB appointment status.
     * Also attaches the WC order ID to the booking record.
     *
     * @param int    $order_id   WooCommerce order ID
     * @param string $ll_status  Status for our custom bookings table
     * @param string $jet_status Status string for Jet APB table
     */
    private function update_booking_from_order( $order_id, $ll_status, $jet_status ) {
        // Prevent double-processing
        $done_key = '_ll_order_processed_' . $ll_status;
        if ( get_post_meta( $order_id, $done_key, true ) ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        foreach ( $order->get_items() as $item ) {
            $ll_booking_id = (int) $item->get_meta( 'll_booking_id' );
            $jet_apt_id    = (int) $item->get_meta( 'll_jet_apt_id' );

            if ( ! $ll_booking_id && ! $item->get_meta( 'll_date' ) ) continue; // not our item

            /* ── A. Update our custom bookings table ── */
            global $wpdb;
            if ( $ll_booking_id ) {
                $wpdb->update(
                    $wpdb->prefix . 'll_sched_bookings',
                    array( 'status' => $ll_status, 'order_id' => $order_id, 'jet_apt_id' => $jet_apt_id ),
                    array( 'id' => $ll_booking_id )
                );
            } else {
                // Fallback: find by order ID
                $wpdb->update(
                    $wpdb->prefix . 'll_sched_bookings',
                    array( 'status' => $ll_status, 'order_id' => $order_id ),
                    array( 'order_id' => 0, 'customer_email' => $order->get_billing_email() )
                );
            }

            /* ── B. Update Jet APB appointment ── */
            if ( $jet_apt_id ) {
                $this->update_jet_appointment_status( $jet_apt_id, $jet_status );
            } else {
                // Try to find by order_id in Jet APB table
                $jet_table = $wpdb->prefix . 'jet_apb_appointments';
                if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jet_table ) ) === $jet_table ) {
                    $found = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM `{$jet_table}` WHERE order_id = %d LIMIT 1", $order_id ) );
                    if ( $found ) {
                        $this->update_jet_appointment_status( (int) $found, $jet_status );
                    }
                }
            }

            /* ── C. Update customer info (available only after checkout) ── */
            if ( $ll_booking_id ) {
                $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                $email = $order->get_billing_email();
                $phone = $order->get_billing_phone();
                $wpdb->update(
                    $wpdb->prefix . 'll_sched_bookings',
                    array( 'customer_name' => $name, 'customer_email' => $email, 'customer_phone' => $phone ),
                    array( 'id' => $ll_booking_id )
                );
            }

            /* ── D. Order note ── */
            $svc_names = $item->get_meta( 'll_services' );
            $bdate     = $item->get_meta( 'll_date' );
            $btime     = $item->get_meta( 'll_time' );
            $order->add_order_note( sprintf(
                'LL Scheduler: Status → %s | Services: %s | Date: %s | Time: %s | Jet APB ID: %s',
                strtoupper( $ll_status ), $svc_names, $bdate, $btime,
                $jet_apt_id ?: 'N/A'
            ) );

            update_post_meta( $order_id, $done_key, 1 );
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
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return false;

        return $wpdb->update(
            $table,
            array( 'status' => sanitize_text_field( $status ) ),
            array( 'ID'     => (int) $jet_apt_id )
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
