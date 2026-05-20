# RideFleet Booking Development Plan

RideFleet Booking is being built as a monetization-ready WordPress plugin for chauffeur, taxi, shuttle, airport transfer, and private driver businesses.

## Product Direction

- Independent WordPress booking system first.
- Optional WooCommerce checkout integration, not a hard dependency.
- Google Maps only for autocomplete, route preview, and distance/duration calculation.
- Polished admin dashboard for bookings, customers, revenue, quote analytics, and fleet operations.
- Clean module boundaries so the plugin can later become Free/Pro or Core/Add-ons.

## Phase 1: Foundation

- Plugin bootstrap, autoloading, activation hooks, and text domain.
- Database tables for bookings, customers, booking metadata, and quote events.
- Admin dashboard shell with homepage-style metrics.
- Customer database page for recurring clients.
- Settings page for currency, distance unit, Google Maps, WooCommerce, and fare details.
- WordPress content types for vehicles, drivers, extras, routes, locations, and booking forms.
- REST API foundation for public settings and quote calculation.
- Frontend shortcode placeholder.

## Phase 2: Booking Flow

- Interactive booking form UI. Done.
- Google Places autocomplete for pickup and drop-off. Done.
- Embedded route map with route polyline. Done.
- Backend-verified distance quote. Done.
- Vehicle list filtered by capacity. Done.
- Extras selection. Done.
- Customer details step. Done.
- Booking creation. Done.
- Availability filtering. Phase 4.
- Waypoints. Phase 5.

## Phase 3: WooCommerce Checkout

- Convert a RideFleet booking into a WooCommerce checkout item. Done.
- Store booking ID and quote snapshot on cart/order item metadata. Done.
- Sync WooCommerce order status back to RideFleet booking status. Done.
- Link RideFleet booking detail to WooCommerce order detail. Done.
- Preserve native RideFleet checkout fallback when WooCommerce is disabled. Done.

## Phase 4: Operations

- Booking detail screens. Done.
- Availability blackout rules. Done.
- Calendar view. Basic availability foundation done; visual calendar pending.
- Driver assignment.
- Customer history and lifetime spend.
- Admin notes and status changes.
- Email notification templates. Basic emails done; editable templates pending.

## Phase 5: Advanced Revenue Features

- Pricing rule builder.
- Availability rule builder.
- Coupons.
- Hourly service.
- Flat-rate routes.
- Return trips.
- Geofence zones.
- Dashboard analytics for quote-to-booking conversion.
