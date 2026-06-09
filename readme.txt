=== LL Service Scheduler ===
Plugin Name: LL Service Scheduler
Version: 1.0.0
Requires WordPress: 5.8+
Requires PHP: 7.4+
Requires: WooCommerce (active)

== DESCRIPTION ==

Custom multi-service booking page with:
- Property size / city / address filters
- Services grouped by category with checkboxes
- Inline calendar (admin-controlled available days)
- Time slot picker (admin-managed)
- WooCommerce checkout integration
- Jet Appointments auto-creation after payment

== SHORTCODE ==

Place   [ll_schedule_service]   on any page.

== ADMIN SETTINGS ==

Settings → Service Scheduler  (three tabs)

Tab: General
  - Selection Mode (single / multiple services)
  - Property Sizes (add / remove dropdown options)
  - Cities (add / remove dropdown options)

Tab: Calendar & Days
  - Tick which days of the week are open for booking
  - Default: Saturday + Sunday

Tab: Time Slots
  - Add / edit / remove time slots shown after date selection

== FLOW ==

1. User visits the Schedule Service page
2. Selects property size, city, address (optional but available)
3. Checks one or more services
4. Booking section slides into view
5. User clicks a date on the calendar (only available days are clickable)
6. User selects a time slot
7. Optionally adds notes
8. Clicks "Add to Cart"
9. AJAX adds a virtual WooCommerce product to the cart (price = sum of selected services)
10. User is redirected to WooCommerce checkout
11. User completes payment
12. Plugin hooks woocommerce_payment_complete → inserts record into Jet APB appointments table

== PRICE META KEY ==

The plugin reads  get_post_meta($id, 'price', true)  for each service post.
This matches the existing JetEngine/JetFormBuilder setup on the site.

== INSTALLATION ==

See the step-by-step guide in INSTALL_GUIDE.txt
