=== RideFleet Booking ===
Contributors: ridefleet
Tags: booking, taxi, chauffeur, woocommerce, google maps
Requires at least: 6.3
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later

RideFleet Booking is a monetization-ready WordPress booking system for chauffeur, taxi, shuttle, airport transfer, and private driver businesses.

== Phase 1 Scope ==

* Plugin bootstrap and autoloading.
* Custom database tables for bookings, customers, booking metadata, and quote analytics.
* Admin dashboard shell with operational metrics.
* Customer database page for repeat clients.
* WordPress content types for vehicles, drivers, extras, routes, locations, and booking forms.
* Settings for currency, distance unit, fare details, Google Maps, and WooCommerce checkout.
* REST API foundation for public settings and basic quote calculation.
* Frontend shortcode placeholder: [ridefleet_booking_form id="1"].

== Phase 2 Scope ==

* Frontend booking form UI.
* Google Places autocomplete integration when a Google Maps API key is configured.
* Embedded Google route preview using Directions.
* Backend quote calculation with fare settings, selected extras, and vehicle price adjustment.
* Vehicle loading with passenger/luggage filtering.
* Extras loading with operator-managed pricing.
* Pending booking creation.
* Customer database creation/update for recurring clients.
* Quote event tracking for future analytics.

== Phase 3 Scope ==

* WooCommerce checkout handoff using a hidden virtual product.
* WooCommerce order status sync back to RideFleet booking status.
* Booking detail page with status/payment workflow.
* Basic ride calendar.
* Coupon system with fixed or percentage discounts.
* Pricing rules for time windows such as night surcharge.
* Availability blackout rules.
* Customer/admin booking notification emails.
* Optional extras toggle for items such as child seats.
* Form color settings.
* Translation template foundation in /languages.
* Route cache database foundation so generated pages can reuse stored route data instead of calling Google Maps on every page view.

== Phase 4 Scope ==

* Multi-step frontend booking flow.
* Quick date strip and time-slot picker.
* Route page generator for SEO landing pages.
* Route cache admin screen and cache clearing.
* Editable booking email templates.
* Visible trip distance/duration boxes under the estimated total.
* Browser-side route cache for repeated same-device route searches.
* Server-side route cache REST endpoint.
* Route metadata fields for origin, destination, fixed price, sample distance/duration, and FAQ notes.
* Booking filters and driver assignment.
* Customer profile pages.
* Settings test email tool and WooCommerce readiness checklist.

== Phase 6 Scope ==

* Generated route pages are published with customer-facing copy.
* Route page booking forms are prefilled with origin and destination.
* Route page shortcodes pass route ID into the quote flow.
* Fixed route pricing is applied when a saved route is used.

== Phase 7/8 Scope ==

* Large map canvas with floating booking command panel on desktop.
* Internal booking notes and lightweight activity timeline.
* Setup checklist admin page.
* Demo data cleanup/reset tool.
* Async Google Maps loading.
* Frontend review step before final reservation.
* Promo code apply button.
* More readable success/error messages.

== Phase 9 Scope ==

* REST rate limiting for quote, vehicle, extras, booking, and route-cache endpoints.
* Stronger server-side booking validation before availability, quote, and WooCommerce handoff.
* Diagnostics admin screen for system, Google Maps, database, and REST protection checks.
* Google Routes API JavaScript flow using `Route.computeRoutes` before falling back to legacy Directions.
* Google Place Autocomplete Element support for blank pickup/drop-off fields, with legacy fallback only when needed.
* Updated versioned assets for production QA.

== Uploading to WordPress ==

1. Zip the full `ridefleet-booking` folder so the zip contains `ridefleet-booking/ridefleet-booking.php` at the top level.
2. In WordPress Admin, go to Plugins > Add New Plugin > Upload Plugin.
3. Choose `ridefleet-booking.zip`.
4. Click Install Now.
5. Click Activate Plugin.
6. Go to RideFleet > Settings and configure currency, distance unit, fare details, Google Maps API key, and WooCommerce preference.
7. Add at least one Vehicle under RideFleet > Vehicles. Set passenger/luggage capacity in the Vehicle Booking Details box.
8. Add optional Extras under RideFleet > Extras and set their prices.
9. Add this shortcode to a page: `[ridefleet_booking_form id="1"]`.
10. For quick testing, go to RideFleet > Dashboard and click Seed Demo Data. This creates demo vehicles, extras, a coupon, a night surcharge, and one sample booking.

For Google Maps autocomplete and route preview, the API key needs access to the Maps JavaScript API, Places API, and Directions API.
Do not hardcode Google Maps API keys in plugin files. Each client should add their own key in RideFleet > Settings.
If Google Maps is not configured yet, the frontend form shows manual distance/duration fields so you can still test booking creation.

== Free Email Marketing Option ==

RideFleet keeps a customer database that can later be connected to a WordPress-native mailing plugin. For a free/self-hosted starting point, consider FluentCRM. For a simpler newsletter-style tool, consider MailPoet. Email deliverability still depends on the sending method configured on the WordPress site.

== Roadmap ==

Next phases add route page generation, fixed route packages, stronger route cache controls, driver calendars, review follow-up automation, and deeper analytics.
