<?php
defined( 'ABSPATH' ) || exit;

/**
 * Meta boxes on the "services" CPT: availability filters and per-service schedule.
 */
class LL_Sched_Service_Meta {

    public function __construct() {
        add_action( 'add_meta_boxes',       array( $this, 'register_meta_boxes' ) );
        add_action( 'save_post_services',   array( $this, 'save_meta' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
    }

    public function enqueue( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'services' ) {
            return;
        }

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
            'll_sched_service_availability',
            '📍 LL Scheduler — Service Availability',
            array( $this, 'render_availability_meta_box' ),
            'services',
            'normal',
            'high'
        );

        add_meta_box(
            'll_sched_service_schedule',
            '📅 LL Scheduler — Service Schedule',
            array( $this, 'render_schedule_meta_box' ),
            'services',
            'normal',
            'default'
        );
    }

    public function render_availability_meta_box( $post ) {
        wp_nonce_field( 'll_sched_service_meta', 'll_svc_nonce' );

        $allowed_sizes     = (array) get_post_meta( $post->ID, '_ll_svc_property_sizes', true );
        $allowed_cities    = (array) get_post_meta( $post->ID, '_ll_svc_cities', true );
        $allowed_addresses = (array) get_post_meta( $post->ID, '_ll_svc_addresses', true );
        $global_sizes      = (array) get_option( 'll_sched_property_sizes', array() );
        $global_cities     = (array) get_option( 'll_sched_cities', array() );
        $global_addresses  = (array) get_option( 'll_sched_addresses', array() );
        ?>
        <div class="ll-svc-meta-wrap">
            <p class="description" style="margin-top:0;">
                Restrict which property sizes, cities, and service areas can book this service on the front-end form.
                Leave all unchecked to allow <strong>all</strong> options in each group.
            </p>

            <div class="ll-svc-section">
                <h4>Allowed Property Sizes</h4>
                <?php if ( empty( $global_sizes ) ) : ?>
                <p class="description">No property sizes configured. Add them under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=general' ) ); ?>">Settings → General</a>.</p>
                <?php else : ?>
                <div class="ll-day-checkboxes">
                    <?php foreach ( $global_sizes as $size ) : ?>
                    <label class="ll-day-label <?php echo in_array( $size, $allowed_sizes, true ) ? 'll-day-open' : ''; ?>">
                        <input type="checkbox"
                               name="_ll_svc_property_sizes[]"
                               value="<?php echo esc_attr( $size ); ?>"
                               <?php checked( in_array( $size, $allowed_sizes, true ) ); ?>>
                        <?php echo esc_html( $size ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="ll-svc-section">
                <h4>Allowed Cities</h4>
                <?php if ( empty( $global_cities ) ) : ?>
                <p class="description">No cities configured. Add them under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=general' ) ); ?>">Settings → General</a>.</p>
                <?php else : ?>
                <div class="ll-day-checkboxes">
                    <?php foreach ( $global_cities as $city ) : ?>
                    <label class="ll-day-label <?php echo in_array( $city, $allowed_cities, true ) ? 'll-day-open' : ''; ?>">
                        <input type="checkbox"
                               name="_ll_svc_cities[]"
                               value="<?php echo esc_attr( $city ); ?>"
                               <?php checked( in_array( $city, $allowed_cities, true ) ); ?>>
                        <?php echo esc_html( $city ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="ll-svc-section">
                <h4>Allowed Service Areas / Addresses</h4>
                <?php if ( empty( $global_addresses ) ) : ?>
                <p class="description">No areas configured. Add them under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=general' ) ); ?>">Settings → General</a>.</p>
                <?php else : ?>
                <div class="ll-day-checkboxes">
                    <?php foreach ( $global_addresses as $address ) : ?>
                    <label class="ll-day-label <?php echo in_array( $address, $allowed_addresses, true ) ? 'll-day-open' : ''; ?>">
                        <input type="checkbox"
                               name="_ll_svc_addresses[]"
                               value="<?php echo esc_attr( $address ); ?>"
                               <?php checked( in_array( $address, $allowed_addresses, true ) ); ?>>
                        <?php echo esc_html( $address ); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function render_schedule_meta_box( $post ) {
        $use_custom    = get_post_meta( $post->ID, '_ll_svc_use_custom', true );
        $schedule_mode = get_post_meta( $post->ID, '_ll_svc_schedule_mode', true ) ?: 'combine';
        $available     = ll_sched_get_service_available_days( $post->ID );
        $time_slots    = (array) get_post_meta( $post->ID, '_ll_svc_time_slots', true );
        $days_off      = (array) get_post_meta( $post->ID, '_ll_svc_days_off', true );

        $work_days    = array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday' );
        $weekend_days = array( 6 => 'Saturday', 0 => 'Sunday' );
        $global_blocked = array_map( 'intval', (array) get_option( 'll_sched_blocked_days', array() ) );
        $day_names      = array( 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' );
        ?>
        <div class="ll-svc-meta-wrap">

            <div class="ll-svc-toggle-row">
                <label>
                    <input type="checkbox" name="_ll_svc_use_custom" id="llSvcUseCustom" value="1" <?php checked( $use_custom, '1' ); ?>>
                    <strong>Use a custom schedule for this service</strong>
                </label>
                <p class="description" style="margin:8px 0 0;">
                    When enabled, configure this service's bookable days, time slots, and days off below.
                </p>
            </div>

            <div id="llSvcCustomFields" style="<?php echo $use_custom ? '' : 'display:none;'; ?>">

                <div class="ll-svc-section">
                    <h4>Schedule Mode</h4>
                    <p class="description">Choose how this service's calendar rules interact with global settings.</p>
                    <label style="display:block;margin-bottom:6px;">
                        <input type="radio" name="_ll_svc_schedule_mode" value="combine" <?php checked( $schedule_mode, 'combine' ); ?>>
                        <strong>Combine with global</strong> — global blocked days plus this service's unavailable days apply
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="_ll_svc_schedule_mode" value="replace" <?php checked( $schedule_mode, 'replace' ); ?>>
                        <strong>Replace global</strong> — only this service's bookable days apply (ignores global blocked days)
                    </label>
                    <p class="description" style="margin-top:8px;">
                        Global blocked days: <?php echo esc_html( implode( ', ', array_map( function( $d ) use ( $day_names ) { return $day_names[ $d ]; }, $global_blocked ) ) ?: 'None' ); ?>.
                    </p>
                </div>

                <div class="ll-svc-section">
                    <h4>Work Days (Mon – Fri)</h4>
                    <p class="description">Check the weekdays this service accepts bookings.</p>
                    <div class="ll-day-checkboxes">
                        <?php foreach ( $work_days as $num => $name ) :
                            $is_open = in_array( $num, $available, true );
                        ?>
                        <label class="ll-day-label ll-day-available <?php echo $is_open ? 'll-day-open' : ''; ?>">
                            <input type="checkbox" name="_ll_svc_available_days[]" value="<?php echo $num; ?>" <?php checked( $is_open ); ?>>
                            <?php echo esc_html( $name ); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ll-svc-section">
                    <h4>Weekend Days (Sat – Sun)</h4>
                    <p class="description">Check weekend days this service accepts bookings.</p>
                    <div class="ll-day-checkboxes">
                        <?php foreach ( $weekend_days as $num => $name ) :
                            $is_open = in_array( $num, $available, true );
                        ?>
                        <label class="ll-day-label ll-day-available <?php echo $is_open ? 'll-day-open' : ''; ?>">
                            <input type="checkbox" name="_ll_svc_available_days[]" value="<?php echo $num; ?>" <?php checked( $is_open ); ?>>
                            <?php echo esc_html( $name ); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ll-svc-section">
                    <h4>Custom Time Slots</h4>
                    <p class="description">Leave empty to use global time slots. Add slots here to override them for this service.</p>
                    <table class="widefat ll-slots-table" style="width:100%;max-width:100%;">
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

                <div class="ll-svc-section">
                    <h4>Days Off / Holidays</h4>
                    <p class="description">Block specific date ranges for this service (e.g. holidays, unavailability).</p>
                    <div id="llSvcDaysOffWrap">
                        <?php foreach ( $days_off as $i => $off ) : ?>
                        <div class="ll-days-off-row">
                            <input type="text"  name="_ll_svc_days_off[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $off['label'] ?? '' ); ?>" placeholder="Label (optional)" style="width:180px;">
                            <label>From: <input type="date" name="_ll_svc_days_off[<?php echo $i; ?>][start]" value="<?php echo esc_attr( $off['start'] ?? '' ); ?>"></label>
                            <label>To:   <input type="date" name="_ll_svc_days_off[<?php echo $i; ?>][end]"   value="<?php echo esc_attr( $off['end'] ?? '' ); ?>"></label>
                            <button type="button" class="button ll-rm-days-off">Remove</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button button-secondary" id="llAddDaysOff" style="margin-top:8px;">+ Add Date Range</button>
                </div>

            </div>

            <p class="description" style="margin-top:12px;color:#888;">
                When custom schedule is off, <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=calendar' ) ); ?>">global calendar settings</a> apply.
            </p>
        </div>
        <?php
    }

    public function save_meta( $post_id, $post ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST['ll_svc_nonce'] ) || ! wp_verify_nonce( $_POST['ll_svc_nonce'], 'll_sched_service_meta' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Filter availability
        $sizes     = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['_ll_svc_property_sizes'] ?? array() ) ) ) );
        $cities    = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['_ll_svc_cities'] ?? array() ) ) ) );
        $addresses = array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['_ll_svc_addresses'] ?? array() ) ) ) );
        update_post_meta( $post_id, '_ll_svc_property_sizes', $sizes );
        update_post_meta( $post_id, '_ll_svc_cities', $cities );
        update_post_meta( $post_id, '_ll_svc_addresses', $addresses );

        // Custom schedule toggle
        update_post_meta( $post_id, '_ll_svc_use_custom', isset( $_POST['_ll_svc_use_custom'] ) ? '1' : '0' );

        $mode = sanitize_text_field( $_POST['_ll_svc_schedule_mode'] ?? 'combine' );
        update_post_meta( $post_id, '_ll_svc_schedule_mode', in_array( $mode, array( 'combine', 'replace' ), true ) ? $mode : 'combine' );

        // Available days (work + weekend checkboxes)
        $available = array_map( 'intval', (array) ( $_POST['_ll_svc_available_days'] ?? array() ) );
        $available = array_values( array_unique( array_intersect( $available, range( 0, 6 ) ) ) );
        update_post_meta( $post_id, '_ll_svc_available_days', $available );

        // Keep legacy blocked meta in sync for backward compatibility
        $blocked = array_values( array_diff( range( 0, 6 ), $available ) );
        update_post_meta( $post_id, '_ll_svc_blocked_days', $blocked );

        // Custom time slots
        $raw_slots   = (array) ( $_POST['_ll_svc_time_slots'] ?? array() );
        $clean_slots = array();
        foreach ( $raw_slots as $sl ) {
            $label = trim( sanitize_text_field( $sl['label'] ?? '' ) );
            if ( $label ) {
                $clean_slots[] = array(
                    'label' => $label,
                    'start' => sanitize_text_field( $sl['start'] ?? '09:00' ),
                    'end'   => sanitize_text_field( $sl['end'] ?? '10:00' ),
                );
            }
        }
        update_post_meta( $post_id, '_ll_svc_time_slots', $clean_slots );

        // Days off
        $raw_off   = (array) ( $_POST['_ll_svc_days_off'] ?? array() );
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
