<?php
/**
 * Plugin Name:       LL Service Scheduler
 * Plugin URI:        https://listinglenspro.com
 * Description:       Independent service booking system with its own dashboard, per-service scheduling, WooCommerce checkout, and Jet Appointments integration.
 * Version:           2.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ListingLens Dev
 * License:           GPL v2 or later
 * Text Domain:       ll-scheduler
 */

defined( 'ABSPATH' ) || exit;

define( 'LL_SCHED_VER',  '2.0.0' );
define( 'LL_SCHED_DIR',  plugin_dir_path( __FILE__ ) );
define( 'LL_SCHED_URL',  plugin_dir_url( __FILE__ ) );
define( 'LL_SCHED_FILE', __FILE__ );

/* ─────────────────────────────────────────────
   Load all includes after plugins are ready
───────────────────────────────────────────── */
add_action( 'plugins_loaded', 'll_sched_boot', 5 );

function ll_sched_boot() {
    ll_sched_migrate_options(); // one-time migration v1 → v2

    require_once LL_SCHED_DIR . 'includes/class-menu.php';
    require_once LL_SCHED_DIR . 'includes/class-settings.php';
    require_once LL_SCHED_DIR . 'includes/class-service-meta.php';
    require_once LL_SCHED_DIR . 'includes/class-bookings-table.php';
    require_once LL_SCHED_DIR . 'includes/class-frontend.php';
    require_once LL_SCHED_DIR . 'includes/class-ajax.php';

    new LL_Sched_Menu();
    new LL_Sched_Settings();
    new LL_Sched_Service_Meta();
    new LL_Sched_Frontend();
    new LL_Sched_Ajax();
}

/* ─────────────────────────────────────────────
   Activation hook
───────────────────────────────────────────── */
register_activation_hook( __FILE__, 'll_sched_activate' );

function ll_sched_activate() {
    ll_sched_create_db_table();
    ll_sched_seed_defaults();
    if ( class_exists( 'WooCommerce' ) ) {
        ll_sched_ensure_woo_product();
    }
}

/* ─────────────────────────────────────────────
   Custom bookings DB table
───────────────────────────────────────────── */
function ll_sched_create_db_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'll_sched_bookings';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
        jet_apt_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
        service_ids TEXT            NOT NULL DEFAULT '',
        service_names TEXT          NOT NULL DEFAULT '',
        booking_date  DATE          NULL,
        start_time  VARCHAR(10)     NOT NULL DEFAULT '',
        end_time    VARCHAR(10)     NOT NULL DEFAULT '',
        time_label  VARCHAR(120)    NOT NULL DEFAULT '',
        property_size VARCHAR(100)  NOT NULL DEFAULT '',
        city        VARCHAR(100)    NOT NULL DEFAULT '',
        address     TEXT            NOT NULL DEFAULT '',
        notes       TEXT            NOT NULL DEFAULT '',
        customer_name  VARCHAR(200) NOT NULL DEFAULT '',
        customer_email VARCHAR(200) NOT NULL DEFAULT '',
        customer_phone VARCHAR(60)  NOT NULL DEFAULT '',
        user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
        total_price DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
        status      VARCHAR(60)     NOT NULL DEFAULT 'pending',
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY order_id (order_id),
        KEY booking_date (booking_date),
        KEY status (status)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'll_sched_db_version', '2.0' );
}

/* ─────────────────────────────────────────────
   Seed default options on first activation
───────────────────────────────────────────── */
function ll_sched_seed_defaults() {
    if ( ! get_option( 'll_sched_property_sizes' ) ) {
        update_option( 'll_sched_property_sizes', array( '0-3000 Sq. Ft', '3001-6000 Sq. Ft', '6001-9000 Sq. Ft', '9001-12000 Sq. Ft' ) );
    }
    if ( ! get_option( 'll_sched_cities' ) ) {
        update_option( 'll_sched_cities', array( 'Montreal', 'Toronto', 'Vancouver' ) );
    }
    if ( ! get_option( 'll_sched_selection_mode' ) ) {
        update_option( 'll_sched_selection_mode', 'multiple' );
    }
    if ( get_option( 'll_sched_blocked_days' ) === false ) {
        update_option( 'll_sched_blocked_days', array( 0, 6 ) ); // Sun + Sat blocked by default
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
}

/* ─────────────────────────────────────────────
   One-time migration: v1 available_days → blocked_days
───────────────────────────────────────────── */
function ll_sched_migrate_options() {
    // v1 used 'll_sched_available_days'; convert once
    $legacy = get_option( 'll_sched_available_days', null );
    if ( $legacy !== null && get_option( 'll_sched_blocked_days', null ) === null ) {
        update_option( 'll_sched_blocked_days', array_map( 'intval', (array) $legacy ) );
    }
    if ( $legacy !== null ) {
        delete_option( 'll_sched_available_days' );
    }

    // Ensure DB table exists (handles plugin updates)
    if ( get_option( 'll_sched_db_version' ) !== '2.0' ) {
        ll_sched_create_db_table();
    }
}

/* ─────────────────────────────────────────────
   Helper: create/verify WooCommerce virtual product
───────────────────────────────────────────── */
function ll_sched_ensure_woo_product() {
    $id = (int) get_option( 'll_sched_woo_product_id', 0 );
    if ( $id && get_post_status( $id ) !== false ) {
        return $id;
    }
    if ( ! class_exists( 'WC_Product_Simple' ) ) {
        return 0;
    }
    $p = new WC_Product_Simple();
    $p->set_name( 'Service Booking' );
    $p->set_status( 'publish' );
    $p->set_virtual( true );
    $p->set_regular_price( '0' );
    $p->set_catalog_visibility( 'hidden' );
    $p->set_sold_individually( true );
    $new_id = $p->save();
    update_option( 'll_sched_woo_product_id', $new_id );
    return $new_id;
}

/* ─────────────────────────────────────────────
   Helper: get bookings from custom table
───────────────────────────────────────────── */
function ll_sched_get_bookings( $args = array() ) {
    global $wpdb;
    $table = $wpdb->prefix . 'll_sched_bookings';

    $defaults = array(
        'status'    => '',
        'limit'     => 20,
        'offset'    => 0,
        'orderby'   => 'created_at',
        'order'     => 'DESC',
        'search'    => '',
    );
    $args = wp_parse_args( $args, $defaults );

    $where = array( '1=1' );
    $values = array();

    if ( ! empty( $args['status'] ) ) {
        $where[] = 'status = %s';
        $values[] = $args['status'];
    }
    if ( ! empty( $args['search'] ) ) {
        $like = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        $where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR service_names LIKE %s)';
        $values[] = $like;
        $values[] = $like;
        $like;
        $values[] = $like;
    }

    $where_sql = implode( ' AND ', $where );
    $order_sql  = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] ) ?: 'created_at DESC';
    $limit_sql  = $wpdb->prepare( 'LIMIT %d OFFSET %d', (int) $args['limit'], (int) $args['offset'] );

    if ( ! empty( $values ) ) {
        $query = $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY {$order_sql} {$limit_sql}", $values ); // phpcs:ignore
    } else {
        $query = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY {$order_sql} {$limit_sql}"; // phpcs:ignore
    }

    return $wpdb->get_results( $query ); // phpcs:ignore
}

function ll_sched_count_bookings( $status = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'll_sched_bookings';
    if ( $status ) {
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", $status ) ); // phpcs:ignore
    }
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore
}

function ll_sched_update_booking_status( $id, $status ) {
    global $wpdb;
    $table = $wpdb->prefix . 'll_sched_bookings';
    return $wpdb->update( $table, array( 'status' => sanitize_text_field( $status ) ), array( 'id' => (int) $id ) );
}
