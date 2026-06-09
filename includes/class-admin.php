<?php
defined( 'ABSPATH' ) || exit;

class LL_Sched_Admin {

    public function __construct() {
        add_action( 'admin_menu',              array( $this, 'add_menu' ) );
        add_action( 'admin_post_ll_sched_save', array( $this, 'save_settings' ) );
        add_action( 'admin_enqueue_scripts',   array( $this, 'enqueue' ) );
        add_action( 'admin_notices',           array( $this, 'saved_notice' ) );
        // Also create WC product on init in case activation ran before WC was ready
        add_action( 'init', array( $this, 'maybe_create_product' ) );
    }

    /* ─────────────────────────────────────────────
       Create WC product lazily if missed at activation
    ───────────────────────────────────────────── */
    public function maybe_create_product() {
        if ( ! is_admin() ) return;
        if ( ! class_exists( 'WooCommerce' ) ) return;
        $id = (int) get_option( 'll_sched_woo_product_id', 0 );
        if ( ! $id || get_post_status( $id ) === false ) {
            ll_sched_ensure_woo_product();
        }
    }

    /* ─────────────────────────────────────────────
       Menu entry under Settings
    ───────────────────────────────────────────── */
    public function add_menu() {
        add_submenu_page(
            'options-general.php',
            'Service Scheduler Settings',
            'Service Scheduler',
            'manage_options',
            'll-scheduler',
            array( $this, 'render_page' )
        );
    }

    /* ─────────────────────────────────────────────
       Enqueue admin JS (only on our settings page)
    ───────────────────────────────────────────── */
    public function enqueue( $hook ) {
        if ( $hook !== 'settings_page_ll-scheduler' ) return;
        wp_enqueue_script(
            'll-sched-admin',
            LL_SCHED_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            LL_SCHED_VER,
            true
        );
    }

    /* ─────────────────────────────────────────────
       Saved confirmation notice
    ───────────────────────────────────────────── */
    public function saved_notice() {
        $page  = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        $saved = isset( $_GET['saved'] );
        if ( $page === 'll-scheduler' && $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Service Scheduler:</strong> Settings saved successfully.</p></div>';
        }
    }

    /* ─────────────────────────────────────────────
       Main settings page renderer (tabbed)
    ───────────────────────────────────────────── */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        $tabs = array(
            'general'   => '&#9881; General',
            'calendar'  => '&#128197; Calendar &amp; Days',
            'timeslots' => '&#128336; Time Slots',
        );
        ?>
        <div class="wrap">
            <h1 style="margin-bottom:8px;">Service Scheduler Settings</h1>
            <p style="color:#666;margin-bottom:20px;">
                Shortcode to use on your Schedule Service page:
                <code style="background:#f0f0f0;padding:3px 8px;border-radius:4px;">[ll_schedule_service]</code>
            </p>

            <nav class="nav-tab-wrapper" style="margin-bottom:0;">
                <?php foreach ( $tabs as $key => $label ) : ?>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=ll-scheduler&tab=' . $key ) ); ?>"
                   class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                    <?php echo $label; ?>
                </a>
                <?php endforeach; ?>
            </nav>

            <div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:20px 24px;margin-bottom:20px;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'll_sched_save', 'll_nonce' ); ?>
                    <input type="hidden" name="action" value="ll_sched_save">
                    <input type="hidden" name="ll_tab"  value="<?php echo esc_attr( $tab ); ?>">

                    <?php
                    if ( $tab === 'general' ) {
                        $this->tab_general();
                    } elseif ( $tab === 'calendar' ) {
                        $this->tab_calendar();
                    } elseif ( $tab === 'timeslots' ) {
                        $this->tab_timeslots();
                    } else {
                        $this->tab_general();
                    }
                    ?>

                    <?php submit_button( 'Save Settings' ); ?>
                </form>
            </div>

            <!-- Shortcode usage help -->
            <div style="background:#f9f9f9;border:1px solid #e0e0e0;border-radius:6px;padding:16px 20px;">
                <h3 style="margin-top:0;">How to Use</h3>
                <ol style="margin:0;padding-left:20px;line-height:1.9;">
                    <li>Go to <strong>Pages → Edit</strong> your Schedule Service page</li>
                    <li>Add a <strong>Shortcode block</strong> (or Classic block) and paste: <code>[ll_schedule_service]</code></li>
                    <li>Save and preview the page</li>
                </ol>
            </div>
        </div>
        <?php
    }

    /* ─────────────────────────────────────────────
       TAB: General
    ───────────────────────────────────────────── */
    private function tab_general() {
        $sizes = (array) get_option( 'll_sched_property_sizes', array() );
        $cities = (array) get_option( 'll_sched_cities', array() );
        $mode   = get_option( 'll_sched_selection_mode', 'multiple' );
        ?>
        <table class="form-table" style="margin-top:10px;">

            <!-- Selection Mode -->
            <tr>
                <th scope="row" style="width:220px;">Service Selection Mode</th>
                <td>
                    <fieldset>
                        <label style="display:block;margin-bottom:10px;">
                            <input type="radio" name="selection_mode" value="multiple"
                                <?php echo checked( $mode, 'multiple', false ); ?>>
                            <strong>Multiple</strong> &mdash; Users can check as many services as they want
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="selection_mode" value="single"
                                <?php echo checked( $mode, 'single', false ); ?>>
                            <strong>Single</strong> &mdash; Selecting a new service automatically unchecks the previous one
                        </label>
                    </fieldset>
                </td>
            </tr>

            <!-- Property Sizes -->
            <tr>
                <th scope="row">Property Sizes (Sq. Ft)</th>
                <td>
                    <p class="description" style="margin-bottom:10px;">These appear in the dropdown at the top of the booking page.</p>
                    <div id="ll-sizes-wrap">
                        <?php foreach ( $sizes as $s ) : ?>
                        <div class="ll-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
                            <input type="text" name="property_sizes[]"
                                   value="<?php echo esc_attr( $s ); ?>"
                                   class="regular-text"
                                   placeholder="e.g. 0-3000 Sq. Ft">
                            <button type="button" class="button ll-rm" title="Remove">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button"
                            class="button button-secondary ll-add"
                            data-target="ll-sizes-wrap"
                            data-name="property_sizes[]"
                            data-placeholder="e.g. 0-3000 Sq. Ft"
                            style="margin-top:4px;">+ Add Size</button>
                </td>
            </tr>

            <!-- Cities -->
            <tr>
                <th scope="row">Cities / Towns</th>
                <td>
                    <p class="description" style="margin-bottom:10px;">These appear in the "Select City" dropdown.</p>
                    <div id="ll-cities-wrap">
                        <?php foreach ( $cities as $c ) : ?>
                        <div class="ll-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
                            <input type="text" name="cities[]"
                                   value="<?php echo esc_attr( $c ); ?>"
                                   class="regular-text"
                                   placeholder="e.g. Montreal">
                            <button type="button" class="button ll-rm" title="Remove">✕</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button"
                            class="button button-secondary ll-add"
                            data-target="ll-cities-wrap"
                            data-name="cities[]"
                            data-placeholder="e.g. Montreal"
                            style="margin-top:4px;">+ Add City</button>
                </td>
            </tr>

        </table>
        <?php
    }

    /* ─────────────────────────────────────────────
       TAB: Calendar & Days
    ───────────────────────────────────────────── */
    private function tab_calendar() {
        $available = array_map( 'intval', (array) get_option( 'll_sched_available_days', array( 0, 6 ) ) );
        $day_names = array(
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        );
        ?>
        <table class="form-table" style="margin-top:10px;">
            <tr>
                <th scope="row" style="width:220px;">Available Booking Days</th>
                <td>
                    <p class="description" style="margin-bottom:14px;">
                        Tick the days that are <strong>open</strong> for bookings.
                        All other days will be greyed-out and unclickable on the calendar.<br>
                        <em>Default: Saturday and Sunday.</em>
                    </p>
                    <div style="display:grid;grid-template-columns:repeat(4,140px);gap:10px;">
                        <?php foreach ( $day_names as $num => $name ) : ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #ddd;border-radius:6px;cursor:pointer;background:<?php echo in_array( $num, $available ) ? '#f0fff4' : '#fff'; ?>">
                            <input type="checkbox"
                                   name="available_days[]"
                                   value="<?php echo $num; ?>"
                                   <?php echo in_array( $num, $available ) ? 'checked' : ''; ?>>
                            <?php echo $name; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    /* ─────────────────────────────────────────────
       TAB: Time Slots
    ───────────────────────────────────────────── */
    private function tab_timeslots() {
        $slots = (array) get_option( 'll_sched_time_slots', array() );
        ?>
        <p style="margin-bottom:12px;">
            Configure the time slots users can pick from after selecting a date.
            The <strong>Label</strong> is what the user sees; Start/End are used internally.
        </p>

        <table class="widefat striped" style="max-width:700px;">
            <thead>
                <tr>
                    <th style="width:45%;">Label (shown to user)</th>
                    <th style="width:20%;">Start Time</th>
                    <th style="width:20%;">End Time</th>
                    <th style="width:15%;">Action</th>
                </tr>
            </thead>
            <tbody id="ll-slots-body">
                <?php foreach ( $slots as $i => $sl ) : ?>
                <tr>
                    <td>
                        <input type="text"
                               name="time_slots[<?php echo $i; ?>][label]"
                               value="<?php echo esc_attr( $sl['label'] ); ?>"
                               class="widefat"
                               placeholder="e.g. 9:00 AM – 10:00 AM">
                    </td>
                    <td><input type="time" name="time_slots[<?php echo $i; ?>][start]" value="<?php echo esc_attr( $sl['start'] ); ?>"></td>
                    <td><input type="time" name="time_slots[<?php echo $i; ?>][end]"   value="<?php echo esc_attr( $sl['end'] ); ?>"></td>
                    <td><button type="button" class="button ll-rm-slot">Remove</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="button" class="button button-secondary" id="ll-add-slot" style="margin-top:10px;">
                + Add Time Slot
            </button>
        </p>
        <p class="description">Current slot count: <span id="ll-slot-count"><?php echo count( $slots ); ?></span></p>
        <?php
    }

    /* ─────────────────────────────────────────────
       Process form save
    ───────────────────────────────────────────── */
    public function save_settings() {
        if ( ! isset( $_POST['ll_nonce'] ) || ! wp_verify_nonce( $_POST['ll_nonce'], 'll_sched_save' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Not allowed.' );
        }

        $tab = isset( $_POST['ll_tab'] ) ? sanitize_key( $_POST['ll_tab'] ) : 'general';

        /* ─── General ─── */
        if ( $tab === 'general' ) {
            $mode = isset( $_POST['selection_mode'] ) ? sanitize_text_field( $_POST['selection_mode'] ) : 'multiple';
            update_option( 'll_sched_selection_mode', in_array( $mode, array( 'single', 'multiple' ), true ) ? $mode : 'multiple' );

            $raw_sizes = isset( $_POST['property_sizes'] ) ? (array) $_POST['property_sizes'] : array();
            $sizes     = array_values( array_filter( array_map( 'sanitize_text_field', $raw_sizes ) ) );
            update_option( 'll_sched_property_sizes', $sizes );

            $raw_cities = isset( $_POST['cities'] ) ? (array) $_POST['cities'] : array();
            $cities     = array_values( array_filter( array_map( 'sanitize_text_field', $raw_cities ) ) );
            update_option( 'll_sched_cities', $cities );
        }

        /* ─── Calendar ─── */
        if ( $tab === 'calendar' ) {
            $days = isset( $_POST['available_days'] ) ? array_map( 'intval', (array) $_POST['available_days'] ) : array();
            update_option( 'll_sched_available_days', $days );
        }

        /* ─── Time Slots ─── */
        if ( $tab === 'timeslots' ) {
            $raw_slots = isset( $_POST['time_slots'] ) ? (array) $_POST['time_slots'] : array();
            $clean = array();
            foreach ( $raw_slots as $sl ) {
                $label = isset( $sl['label'] ) ? trim( sanitize_text_field( $sl['label'] ) ) : '';
                if ( $label === '' ) continue;
                $clean[] = array(
                    'label' => $label,
                    'start' => isset( $sl['start'] ) ? sanitize_text_field( $sl['start'] ) : '09:00',
                    'end'   => isset( $sl['end'] )   ? sanitize_text_field( $sl['end'] )   : '10:00',
                );
            }
            update_option( 'll_sched_time_slots', $clean );
        }

        wp_safe_redirect(
            admin_url( 'options-general.php?page=ll-scheduler&tab=' . $tab . '&saved=1' )
        );
        exit;
    }
}
