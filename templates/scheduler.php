<?php
/**
 * Template: Schedule Service shortcode output
 * Variables available from LL_Sched_Frontend::shortcode():
 *   $sizes     - array of property size strings
 *   $cities    - array of city strings
 *   $addresses - array of service area / address strings
 *   $cats   - WP_Term[] service categories (or WP_Error)
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="ll-wrap" id="llScheduler">

    <!-- ══════════════════════════
         PAGE TITLE
    ══════════════════════════ -->
    <h1 class="ll-page-title">Book a Service</h1>

    <!-- ══════════════════════════
         FILTERS: Property Size / City / Address
    ══════════════════════════ -->
    <div class="ll-filters">

        <div class="ll-field">
            <label for="llPropSize">Property Size (Sq. Ft)</label>
            <select id="llPropSize">
                <option value="">&#8212; Select &#8212;</option>
                <?php foreach ( $sizes as $s ) : ?>
                <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ll-field">
            <label for="llCity">Select City</label>
            <select id="llCity">
                <option value="">&#8212; Select &#8212;</option>
                <?php foreach ( $cities as $c ) : ?>
                <option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="ll-field">
            <label for="llAddress">Service Area / Address</label>
            <select id="llAddress">
                <option value="">&#8212; Select &#8212;</option>
                <?php foreach ( $addresses as $a ) : ?>
                <option value="<?php echo esc_attr( $a ); ?>"><?php echo esc_html( $a ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

    </div><!-- .ll-filters -->

    <!-- ══════════════════════════
         SERVICES (grouped by category)
    ══════════════════════════ -->
    <?php if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) : ?>

        <?php foreach ( $cats as $cat ) :

            $services = get_posts( array(
                'post_type'      => 'services',
                'posts_per_page' => -1,
                'tax_query'      => array(
                    array(
                        'taxonomy' => 'service-category',
                        'field'    => 'term_id',
                        'terms'    => $cat->term_id,
                    ),
                ),
                'orderby'        => 'menu_order',
                'order'          => 'ASC',
            ) );

            if ( empty( $services ) ) continue;
        ?>

        <div class="ll-cat-group">
            <h3 class="ll-cat-title"><?php echo esc_html( $cat->name ); ?></h3>
            <div class="ll-services-grid">

                <?php foreach ( $services as $svc ) :
                    $price          = get_post_meta( $svc->ID, 'price', true );
                    $img            = get_the_post_thumbnail_url( $svc->ID, 'medium' );
                    $svc_sizes      = array_values( (array) get_post_meta( $svc->ID, '_ll_svc_property_sizes', true ) );
                    $svc_cities     = array_values( (array) get_post_meta( $svc->ID, '_ll_svc_cities', true ) );
                    $svc_addresses  = array_values( array_filter( (array) get_post_meta( $svc->ID, '_ll_svc_addresses', true ) ) );
                ?>

                <label class="ll-svc-item"
                       data-id="<?php echo $svc->ID; ?>"
                       data-price="<?php echo esc_attr( floatval( $price ) ); ?>"
                       data-title="<?php echo esc_attr( $svc->post_title ); ?>"
                       data-sizes="<?php echo esc_attr( wp_json_encode( $svc_sizes ) ); ?>"
                       data-cities="<?php echo esc_attr( wp_json_encode( $svc_cities ) ); ?>"
                       data-addresses="<?php echo esc_attr( wp_json_encode( $svc_addresses ) ); ?>">

                    <div class="ll-svc-left">
                        <input type="checkbox"
                               class="ll-svc-cb"
                               value="<?php echo $svc->ID; ?>">
                        <span class="ll-svc-info">
                            <span class="ll-svc-name"><?php echo esc_html( $svc->post_title ); ?></span>
                            <?php if ( $price ) : ?>
                            <span class="ll-svc-price">From: $<?php echo number_format( floatval( $price ) ); ?></span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="ll-svc-right">
                        <?php if ( $img ) : ?>
                        <img src="<?php echo esc_url( $img ); ?>"
                             alt="<?php echo esc_attr( $svc->post_title ); ?>">
                        <?php else : ?>
                        <div class="ll-no-img"></div>
                        <?php endif; ?>
                    </div>

                </label>

                <?php endforeach; // services ?>
            </div><!-- .ll-services-grid -->
        </div><!-- .ll-cat-group -->

        <?php endforeach; // cats ?>

    <?php else : ?>
    <p class="ll-no-services">No services are available at the moment. Please check back later.</p>
    <?php endif; ?>

    <!-- ══════════════════════════
         BOOKING SECTION
         Hidden until at least one service is checked.
         JS toggles the `hidden` attribute.
    ══════════════════════════ -->
    <div class="ll-booking" id="llBooking" hidden>

        <h2 class="ll-section-title">Preferred Date &amp; Time</h2>

        <!-- ── Calendar ── -->
        <div class="ll-calendar-wrap">
            <div class="ll-cal-header">
                <button type="button" id="llCalPrev" aria-label="Previous month">&#8592;</button>
                <span id="llCalTitle"></span>
                <button type="button" id="llCalNext" aria-label="Next month">&#8594;</button>
            </div>
            <div class="ll-cal-weekdays" aria-hidden="true">
                <span>Mon</span>
                <span>Tue</span>
                <span>Wed</span>
                <span>Thu</span>
                <span>Fri</span>
                <span>Sat</span>
                <span>Sun</span>
            </div>
            <div class="ll-cal-grid" id="llCalGrid" role="grid"></div>
        </div><!-- .ll-calendar-wrap -->

        <!-- ── Time Slots (revealed after date click) ── -->
        <div class="ll-times" id="llTimes" hidden>
            <h3 class="ll-section-subtitle">Select a Time Slot</h3>
            <div class="ll-times-grid" id="llTimesGrid"></div>
        </div>

        <!-- ── Additional Notes ── -->
        <div class="ll-field ll-notes-field">
            <label for="llNotes">Additional Notes</label>
            <textarea id="llNotes" rows="4" placeholder="Any special instructions or requests..."></textarea>
        </div>

        <!-- ── Order Summary ── -->
        <div class="ll-summary" id="llSummary">
            <div class="ll-summary-header">Order Summary</div>
            <div class="ll-summary-items" id="llSummaryItems">
                <!-- Populated by JS -->
            </div>
            <div class="ll-summary-total">
                Total: <strong>$<span id="llTotal">0.00</span></strong>
            </div>
        </div>

        <!-- ── Submit Button ── -->
        <button type="button" class="ll-btn-submit" id="llSubmit">
            Add to Cart &#8594;
        </button>

        <!-- ── Status Message ── -->
        <div class="ll-msg" id="llMsg" role="alert"></div>

    </div><!-- #llBooking -->

</div><!-- #llScheduler -->
