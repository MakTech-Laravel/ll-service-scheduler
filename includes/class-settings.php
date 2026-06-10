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
        $sizes  = (array) get_option( 'll_sched_property_sizes', array() );
        $cities = (array) get_option( 'll_sched_cities', array() );
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
        </table>
        <?php
    }

    /* ─── Tab: Calendar ─── */
    private static function tab_calendar() {
        $blocked   = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );
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
        }

        if ( $tab === 'calendar' ) {
            $days = array_map( 'intval', (array)( $_POST['blocked_days'] ?? array() ) );
            update_option( 'll_sched_blocked_days', $days );
            delete_option( 'll_sched_available_days' );
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
