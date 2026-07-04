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

define( 'LL_SCHED_VER',  '2.2.2' );
define( 'LL_SCHED_DIR',  plugin_dir_path( __FILE__ ) );
define( 'LL_SCHED_URL',  plugin_dir_url( __FILE__ ) );
define( 'LL_SCHED_FILE', __FILE__ );

/* ─────────────────────────────────────────────
   Load all includes after plugins are ready
───────────────────────────────────────────── */
add_action( 'plugins_loaded', 'll_sched_boot', 20 );
add_action( 'init', 'll_sched_repair_orphan_bookings', 20 );

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
   Helper: find booking record from order line item
───────────────────────────────────────────── */
function ll_sched_find_booking_for_order_item( $item ) {
    global $wpdb;
    $table = $wpdb->prefix . 'll_sched_bookings';

    $date        = $item->get_meta( 'll_date' );
    $service_ids = $item->get_meta( 'll_service_ids' );
    $start       = $item->get_meta( 'll_start' );

    if ( ! $date ) {
        return 0;
    }

    $where  = array( 'booking_date = %s' );
    $params = array( $date );

    if ( $service_ids ) {
        $where[]  = 'service_ids = %s';
        $params[] = $service_ids;
    }
    if ( $start ) {
        $where[]  = 'start_time = %s';
        $params[] = $start;
    }

    $sql = "SELECT id FROM `{$table}` WHERE " . implode( ' AND ', $where ) . ' ORDER BY (order_id = 0) DESC, id DESC LIMIT 1';

    return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore
}

/**
 * One-time repair: link existing bookings to WooCommerce orders and sync status.
 */
function ll_sched_repair_orphan_bookings() {
    if ( get_option( 'll_sched_repaired_booking_links' ) === LL_SCHED_VER ) {
        return;
    }

    if ( ! function_exists( 'wc_get_orders' ) ) {
        return;
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'll_sched_bookings';
    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        return;
    }

    $orphans = $wpdb->get_results( "SELECT * FROM `{$table}` WHERE order_id = 0 OR order_id IS NULL" ); // phpcs:ignore

    if ( empty( $orphans ) ) {
        update_option( 'll_sched_repaired_booking_links', LL_SCHED_VER );
        return;
    }

    $orders = wc_get_orders( array(
        'limit'   => 200,
        'orderby' => 'date',
        'order'   => 'DESC',
        'status'  => array( 'processing', 'completed', 'on-hold', 'pending' ),
    ) );

    if ( empty( $orders ) || ! is_iterable( $orders ) ) {
        update_option( 'll_sched_repaired_booking_links', LL_SCHED_VER );
        return;
    }

    foreach ( $orphans as $booking ) {
        foreach ( $orders as $order ) {
            if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
                continue;
            }

            foreach ( $order->get_items() as $item ) {
                if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
                    continue;
                }

                $item_date    = $item->get_meta( 'll_date' );
                $item_service = $item->get_meta( 'll_service_ids' );

                if ( ! $item_date ) {
                    continue;
                }

                $match = ( $item_date === $booking->booking_date );
                if ( $item_service && $booking->service_ids ) {
                    $match = $match && ( $item_service === $booking->service_ids );
                }

                if ( ! $match ) {
                    continue;
                }

                $order_id = $order->get_id();
                $status   = 'pending';
                if ( $order->has_status( 'processing' ) || $order->has_status( 'completed' ) ) {
                    $status = 'paid';
                } elseif ( $order->has_status( 'cancelled' ) ) {
                    $status = 'cancelled';
                } elseif ( $order->has_status( 'refunded' ) ) {
                    $status = 'refunded';
                }

                $wpdb->update(
                    $table,
                    array(
                        'order_id'       => $order_id,
                        'status'         => $status,
                        'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                        'customer_email' => $order->get_billing_email(),
                        'customer_phone' => $order->get_billing_phone(),
                    ),
                    array( 'id' => $booking->id )
                );

                update_post_meta( $order_id, '_ll_booking_id', $booking->id );
                break 2;
            }
        }
    }

    update_option( 'll_sched_repaired_booking_links', LL_SCHED_VER );
}

/* ─────────────────────────────────────────────
   Helpers: per-service availability & filters
───────────────────────────────────────────── */

/**
 * Available weekdays (0=Sun … 6=Sat) for a service with custom schedule.
 * Migrates legacy _ll_svc_blocked_days when needed.
 */
function ll_sched_get_service_available_days( $post_id ) {
    $available = get_post_meta( $post_id, '_ll_svc_available_days', true );
    if ( is_array( $available ) ) {
        return array_map( 'intval', $available );
    }

    $blocked = array_map( 'intval', (array) get_post_meta( $post_id, '_ll_svc_blocked_days', true ) );
    if ( empty( $blocked ) ) {
        return range( 0, 6 );
    }

    return array_values( array_diff( range( 0, 6 ), $blocked ) );
}

/**
 * Blocked weekdays for one service (calendar context).
 */
function ll_sched_get_service_blocked_days( $post_id, $global_blocked = null ) {
    if ( null === $global_blocked ) {
        $global_blocked = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );
    }

    $use_custom = get_post_meta( $post_id, '_ll_svc_use_custom', true ) === '1';
    if ( ! $use_custom ) {
        return $global_blocked;
    }

    $available   = ll_sched_get_service_available_days( $post_id );
    $svc_blocked = array_values( array_diff( range( 0, 6 ), $available ) );
    $mode        = get_post_meta( $post_id, '_ll_svc_schedule_mode', true ) ?: 'combine';

    if ( $mode === 'replace' ) {
        return $svc_blocked;
    }

    return array_values( array_unique( array_merge( $global_blocked, $svc_blocked ) ) );
}

/**
 * Whether a service allows booking on a given weekday.
 */
function ll_sched_service_allows_weekday( $post_id, $weekday, $global_blocked = null ) {
    $blocked = ll_sched_get_service_blocked_days( $post_id, $global_blocked );
    return ! in_array( (int) $weekday, $blocked, true );
}

/**
 * Case-insensitive partial match (either string contains the other).
 */
function ll_sched_filter_text_matches( $user_text, $allowed_text ) {
    $user    = strtolower( trim( (string) $user_text ) );
    $allowed = strtolower( trim( (string) $allowed_text ) );
    if ( $user === '' || $allowed === '' ) {
        return false;
    }
    return strpos( $allowed, $user ) !== false || strpos( $user, $allowed ) !== false;
}

/**
 * Whether user input matches any value in an allowed list (partial or exact).
 */
function ll_sched_list_matches_filter( $user_value, $allowed_list ) {
    if ( trim( (string) $user_value ) === '' || empty( $allowed_list ) ) {
        return true;
    }
    foreach ( $allowed_list as $item ) {
        if ( ll_sched_filter_text_matches( $user_value, $item ) ) {
            return true;
        }
    }
    return false;
}

/**
 * Find a city key in a city→prices map (case-insensitive exact match).
 *
 * @param string              $city User-selected city.
 * @param array<string,mixed> $map  Keys are city names.
 * @return string|null Matched key or null.
 */
function ll_sched_find_city_price_key( $city, $map ) {
    $city = trim( (string) $city );
    if ( $city === '' || empty( $map ) || ! is_array( $map ) ) {
        return null;
    }

    if ( array_key_exists( $city, $map ) ) {
        return $city;
    }

    $city_lower = strtolower( $city );
    foreach ( $map as $key => $value ) {
        if ( strtolower( trim( (string) $key ) ) === $city_lower ) {
            return (string) $key;
        }
    }

    return null;
}

/**
 * Resolve the booking price for one service.
 *
 * Priority: city+size override → size override → base price.
 *
 * @param int    $post_id       Service post ID.
 * @param string $property_size Selected property size label.
 * @param string $city          Selected city.
 * @return float
 */
function ll_sched_get_service_price( $post_id, $property_size = '', $city = '' ) {
    $base_price = floatval( get_post_meta( $post_id, 'price', true ) );
    $price      = $base_price;

    $size_prices      = (array) get_post_meta( $post_id, '_ll_svc_size_prices', true );
    $city_size_prices = (array) get_post_meta( $post_id, '_ll_svc_city_size_prices', true );

    if ( $city !== '' && $property_size !== '' && ! empty( $city_size_prices ) ) {
        $city_key = ll_sched_find_city_price_key( $city, $city_size_prices );
        if ( null !== $city_key ) {
            $city_sizes = (array) ( $city_size_prices[ $city_key ] ?? array() );
            if ( array_key_exists( $property_size, $city_sizes ) ) {
                $city_size_price = floatval( $city_sizes[ $property_size ] );
                if ( $city_size_price > 0 ) {
                    return $city_size_price;
                }
            }
        }
    }

    if ( $property_size !== '' && ! empty( $size_prices ) && array_key_exists( $property_size, $size_prices ) ) {
        $size_price = floatval( $size_prices[ $property_size ] );
        if ( $size_price > 0 ) {
            $price = $size_price;
        }
    }

    return $price;
}

/**
 * Lowest display price for service cards ("From: $X").
 *
 * @param int    $post_id       Service post ID.
 * @param string $property_size Optional selected size.
 * @param string $city          Optional selected city.
 * @return float
 */
function ll_sched_get_service_display_price( $post_id, $property_size = '', $city = '' ) {
    if ( $property_size !== '' && $city !== '' ) {
        return ll_sched_get_service_price( $post_id, $property_size, $city );
    }

    if ( $property_size !== '' ) {
        return ll_sched_get_service_price( $post_id, $property_size, '' );
    }

    $base_price       = floatval( get_post_meta( $post_id, 'price', true ) );
    $size_prices      = (array) get_post_meta( $post_id, '_ll_svc_size_prices', true );
    $city_size_prices = (array) get_post_meta( $post_id, '_ll_svc_city_size_prices', true );
    $candidates       = array();

    if ( $city !== '' && ! empty( $city_size_prices ) ) {
        $city_key = ll_sched_find_city_price_key( $city, $city_size_prices );
        if ( null !== $city_key ) {
            foreach ( (array) ( $city_size_prices[ $city_key ] ?? array() ) as $amount ) {
                $num = floatval( $amount );
                if ( $num > 0 ) {
                    $candidates[] = $num;
                }
            }
        }
    }

    if ( empty( $candidates ) ) {
        foreach ( $city_size_prices as $city_sizes ) {
            foreach ( (array) $city_sizes as $amount ) {
                $num = floatval( $amount );
                if ( $num > 0 ) {
                    $candidates[] = $num;
                }
            }
        }
    }

    foreach ( $size_prices as $amount ) {
        $num = floatval( $amount );
        if ( $num > 0 ) {
            $candidates[] = $num;
        }
    }

    if ( ! empty( $candidates ) ) {
        return min( $candidates );
    }

    return $base_price;
}

/**
 * Service card media (image, GIF, or short video).
 *
 * Uses custom media ID when set; otherwise falls back to the Featured Image.
 *
 * @param int $post_id Service post ID.
 * @return array{id:int,url:string,mime:string,type:string}|null
 */
function ll_sched_get_service_card_media( $post_id ) {
    $media_id = (int) get_post_meta( $post_id, '_ll_svc_card_media_id', true );
    if ( ! $media_id ) {
        $media_id = (int) get_post_thumbnail_id( $post_id );
    }
    if ( ! $media_id ) {
        return null;
    }

    $mime = (string) get_post_mime_type( $media_id );
    $url  = wp_get_attachment_url( $media_id );
    if ( ! $url ) {
        return null;
    }

    $type = 'image';
    if ( $mime !== '' && strpos( $mime, 'video/' ) === 0 ) {
        $type = 'video';
    } elseif ( $mime === 'image/gif' ) {
        $type = 'gif';
    }

    return array(
        'id'   => $media_id,
        'url'  => $url,
        'mime' => $mime,
        'type' => $type,
    );
}

/**
 * Output service card media markup (static image, GIF, or looping video).
 *
 * @param int    $post_id Service post ID.
 * @param string $title   Accessible label / alt text.
 */
function ll_sched_render_service_card_media( $post_id, $title = '' ) {
    $media = ll_sched_get_service_card_media( $post_id );
    $title = $title !== '' ? $title : get_the_title( $post_id );

    if ( ! $media ) {
        echo '<div class="ll-no-img"></div>';
        return;
    }

    if ( $media['type'] === 'video' ) {
        printf(
            '<span class="ll-svc-media-frame"><video class="ll-svc-media" autoplay muted loop playsinline webkit-playsinline preload="auto" disablepictureinpicture aria-label="%1$s"><source src="%2$s" type="%3$s"></video></span>',
            esc_attr( $title ),
            esc_url( $media['url'] ),
            esc_attr( $media['mime'] )
        );
        return;
    }

    printf(
        '<img class="ll-svc-media" src="%1$s" alt="%2$s" loading="lazy" decoding="async"%3$s>',
        esc_url( $media['url'] ),
        esc_attr( $title ),
        $media['type'] === 'gif' ? ' data-ll-media="gif"' : ''
    );
}

/**
 * Whether a service matches property size / city / address filters.
 */
function ll_sched_service_matches_filters( $post_id, $property_size, $city, $address = '' ) {
    $sizes     = array_filter( (array) get_post_meta( $post_id, '_ll_svc_property_sizes', true ) );
    $cities    = array_filter( (array) get_post_meta( $post_id, '_ll_svc_cities', true ) );
    $addresses = array_filter( (array) get_post_meta( $post_id, '_ll_svc_addresses', true ) );

    if ( ! empty( $sizes ) && $property_size !== '' && ! in_array( $property_size, $sizes, true ) ) {
        return false;
    }

    if ( ! empty( $cities ) && $city !== '' ) {
        $city_lower = strtolower( trim( $city ) );
        $match      = false;
        foreach ( $cities as $c ) {
            if ( strtolower( trim( $c ) ) === $city_lower ) {
                $match = true;
                break;
            }
        }
        if ( ! $match ) {
            return false;
        }
    }

    if ( ! empty( $addresses ) && $address !== '' && ! ll_sched_list_matches_filter( $address, $addresses ) ) {
        return false;
    }

    return true;
}

/**
 * Whether a service allows a specific date (weekday + days off).
 */
function ll_sched_service_allows_date( $post_id, $date, $global_blocked = null ) {
    if ( null === $global_blocked ) {
        $global_blocked = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );
    }

    $date_obj = DateTime::createFromFormat( 'Y-m-d', $date );
    if ( ! $date_obj ) {
        return false;
    }

    $weekday = (int) $date_obj->format( 'w' );
    if ( ! ll_sched_service_allows_weekday( $post_id, $weekday, $global_blocked ) ) {
        return false;
    }

    $use_custom = get_post_meta( $post_id, '_ll_svc_use_custom', true ) === '1';
    if ( $use_custom ) {
        $days_off = (array) get_post_meta( $post_id, '_ll_svc_days_off', true );
        foreach ( $days_off as $off ) {
            $off_start = $off['start'] ?? '';
            $off_end   = $off['end'] ?? $off_start;
            if ( $off_start && $date >= $off_start && $date <= $off_end ) {
                return false;
            }
        }
    }

    return true;
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

/**
 * Formatted date/time when the order was placed (WC order date, or booking created_at).
 */
function ll_sched_get_booking_ordered_at( $booking ) {
    $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

    if ( ! empty( $booking->order_id ) && function_exists( 'wc_get_order' ) ) {
        $order = wc_get_order( (int) $booking->order_id );
        if ( $order ) {
            $created = $order->get_date_created();
            if ( $created ) {
                return $created->date_i18n( $format );
            }
        }
    }

    if ( ! empty( $booking->created_at ) ) {
        return mysql2date( $format, $booking->created_at );
    }

    return '';
}
