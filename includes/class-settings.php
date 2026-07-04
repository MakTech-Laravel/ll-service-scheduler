<?php
defined( 'ABSPATH' ) || exit;

class LL_Sched_Settings {

    public function __construct() {
        add_action( 'admin_post_ll_sched_save', array( $this, 'save' ) );
        add_action( 'admin_notices',            array( $this, 'saved_notice' ) );
    }

    public function saved_notice() {
        $page  = isset( $_GET['page'] )  ? sanitize_key( $_GET['page'] )  : '';
        if ( $page === 'll-scheduler-settings' && isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Settings saved.</strong></p></div>';
        }
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $tabs = array(
            'general'   => '&#9881; General',
            'calendar'  => '&#128197; Calendar &amp; Days',
            'timeslots' => '&#128336; Time Slots',
        );
        ?>
        <div class="wrap ll-admin-wrap">
            <h1 class="ll-page-heading">
                <span class="dashicons dashicons-admin-settings"></span>
                Settings
            </h1>
            <nav class="nav-tab-wrapper" style="margin-bottom:0;">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=' . $key ) ); ?>"
                   class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo $label; ?>
                </a>
                <?php endforeach; ?>
            </nav>
            <div class="ll-settings-body">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'll_sched_save', 'll_nonce' ); ?>
                    <input type="hidden" name="action"  value="ll_sched_save">
                    <input type="hidden" name="ll_tab"  value="<?php echo esc_attr( $tab ); ?>">
                    <?php
                    if ( $tab === 'general' )   self::tab_general();
                    elseif ( $tab === 'calendar' )  self::tab_calendar();
                    elseif ( $tab === 'timeslots' ) self::tab_timeslots();
                    ?>
                    <?php submit_button( 'Save Settings' ); ?>
                </form>
            </div>
        </div>
        <?php
    }

    /* ─── Tab: General ─── */
    private static function tab_general() {
        $sizes      = (array) get_option( 'll_sched_property_sizes', array() );
        $cities     = (array) get_option( 'll_sched_cities', array() );
        $addresses  = (array) get_option( 'll_sched_addresses', array() );
        $mode   = get_option( 'll_sched_selection_mode', 'multiple' );
        ?>
        <table class="form-table">
            <tr>
                <th>Selection Mode</th>
                <td>
                    <label style="display:block;margin-bottom:10px;">
                        <input type="radio" name="selection_mode" value="multiple" <?php checked( $mode, 'multiple' ); ?>>
                        <strong>Multiple</strong> — users can select many services
                    </label>
                    <label>
                        <input type="radio" name="selection_mode" value="single" <?php checked( $mode, 'single' ); ?>>
                        <strong>Single</strong> — selecting a new service unchecks the previous
                    </label>
                </td>
            </tr>
            <tr>
                <th>Property Sizes (Sq. Ft)</th>
                <td>
                    <p class="description" style="margin-bottom:10px;">Dropdown options shown at the top of the booking form.</p>
                    <div id="ll-sizes-wrap">
                        <?php foreach ( $sizes as $s ) : ?>
                        <div class="ll-row">
                            <input type="text" name="property_sizes[]" value="<?php echo esc_attr( $s ); ?>" class="regular-text" placeholder="e.g. 0-3000 Sq. Ft">
                            <button type="button" class="button ll-rm">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary ll-add" data-target="ll-sizes-wrap" data-name="property_sizes[]" data-placeholder="e.g. 0-3000 Sq. Ft" style="margin-top:6px;">+ Add Size</button>
                </td>
            </tr>
            <tr>
                <th>Cities / Towns</th>
                <td>
                    <p class="description" style="margin-bottom:10px;">"Select City" dropdown options.</p>
                    <div id="ll-cities-wrap">
                        <?php foreach ( $cities as $c ) : ?>
                        <div class="ll-row">
                            <input type="text" name="cities[]" value="<?php echo esc_attr( $c ); ?>" class="regular-text" placeholder="e.g. Montreal">
                            <button type="button" class="button ll-rm">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary ll-add" data-target="ll-cities-wrap" data-name="cities[]" data-placeholder="e.g. Montreal" style="margin-top:6px;">+ Add City</button>
                </td>
            </tr>
            <tr>
                <th>Service Areas / Addresses</th>
                <td>
                    <p class="description" style="margin-bottom:10px;">Address or area options for the booking form filter (e.g. neighborhoods, zones). Used the same way as cities.</p>
                    <div id="ll-addresses-wrap">
                        <?php foreach ( $addresses as $a ) : ?>
                        <div class="ll-row">
                            <input type="text" name="addresses[]" value="<?php echo esc_attr( $a ); ?>" class="regular-text" placeholder="e.g. Downtown Montreal">
                            <button type="button" class="button ll-rm">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary ll-add" data-target="ll-addresses-wrap" data-name="addresses[]" data-placeholder="e.g. Downtown Montreal" style="margin-top:6px;">+ Add Area</button>
                </td>
            </tr>
        </table>
        <?php
    }

    /* ─── Tab: Calendar ─── */
    private static function tab_calendar() {
        $blocked   = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );
        $days_off  = ll_sched_get_global_days_off();
        $day_names = array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );
        ?>
        <table class="form-table">
            <tr>
                <th>Blocked Booking Days</th>
                <td>
                    <p class="description" style="margin-bottom:14px;">Checked days are <strong>unavailable</strong> on the calendar. Leave a day unchecked to allow bookings on it.</p>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;">
                        <?php foreach ( $day_names as $num => $name ) :
                            $is_blocked = in_array( $num, $blocked );
                        ?>
                        <label class="ll-day-label <?php echo $is_blocked ? 'll-day-blocked' : 'll-day-open'; ?>">
                            <input type="checkbox" name="blocked_days[]" value="<?php echo $num; ?>" <?php echo $is_blocked ? 'checked' : ''; ?>>
                            <?php echo $name; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description" style="margin-top:10px;">Default: Sunday and Saturday are blocked (no bookings on weekends).</p>
                </td>
            </tr>
            <tr>
                <th>Blocked Specific Dates</th>
                <td>
                    <p class="description" style="margin-bottom:12px;">
                        Block specific dates when you are unavailable. Use <strong>Single date</strong> to block one day,
                        or <strong>Date range</strong> to block multiple consecutive days. These apply to all services.
                    </p>

                    <div class="ll-admin-block-mode" style="margin-bottom:12px;">
                        <label style="margin-right:16px;">
                            <input type="radio" name="ll_admin_block_mode_ui" value="single" checked>
                            Single date
                        </label>
                        <label>
                            <input type="radio" name="ll_admin_block_mode_ui" value="range">
                            Date range
                        </label>
                    </div>

                    <div id="llAdminBlockedCal" class="ll-admin-blocked-cal">
                        <div class="ll-admin-cal-header">
                            <button type="button" class="button" id="llAdminCalPrev" aria-label="Previous month">&lsaquo;</button>
                            <span id="llAdminCalTitle"></span>
                            <button type="button" class="button" id="llAdminCalNext" aria-label="Next month">&rsaquo;</button>
                        </div>
                        <div class="ll-admin-cal-weekdays" aria-hidden="true">
                            <span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span>
                            <span>Fri</span><span>Sat</span><span>Sun</span>
                        </div>
                        <div class="ll-admin-cal-grid" id="llAdminCalGrid" role="grid"></div>
                        <p class="description" id="llAdminCalHint" style="margin-top:8px;">Click a day to select it, then click Add blocked date(s).</p>
                    </div>

                    <div class="ll-admin-block-toolbar" style="margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                        <input type="text"
                               id="llAdminBlockLabel"
                               class="regular-text"
                               placeholder="Label (optional)"
                               style="max-width:220px;">
                        <button type="button" class="button button-secondary" id="llAdminAddBlockedDate">Add blocked date(s)</button>
                    </div>

                    <div id="llAdminDaysOffList" class="ll-admin-days-off-list" style="margin-top:16px;">
                        <?php foreach ( $days_off as $i => $off ) :
                            $display = $off['start'] === $off['end']
                                ? wp_date( get_option( 'date_format' ), strtotime( $off['start'] ) )
                                : wp_date( get_option( 'date_format' ), strtotime( $off['start'] ) ) . ' – ' . wp_date( get_option( 'date_format' ), strtotime( $off['end'] ) );
                        ?>
                        <div class="ll-admin-days-off-row" data-start="<?php echo esc_attr( $off['start'] ); ?>" data-end="<?php echo esc_attr( $off['end'] ); ?>">
                            <span class="ll-admin-days-off-display">
                                <?php if ( ! empty( $off['label'] ) ) : ?>
                                <strong><?php echo esc_html( $off['label'] ); ?></strong> —
                                <?php endif; ?>
                                <?php echo esc_html( $display ); ?>
                            </span>
                            <input type="hidden" name="days_off[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $off['label'] ); ?>">
                            <input type="hidden" name="days_off[<?php echo (int) $i; ?>][start]" value="<?php echo esc_attr( $off['start'] ); ?>">
                            <input type="hidden" name="days_off[<?php echo (int) $i; ?>][end]" value="<?php echo esc_attr( $off['end'] ); ?>">
                            <button type="button" class="button ll-admin-rm-days-off">Remove</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    /* ─── Tab: Time Slots ─── */
    private static function tab_timeslots() {
        $slots = (array) get_option( 'll_sched_time_slots', array() );
        ?>
        <p>Configure the global time slots. Individual services can override these with their own schedule (set in the service edit screen).</p>
        <table class="widefat ll-slots-table" style="width:100%;">
            <thead>
                <tr><th>Label (shown to user)</th><th>Start</th><th>End</th><th>Remove</th></tr>
            </thead>
            <tbody id="ll-slots-body">
            <?php foreach ( $slots as $i => $sl ) : ?>
            <tr>
                <td><input type="text" name="time_slots[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $sl['label'] ); ?>" class="widefat" placeholder="e.g. 9:00 AM – 10:00 AM"></td>
                <td><input type="time" name="time_slots[<?php echo $i; ?>][start]" value="<?php echo esc_attr( $sl['start'] ); ?>"></td>
                <td><input type="time" name="time_slots[<?php echo $i; ?>][end]"   value="<?php echo esc_attr( $sl['end'] ); ?>"></td>
                <td><button type="button" class="button ll-rm-slot">Remove</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="button" class="button button-secondary" id="ll-add-slot" style="margin-top:10px;">+ Add Time Slot</button>
        <p class="description">Count: <span id="ll-slot-count"><?php echo count( $slots ); ?></span></p>
        <?php
    }

    /* ─────────────────────────────────────────────
       Save handler (called via admin-post.php)
    ───────────────────────────────────────────── */
    public function save() {
        if ( ! isset( $_POST['ll_nonce'] ) || ! wp_verify_nonce( $_POST['ll_nonce'], 'll_sched_save' ) ) wp_die( 'Security check failed.' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );

        $tab = isset( $_POST['ll_tab'] ) ? sanitize_key( $_POST['ll_tab'] ) : 'general';

        if ( $tab === 'general' ) {
            $mode = sanitize_text_field( $_POST['selection_mode'] ?? 'multiple' );
            update_option( 'll_sched_selection_mode', in_array( $mode, array( 'single', 'multiple' ) ) ? $mode : 'multiple' );
            update_option( 'll_sched_property_sizes', array_values( array_filter( array_map( 'sanitize_text_field', (array)( $_POST['property_sizes'] ?? array() ) ) ) ) );
            update_option( 'll_sched_cities',         array_values( array_filter( array_map( 'sanitize_text_field', (array)( $_POST['cities'] ?? array() ) ) ) ) );
            update_option( 'll_sched_addresses',    array_values( array_filter( array_map( 'sanitize_text_field', (array)( $_POST['addresses'] ?? array() ) ) ) ) );
        }

        if ( $tab === 'calendar' ) {
            $days = array_map( 'intval', (array)( $_POST['blocked_days'] ?? array() ) );
            update_option( 'll_sched_blocked_days', $days );
            delete_option( 'll_sched_available_days' );

            $clean_off = array();
            foreach ( (array) ( $_POST['days_off'] ?? array() ) as $off ) {
                $start = sanitize_text_field( $off['start'] ?? '' );
                $end   = sanitize_text_field( $off['end'] ?? $start );
                if ( $start === '' ) {
                    continue;
                }
                $start_obj = DateTime::createFromFormat( 'Y-m-d', $start );
                $end_obj   = DateTime::createFromFormat( 'Y-m-d', $end );
                if ( ! $start_obj || $start_obj->format( 'Y-m-d' ) !== $start ) {
                    continue;
                }
                if ( ! $end_obj || $end_obj->format( 'Y-m-d' ) !== $end ) {
                    $end = $start;
                }
                if ( $end < $start ) {
                    $tmp   = $start;
                    $start = $end;
                    $end   = $tmp;
                }
                $clean_off[] = array(
                    'label' => sanitize_text_field( $off['label'] ?? '' ),
                    'start' => $start,
                    'end'   => $end,
                );
            }
            update_option( 'll_sched_days_off', $clean_off );
        }

        if ( $tab === 'timeslots' ) {
            $clean = array();
            foreach ( (array)( $_POST['time_slots'] ?? array() ) as $sl ) {
                $label = trim( sanitize_text_field( $sl['label'] ?? '' ) );
                if ( $label ) {
                    $clean[] = array(
                        'label' => $label,
                        'start' => sanitize_text_field( $sl['start'] ?? '09:00' ),
                        'end'   => sanitize_text_field( $sl['end']   ?? '10:00' ),
                    );
                }
            }
            update_option( 'll_sched_time_slots', $clean );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ll-scheduler-settings&tab=' . $tab . '&saved=1' ) );
        exit;
    }
}
