<?php
defined( 'ABSPATH' ) || exit;

class LL_Sched_Menu {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'admin_body_class',      array( $this, 'admin_body_class' ) );
        add_action( 'wp_ajax_ll_sched_update_booking_status', array( $this, 'ajax_update_status' ) );
        add_action( 'wp_ajax_ll_sched_delete_booking',        array( $this, 'ajax_delete_booking' ) );
        add_action( 'init',                  array( $this, 'maybe_create_product' ) );
    }

    /* ─────────────────────────────────────────────
       Sidebar menu registration
    ───────────────────────────────────────────── */
    public function register_menus() {
        // Top-level menu
        add_menu_page(
            'LL Service Scheduler',
            'LL Scheduler',
            'manage_options',
            'll-scheduler',
            array( $this, 'page_dashboard' ),
            'dashicons-calendar-alt',
            26
        );

        // Dashboard (same as top-level)
        add_submenu_page(
            'll-scheduler',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'll-scheduler',
            array( $this, 'page_dashboard' )
        );

        // All Bookings
        add_submenu_page(
            'll-scheduler',
            'All Bookings',
            'All Bookings',
            'manage_options',
            'll-scheduler-bookings',
            array( $this, 'page_bookings' )
        );

        // Services (links to existing CPT)
        add_submenu_page(
            'll-scheduler',
            'Services',
            'Services',
            'manage_options',
            'edit.php?post_type=services',
            null
        );

        // Categories (links to existing taxonomy)
        add_submenu_page(
            'll-scheduler',
            'Categories',
            'Categories',
            'manage_options',
            'edit-tags.php?taxonomy=service-category&post_type=services',
            null
        );

        // Settings
        add_submenu_page(
            'll-scheduler',
            'Settings',
            'Settings',
            'manage_options',
            'll-scheduler-settings',
            array( $this, 'page_settings' )
        );
    }

    /* ─────────────────────────────────────────────
       Enqueue admin assets (only on our plugin pages)
    ───────────────────────────────────────────── */
    public function admin_body_class( $classes ) {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return $classes;
        }
        $screen = get_current_screen();
        if ( $screen && ! empty( $screen->id ) && strpos( $screen->id, 'll-scheduler' ) !== false ) {
            $classes .= ' ll-sched-admin-page';
        }
        return $classes;
    }

    public function enqueue_assets( $hook ) {
        $our_pages = array(
            'toplevel_page_ll-scheduler',
            'll-scheduler_page_ll-scheduler-bookings',
            'll-scheduler_page_ll-scheduler-settings',
        );
        if ( ! in_array( $hook, $our_pages, true ) ) return;

        wp_enqueue_style(
            'll-sched-admin',
            LL_SCHED_URL . 'assets/css/admin.css',
            array(),
            LL_SCHED_VER
        );
        wp_enqueue_script(
            'll-sched-admin',
            LL_SCHED_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            LL_SCHED_VER,
            true
        );
        wp_localize_script( 'll-sched-admin', 'llSchedAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'll_sched_admin' ),
        ) );
    }

    public function maybe_create_product() {
        if ( ! is_admin() || ! class_exists( 'WooCommerce' ) ) return;
        $id = (int) get_option( 'll_sched_woo_product_id', 0 );
        if ( ! $id || get_post_status( $id ) === false ) {
            ll_sched_ensure_woo_product();
        }
    }

    /* ─────────────────────────────────────────────
       PAGE: Dashboard
    ───────────────────────────────────────────── */
    public function page_dashboard() {
        global $wpdb;
        $table = $wpdb->prefix . 'll_sched_bookings';

        $total     = ll_sched_count_bookings();
        $pending   = ll_sched_count_bookings( 'pending' );
        $confirmed = ll_sched_count_bookings( 'paid' ) + ll_sched_count_bookings( 'confirmed' );
        $cancelled = ll_sched_count_bookings( 'cancelled' );

        // Revenue this month
        $revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(total_price) FROM `{$table}` WHERE status != 'cancelled' AND MONTH(created_at) = %d AND YEAR(created_at) = %d",
            date( 'n' ), date( 'Y' )
        ) );

        // Recent 5 bookings
        $recent = ll_sched_get_bookings( array( 'limit' => 5 ) );

        // Service count
        $svc_count = wp_count_posts( 'services' );
        $svc_total = isset( $svc_count->publish ) ? $svc_count->publish : 0;
        ?>
        <div class="wrap ll-admin-wrap">
            <h1 class="ll-page-heading">
                <span class="dashicons dashicons-calendar-alt"></span>
                Service Scheduler Dashboard
            </h1>

            <!-- Stat Cards -->
            <div class="ll-stat-cards">
                <div class="ll-stat-card ll-stat-total">
                    <div class="ll-stat-icon"><span class="dashicons dashicons-list-view"></span></div>
                    <div class="ll-stat-info">
                        <div class="ll-stat-num"><?php echo number_format( $total ); ?></div>
                        <div class="ll-stat-label">Total Bookings</div>
                    </div>
                </div>
                <div class="ll-stat-card ll-stat-pending">
                    <div class="ll-stat-icon"><span class="dashicons dashicons-clock"></span></div>
                    <div class="ll-stat-info">
                        <div class="ll-stat-num"><?php echo number_format( $pending ); ?></div>
                        <div class="ll-stat-label">Pending</div>
                    </div>
                </div>
                <div class="ll-stat-card ll-stat-confirmed">
                    <div class="ll-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="ll-stat-info">
                        <div class="ll-stat-num"><?php echo number_format( $confirmed ); ?></div>
                        <div class="ll-stat-label">Confirmed</div>
                    </div>
                </div>
                <div class="ll-stat-card ll-stat-revenue">
                    <div class="ll-stat-icon"><span class="dashicons dashicons-money-alt"></span></div>
                    <div class="ll-stat-info">
                        <div class="ll-stat-num">$<?php echo number_format( $revenue, 2 ); ?></div>
                        <div class="ll-stat-label">Revenue (This Month)</div>
                    </div>
                </div>
                <div class="ll-stat-card ll-stat-services">
                    <div class="ll-stat-icon"><span class="dashicons dashicons-store"></span></div>
                    <div class="ll-stat-info">
                        <div class="ll-stat-num"><?php echo number_format( $svc_total ); ?></div>
                        <div class="ll-stat-label">Active Services</div>
                    </div>
                </div>
                <div class="ll-stat-card ll-stat-cancelled">
                    <div class="ll-stat-icon"><span class="dashicons dashicons-no-alt"></span></div>
                    <div class="ll-stat-info">
                        <div class="ll-stat-num"><?php echo number_format( $cancelled ); ?></div>
                        <div class="ll-stat-label">Cancelled</div>
                    </div>
                </div>
            </div><!-- .ll-stat-cards -->

            <div class="ll-dash-columns">
                <!-- Recent Bookings -->
                <div class="ll-dash-panel ll-dash-recent">
                    <div class="ll-panel-header">
                        <h2>Recent Bookings</h2>
                        <a href="<?php echo admin_url( 'admin.php?page=ll-scheduler-bookings' ); ?>" class="button button-small">View All</a>
                    </div>
                    <?php if ( $recent ) : ?>
                    <table class="widefat ll-recent-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Customer</th>
                                <th>Services</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $recent as $b ) : ?>
                        <tr>
                            <td><?php echo $b->id; ?></td>
                            <td>
                                <strong><?php echo esc_html( $b->customer_name ); ?></strong><br>
                                <small><?php echo esc_html( $b->customer_email ); ?></small>
                            </td>
                            <td><?php echo esc_html( $b->service_names ); ?></td>
                            <td><?php echo esc_html( $b->booking_date ); ?><br><small><?php echo esc_html( $b->time_label ); ?></small></td>
                            <td><span class="ll-status-badge ll-status-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( ucfirst( $b->status ) ); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else : ?>
                    <p style="padding:20px;color:#888;">No bookings yet. They will appear here once users complete the booking form.</p>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="ll-dash-panel ll-dash-quick">
                    <div class="ll-panel-header"><h2>Quick Actions</h2></div>
                    <div class="ll-quick-actions">
                        <a href="<?php echo admin_url( 'admin.php?page=ll-scheduler-bookings' ); ?>" class="ll-qa-btn">
                            <span class="dashicons dashicons-list-view"></span> View All Bookings
                        </a>
                        <a href="<?php echo admin_url( 'post-new.php?post_type=services' ); ?>" class="ll-qa-btn">
                            <span class="dashicons dashicons-plus-alt"></span> Add New Service
                        </a>
                        <a href="<?php echo admin_url( 'edit.php?post_type=services' ); ?>" class="ll-qa-btn">
                            <span class="dashicons dashicons-admin-generic"></span> Manage Services
                        </a>
                        <a href="<?php echo admin_url( 'edit-tags.php?taxonomy=service-category&post_type=services' ); ?>" class="ll-qa-btn">
                            <span class="dashicons dashicons-tag"></span> Manage Categories
                        </a>
                        <a href="<?php echo admin_url( 'admin.php?page=ll-scheduler-settings' ); ?>" class="ll-qa-btn">
                            <span class="dashicons dashicons-admin-settings"></span> Settings
                        </a>
                        <a href="<?php echo admin_url( 'admin.php?page=ll-scheduler-settings&tab=timeslots' ); ?>" class="ll-qa-btn">
                            <span class="dashicons dashicons-clock"></span> Manage Time Slots
                        </a>
                    </div>
                    <div class="ll-shortcode-info">
                        <p><strong>Page Shortcode:</strong></p>
                        <code>[ll_schedule_service]</code>
                        <p style="margin-top:8px;font-size:12px;color:#666;">Add this to your Schedule Service page.</p>
                    </div>
                </div>
            </div><!-- .ll-dash-columns -->
        </div>
        <?php
    }

    /* ─────────────────────────────────────────────
       PAGE: All Bookings
    ───────────────────────────────────────────── */
    public function page_bookings() {
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $search        = isset( $_GET['s'] )      ? sanitize_text_field( $_GET['s'] ) : '';
        $paged         = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page      = 20;

        $bookings = ll_sched_get_bookings( array(
            'status'  => $status_filter,
            'search'  => $search,
            'limit'   => $per_page,
            'offset'  => ( $paged - 1 ) * $per_page,
        ) );

        $total_items = ll_sched_count_bookings( $status_filter );
        $total_pages = ceil( $total_items / $per_page );

        $statuses = array( 'pending', 'paid', 'confirmed', 'cancelled', 'refunded' );

        $notice = '';
        if ( isset( $_GET['updated'] ) ) $notice = 'Booking updated.';
        if ( isset( $_GET['deleted'] ) ) $notice = 'Booking deleted.';
        ?>
        <div class="wrap ll-admin-wrap">
            <h1 class="ll-page-heading">
                <span class="dashicons dashicons-list-view"></span>
                All Bookings
                <span style="font-size:14px;font-weight:400;color:#888;margin-left:8px;">(<?php echo $total_items; ?> total)</span>
            </h1>

            <?php if ( $notice ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="ll-bookings-filters">
                <form method="get">
                    <input type="hidden" name="page" value="ll-scheduler-bookings">
                    <div class="ll-filter-row">
                        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search name, email, service..." class="regular-text">
                        <select name="status">
                            <option value="">All Statuses</option>
                            <?php foreach ( $statuses as $s ) : ?>
                            <option value="<?php echo $s; ?>" <?php selected( $status_filter, $s ); ?>><?php echo ucfirst( $s ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="button">Filter</button>
                        <?php if ( $status_filter || $search ) : ?>
                        <a href="<?php echo admin_url( 'admin.php?page=ll-scheduler-bookings' ); ?>" class="button">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Bookings Table -->
            <div class="ll-bookings-table-wrap">
            <table class="widefat ll-bookings-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Customer</th>
                        <th>Services</th>
                        <th>Date &amp; Time</th>
                        <th>Property</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Order</th>
                        <th style="width:120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $bookings ) : ?>
                <?php foreach ( $bookings as $b ) : ?>
                <tr id="ll-booking-row-<?php echo $b->id; ?>">
                    <td><?php echo $b->id; ?></td>
                    <td>
                        <strong><?php echo esc_html( $b->customer_name ); ?></strong><br>
                        <small><?php echo esc_html( $b->customer_email ); ?></small><br>
                        <small><?php echo esc_html( $b->customer_phone ); ?></small>
                    </td>
                    <td>
                        <?php echo esc_html( $b->service_names ); ?>
                        <?php if ( $b->city ) echo '<br><small>📍 ' . esc_html( $b->city ) . '</small>'; ?>
                        <?php if ( $b->address ) echo '<br><small>' . esc_html( $b->address ) . '</small>'; ?>
                        <?php if ( $b->notes ) echo '<br><small style="color:#888;">💬 ' . esc_html( substr( $b->notes, 0, 60 ) ) . ( strlen( $b->notes ) > 60 ? '…' : '' ) . '</small>'; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html( $b->booking_date ); ?></strong><br>
                        <small><?php echo esc_html( $b->time_label ); ?></small>
                    </td>
                    <td>
                        <?php echo esc_html( $b->property_size ); ?>
                    </td>
                    <td><strong>$<?php echo number_format( (float) $b->total_price, 2 ); ?></strong></td>
                    <td>
                        <select class="ll-status-select" data-id="<?php echo $b->id; ?>">
                            <?php foreach ( $statuses as $s ) : ?>
                            <option value="<?php echo $s; ?>" <?php selected( $b->status, $s ); ?>><?php echo ucfirst( $s ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <?php if ( $b->order_id ) : ?>
                        <a href="<?php echo admin_url( 'post.php?post=' . $b->order_id . '&action=edit' ); ?>" target="_blank">#<?php echo $b->order_id; ?></a>
                        <?php endif; ?>
                        <?php if ( $b->jet_apt_id ) echo '<br><small>Jet #' . $b->jet_apt_id . '</small>'; ?>
                    </td>
                    <td>
                        <button class="button button-small ll-btn-delete-booking" data-id="<?php echo $b->id; ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else : ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:#888;">No bookings found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'total'     => $total_pages,
                        'current'   => $paged,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ) );
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ─────────────────────────────────────────────
       PAGE: Settings (delegates to LL_Sched_Settings)
    ───────────────────────────────────────────── */
    public function page_settings() {
        if ( class_exists( 'LL_Sched_Settings' ) ) {
            LL_Sched_Settings::render_page();
        }
    }

    /* ─────────────────────────────────────────────
       AJAX: Update booking status
    ───────────────────────────────────────────── */
    public function ajax_update_status() {
        check_ajax_referer( 'll_sched_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Not allowed.' );

        $id     = absint( $_POST['id'] ?? 0 );
        $status = sanitize_key( $_POST['status'] ?? '' );
        if ( ! $id || ! $status ) wp_send_json_error( 'Invalid data.' );

        $result = ll_sched_update_booking_status( $id, $status );

        // Also update Jet APB if appointment linked
        global $wpdb;
        $booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}ll_sched_bookings` WHERE id = %d", $id ) );
        if ( $booking && $booking->jet_apt_id ) {
            $jet_table = $wpdb->prefix . 'jet_apb_appointments';
            $jet_status_map = array(
                'pending'   => 'pending_payment',
                'paid'      => 'paid',
                'confirmed' => 'paid',
                'cancelled' => 'cancelled',
                'refunded'  => 'refunded',
            );
            $jet_status = isset( $jet_status_map[ $status ] ) ? $jet_status_map[ $status ] : $status;
            $wpdb->update( $jet_table, array( 'status' => $jet_status ), array( 'ID' => (int) $booking->jet_apt_id ) );
        }

        wp_send_json_success( array( 'message' => 'Status updated.' ) );
    }

    /* ─────────────────────────────────────────────
       AJAX: Delete booking
    ───────────────────────────────────────────── */
    public function ajax_delete_booking() {
        check_ajax_referer( 'll_sched_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Not allowed.' );

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid ID.' );

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'll_sched_bookings', array( 'id' => $id ) );
        wp_send_json_success( array( 'message' => 'Booking deleted.' ) );
    }
}
