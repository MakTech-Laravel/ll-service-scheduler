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
        wp_enqueue_media();
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

        $allowed_sizes       = (array) get_post_meta( $post->ID, '_ll_svc_property_sizes', true );
        $size_prices         = (array) get_post_meta( $post->ID, '_ll_svc_size_prices', true );
        $city_size_prices    = (array) get_post_meta( $post->ID, '_ll_svc_city_size_prices', true );
        $allowed_cities      = (array) get_post_meta( $post->ID, '_ll_svc_cities', true );
        $allowed_addresses = (array) get_post_meta( $post->ID, '_ll_svc_addresses', true );
        $card_media_id     = (int) get_post_meta( $post->ID, '_ll_svc_card_media_id', true );
        $card_media        = $card_media_id ? ll_sched_get_service_card_media( $post->ID ) : null;
        $featured_media    = ! $card_media_id ? ll_sched_get_service_card_media( $post->ID ) : null;
        $global_sizes      = (array) get_option( 'll_sched_property_sizes', array() );
        $global_cities     = (array) get_option( 'll_sched_cities', array() );
        $global_addresses  = (array) get_option( 'll_sched_addresses', array() );
        ?>
        <div class="ll-svc-meta-wrap">
            <p class="description" style="margin-top:0;">
                Restrict which property sizes, cities, and service areas can book this service on the front-end form.
                Leave all empty to allow <strong>all</strong> options.
            </p>

            <div class="ll-svc-section">
                <h4>Service Card Image / GIF / Video</h4>
                <p class="description">
                    Optional override for the booking form card. Leave empty to use this service&rsquo;s <strong>Featured Image</strong>.
                    Upload a still image, animated GIF, or short MP4/WebM clip (keep files small for fast loading).
                    GIFs and videos autoplay and loop on the service card.
                </p>
                <input type="hidden" name="_ll_svc_card_media_id" id="llSvcCardMediaId" value="<?php echo esc_attr( $card_media_id ); ?>">
                <div class="ll-svc-card-media-preview" id="llSvcCardMediaPreview">
                    <?php
                    $preview = $card_media ?: $featured_media;
                    if ( $preview && $preview['type'] === 'video' ) :
                    ?>
                        <video src="<?php echo esc_url( $preview['url'] ); ?>" muted loop playsinline></video>
                    <?php elseif ( $preview ) : ?>
                        <img src="<?php echo esc_url( $preview['url'] ); ?>" alt="">
                    <?php else : ?>
                        <span class="ll-svc-card-media-placeholder">No media selected — Featured Image will be used if set.</span>
                    <?php endif; ?>
                </div>
                <p class="ll-svc-card-media-actions">
                    <button type="button" class="button" id="llSvcCardMediaPick">Select Media</button>
                    <button type="button" class="button button-link-delete" id="llSvcCardMediaRemove" <?php echo $card_media_id ? '' : 'style="display:none;"'; ?>>Remove Override</button>
                </p>
            </div>

            <div class="ll-svc-section">
                <h4>Allowed Property Sizes (Sq. Ft)</h4>
                <p class="description">
                    Choose which property sizes can book this service. Leave all unchecked to allow <strong>any</strong> size.
                </p>
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
                <p class="description">Leave all unchecked to allow <strong>any</strong> property size on the booking form.</p>
                <?php endif; ?>
            </div>

            <div class="ll-svc-section">
                <h4>Pricing by Property Size</h4>
                <p class="description">
                    Optional. If you enter prices here, the front-end will use the selected Property Size to calculate the service price.
                    Leave a size price blank to fall back to this service&rsquo;s base price (the existing <code>price</code> field).
                </p>
                <?php if ( empty( $global_sizes ) ) : ?>
                    <p class="description">No property sizes configured. Add them under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=general' ) ); ?>">Settings → General</a>.</p>
                <?php else : ?>
                    <table class="widefat striped" style="max-width:520px;">
                        <thead>
                            <tr>
                                <th style="width:65%;">Property Size</th>
                                <th style="width:35%;">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( array_values( $global_sizes ) as $i => $size ) :
                                $val = '';
                                if ( is_array( $size_prices ) && array_key_exists( $size, $size_prices ) ) {
                                    $val = $size_prices[ $size ];
                                }
                            ?>
                                <tr>
                                    <td>
                                        <?php echo esc_html( $size ); ?>
                                        <input type="hidden" name="_ll_svc_size_prices[<?php echo (int) $i; ?>][size]" value="<?php echo esc_attr( $size ); ?>">
                                    </td>
                                    <td>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="small-text"
                                            name="_ll_svc_size_prices[<?php echo (int) $i; ?>][price]"
                                            value="<?php echo esc_attr( $val ); ?>"
                                            placeholder="e.g. 99">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="ll-svc-section">
                <h4>Pricing by City</h4>
                <p class="description">
                    Optional. Set different prices per city for each property size.
                    When a customer selects a city and size on the booking form, the matching city price is used.
                    Leave a field blank to fall back to the <strong>Pricing by Property Size</strong> value above, then the service base price.
                </p>
                <?php if ( empty( $global_cities ) ) : ?>
                    <p class="description">No cities configured. Add them under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=general' ) ); ?>">Settings → General</a>.</p>
                <?php elseif ( empty( $global_sizes ) ) : ?>
                    <p class="description">Add property sizes under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=general' ) ); ?>">Settings → General</a> before setting city prices.</p>
                <?php else : ?>
                    <div class="ll-city-price-groups">
                        <?php foreach ( $global_cities as $city_index => $city ) :
                            $city_prices = (array) ( $city_size_prices[ $city ] ?? array() );
                            $has_prices  = false;
                            foreach ( $city_prices as $amount ) {
                                if ( floatval( $amount ) > 0 ) {
                                    $has_prices = true;
                                    break;
                                }
                            }
                        ?>
                        <details class="ll-city-price-group" <?php echo $has_prices ? 'open' : ''; ?>>
                            <summary class="ll-city-price-summary"><?php echo esc_html( $city ); ?></summary>
                            <table class="widefat striped ll-city-price-table">
                                <thead>
                                    <tr>
                                        <th style="width:65%;">Property Size</th>
                                        <th style="width:35%;">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( array_values( $global_sizes ) as $size_index => $size ) :
                                        $val = '';
                                        if ( is_array( $city_prices ) && array_key_exists( $size, $city_prices ) ) {
                                            $val = $city_prices[ $size ];
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <?php echo esc_html( $size ); ?>
                                            <input type="hidden"
                                                   name="_ll_svc_city_size_prices[<?php echo (int) $city_index; ?>][city]"
                                                   value="<?php echo esc_attr( $city ); ?>">
                                            <input type="hidden"
                                                   name="_ll_svc_city_size_prices[<?php echo (int) $city_index; ?>][sizes][<?php echo (int) $size_index; ?>][size]"
                                                   value="<?php echo esc_attr( $size ); ?>">
                                        </td>
                                        <td>
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                class="small-text"
                                                name="_ll_svc_city_size_prices[<?php echo (int) $city_index; ?>][sizes][<?php echo (int) $size_index; ?>][price]"
                                                value="<?php echo esc_attr( $val ); ?>"
                                                placeholder="e.g. 199">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ll-svc-section">
                <h4>Allowed Cities</h4>
                <p class="description">
                    Choose which cities can book this service. Leave all unchecked to allow <strong>any</strong> city.
                </p>
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
                <p class="description">Leave all unchecked to allow <strong>any</strong> city on the booking form.</p>
                <?php endif; ?>
            </div>

            <div class="ll-svc-section">
                <h4>Allowed Service Areas / Addresses</h4>
                <p class="description">
                    Choose a predefined area from the dropdown or type a custom address. Matching on the booking form is partial (contains).
                    <?php if ( empty( $global_addresses ) ) : ?>
                    Add predefined options under <a href="<?php echo esc_url( admin_url( 'admin.php?page=ll-scheduler-settings&tab=general' ) ); ?>">Settings → General</a>.
                    <?php endif; ?>
                </p>

                <div class="ll-svc-address-toolbar">
                    <?php if ( ! empty( $global_addresses ) ) : ?>
                    <select id="llSvcAddressPreset" class="regular-text">
                        <option value="">— Select predefined —</option>
                        <?php foreach ( $global_addresses as $address ) : ?>
                        <option value="<?php echo esc_attr( $address ); ?>"><?php echo esc_html( $address ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" id="llSvcAddPresetAddress">Add Selected</button>
                    <?php endif; ?>
                    <input type="text"
                           id="llSvcAddressCustom"
                           class="regular-text"
                           placeholder="Or type custom address / area">
                    <button type="button" class="button button-secondary" id="llSvcAddCustomAddress">Add Custom</button>
                </div>

                <div id="llSvcAddressTags" class="ll-svc-address-tags">
                    <?php foreach ( $allowed_addresses as $addr ) : ?>
                    <span class="ll-svc-address-tag">
                        <input type="hidden" name="_ll_svc_addresses[]" value="<?php echo esc_attr( $addr ); ?>">
                        <span class="ll-svc-address-tag-text"><?php echo esc_html( $addr ); ?></span>
                        <button type="button" class="ll-svc-address-rm" aria-label="<?php esc_attr_e( 'Remove', 'll-service-scheduler' ); ?>">&times;</button>
                    </span>
                    <?php endforeach; ?>
                </div>
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
        $addresses = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['_ll_svc_addresses'] ?? array() ) ) ) ) );
        update_post_meta( $post_id, '_ll_svc_property_sizes', $sizes );
        update_post_meta( $post_id, '_ll_svc_cities', $cities );
        update_post_meta( $post_id, '_ll_svc_addresses', $addresses );

        $card_media_id = absint( $_POST['_ll_svc_card_media_id'] ?? 0 );
        if ( $card_media_id ) {
            $mime = (string) get_post_mime_type( $card_media_id );
            if ( $mime && ( strpos( $mime, 'image/' ) === 0 || strpos( $mime, 'video/' ) === 0 ) ) {
                update_post_meta( $post_id, '_ll_svc_card_media_id', $card_media_id );
            } else {
                delete_post_meta( $post_id, '_ll_svc_card_media_id' );
            }
        } else {
            delete_post_meta( $post_id, '_ll_svc_card_media_id' );
        }

        // Size-based pricing (optional)
        $raw_size_prices = (array) ( $_POST['_ll_svc_size_prices'] ?? array() );
        $clean_map       = array();
        foreach ( $raw_size_prices as $row ) {
            $size  = sanitize_text_field( $row['size'] ?? '' );
            $price = sanitize_text_field( $row['price'] ?? '' );
            if ( $size === '' || $price === '' ) {
                continue;
            }
            $num = (float) $price;
            if ( $num <= 0 ) {
                continue;
            }
            $clean_map[ $size ] = $num;
        }
        update_post_meta( $post_id, '_ll_svc_size_prices', $clean_map );

        // City × size pricing (optional)
        $raw_city_prices = (array) ( $_POST['_ll_svc_city_size_prices'] ?? array() );
        $clean_city_map  = array();
        foreach ( $raw_city_prices as $row ) {
            $city = sanitize_text_field( $row['city'] ?? '' );
            if ( $city === '' ) {
                continue;
            }
            $size_map = array();
            foreach ( (array) ( $row['sizes'] ?? array() ) as $size_row ) {
                $size  = sanitize_text_field( $size_row['size'] ?? '' );
                $price = sanitize_text_field( $size_row['price'] ?? '' );
                if ( $size === '' || $price === '' ) {
                    continue;
                }
                $num = (float) $price;
                if ( $num <= 0 ) {
                    continue;
                }
                $size_map[ $size ] = $num;
            }
            if ( ! empty( $size_map ) ) {
                $clean_city_map[ $city ] = $size_map;
            }
        }
        update_post_meta( $post_id, '_ll_svc_city_size_prices', $clean_city_map );

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
