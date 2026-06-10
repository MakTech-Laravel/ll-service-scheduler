<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds meta boxes to the "services" CPT edit screen so each service
 * can have its own schedule (blocked days, custom time slots, days off).
 */
class LL_Sched_Service_Meta {

    public function __construct() {
        add_action( 'add_meta_boxes',    array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post_services', array( $this, 'save_meta' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public function enqueue( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'services' ) return;

        wp_enqueue_script(
            'll-sched-service-meta',
            LL_SCHED_URL . 'assets/js/service-meta.js',
            array( 'jquery' ),
            LL_SCHED_VER,
            true
        );
        wp_enqueue_style(
            'll-sched-service-meta',
            LL_SCHED_URL . 'assets/css/admin.css',
            array(),
            LL_SCHED_VER
        );
    }

    public function register_meta_boxes() {
        add_meta_box(
            'll_sched_service_schedule',
            '📅 LL Scheduler — Service Schedule',
            array( $this, 'render_meta_box' ),
            'services',
            'normal',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'll_sched_service_meta', 'll_svc_nonce' );

        $use_custom  = get_post_meta( $post->ID, '_ll_svc_use_custom', true );
        $blocked     = (array) get_post_meta( $post->ID, '_ll_svc_blocked_days', true );
        $time_slots  = (array) get_post_meta( $post->ID, '_ll_svc_time_slots', true );
        $days_off    = (array) get_post_meta( $post->ID, '_ll_svc_days_off', true );

        $day_names   = array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );
        $global_blocked = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );
        ?>
        <div class="ll-svc-meta-wrap">

            <!-- Toggle custom schedule -->
            <div class="ll-svc-toggle-row">
                <label>
                    <input type="checkbox" name="_ll_svc_use_custom" id="llSvcUseCustom" value="1" <?php checked( $use_custom, '1' ); ?>>
                    <strong>Use a custom schedule for this service</strong> (overrides global settings)
                </label>
            </div>

            <div id="llSvcCustomFields" style="<?php echo $use_custom ? '' : 'display:none;'; ?>">

                <!-- ── Blocked Days ── -->
                <div class="ll-svc-section">
                    <h4>🚫 Blocked Days</h4>
                    <p class="description">Days checked here are unavailable <em>for this service only</em>. Global blocked days: <?php echo implode( ', ', array_map( function( $d ) use ( $day_names ) { return $day_names[$d]; }, $global_blocked ) ) ?: 'None'; ?>.</p>
                    <div class="ll-day-checkboxes">
                        <?php foreach ( $day_names as $num => $name ) :
                            $is_blocked = in_array( $num, array_map( 'intval', $blocked ) );
                        ?>
                        <label class="ll-day-label <?php echo $is_blocked ? 'll-day-blocked' : ''; ?>">
                            <input type="checkbox" name="_ll_svc_blocked_days[]" value="<?php echo $num; ?>" <?php echo $is_blocked ? 'checked' : ''; ?>>
                            <?php echo $name; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ── Custom Time Slots ── -->
                <div class="ll-svc-section">
                    <h4>⏰ Custom Time Slots</h4>
                    <p class="description">Leave empty to use the global time slots. Add slots here to override them for this service.</p>
                    <table class="widefat ll-slots-table" style="max-width:600px;">
                        <thead>
                            <tr><th>Label</th><th>Start</th><th>End</th><th></th></tr>
                        </thead>
                        <tbody id="llSvcSlotsBody">
                        <?php foreach ( $time_slots as $i => $sl ) : ?>
                        <tr>
                            <td><input type="text" name="_ll_svc_time_slots[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $sl['label'] ?? '' ); ?>" class="widefat" placeholder="9:00 AM – 10:00 AM"></td>
                            <td><input type="time" name="_ll_svc_time_slots[<?php echo $i; ?>][start]" value="<?php echo esc_attr( $sl['start'] ?? '' ); ?>"></td>
                            <td><input type="time" name="_ll_svc_time_slots[<?php echo $i; ?>][end]"   value="<?php echo esc_attr( $sl['end'] ?? '' ); ?>"></td>
                            <td><button type="button" class="button ll-rm-svc-slot">✕</button></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <button type="button" class="button button-secondary" id="llAddSvcSlot" style="margin-top:8px;">+ Add Time Slot</button>
                </div>

                <!-- ── Days Off ── -->
                <div class="ll-svc-section">
                    <h4>📅 Days Off / Holidays</h4>
                    <p class="description">Block specific date ranges for this service (e.g. holidays, unavailability).</p>
                    <div id="llSvcDaysOffWrap">
                        <?php foreach ( $days_off as $i => $off ) : ?>
                        <div class="ll-days-off-row">
                            <input type="text"  name="_ll_svc_days_off[<?php echo $i; ?>][label]"      value="<?php echo esc_attr( $off['label'] ?? '' ); ?>" placeholder="Label (optional)" style="width:180px;">
                            <label>From: <input type="date" name="_ll_svc_days_off[<?php echo $i; ?>][start]" value="<?php echo esc_attr( $off['start'] ?? '' ); ?>"></label>
                            <label>To:   <input type="date" name="_ll_svc_days_off[<?php echo $i; ?>][end]"   value="<?php echo esc_attr( $off['end'] ?? '' ); ?>"></label>
                            <button type="button" class="button ll-rm-days-off">Remove</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary" id="llAddDaysOff" style="margin-top:8px;">+ Add Date Range</button>
                </div>

            </div><!-- #llSvcCustomFields -->

            <p class="description" style="margin-top:12px;color:#888;">
                These settings are used by the booking calendar on the front end.
                When "Use custom schedule" is off, the <a href="<?php echo admin_url( 'admin.php?page=ll-scheduler-settings&tab=calendar' ); ?>">global settings</a> apply.
            </p>
        </div>
        <?php
    }

    public function save_meta( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['ll_svc_nonce'] ) || ! wp_verify_nonce( $_POST['ll_svc_nonce'], 'll_sched_service_meta' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Use custom toggle
        update_post_meta( $post_id, '_ll_svc_use_custom', isset( $_POST['_ll_svc_use_custom'] ) ? '1' : '0' );

        // Blocked days
        $blocked = array_map( 'intval', (array)( $_POST['_ll_svc_blocked_days'] ?? array() ) );
        update_post_meta( $post_id, '_ll_svc_blocked_days', $blocked );

        // Custom time slots
        $raw_slots = (array)( $_POST['_ll_svc_time_slots'] ?? array() );
        $clean_slots = array();
        foreach ( $raw_slots as $sl ) {
            $label = trim( sanitize_text_field( $sl['label'] ?? '' ) );
            if ( $label ) {
                $clean_slots[] = array(
                    'label' => $label,
                    'start' => sanitize_text_field( $sl['start'] ?? '09:00' ),
                    'end'   => sanitize_text_field( $sl['end']   ?? '10:00' ),
                );
            }
        }
        update_post_meta( $post_id, '_ll_svc_time_slots', $clean_slots );

        // Days off
        $raw_off = (array)( $_POST['_ll_svc_days_off'] ?? array() );
        $clean_off = array();
        foreach ( $raw_off as $off ) {
            $start = sanitize_text_field( $off['start'] ?? '' );
            if ( $start ) {
                $clean_off[] = array(
                    'label' => sanitize_text_field( $off['label'] ?? '' ),
                    'start' => $start,
                    'end'   => sanitize_text_field( $off['end'] ?? $start ),
                );
            }
        }
        update_post_meta( $post_id, '_ll_svc_days_off', $clean_off );
    }
}
