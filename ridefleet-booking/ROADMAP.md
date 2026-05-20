# RideFleet Booking Roadmap

RideFleet Booking is a production-ready taxi/chauffeur booking system for WordPress with full admin operations, fleet management, and monetization-ready features.

## Current Version: 1.0.0

**Overview of v1.1:**
- Hourly service pricing with dynamic rate calculations.
- Flat-rate routes per route basis with multiplier support.
- Availability calendar UI for drivers and vehicles.
- Customer lifetime spend and booking history tracking.
- Review request email after completed ride.
- Geofence zones for service areas with pricing multipliers.
- Admin menu enhancements with new operations screens.
- All v1.0 core booking features intact.

## Version 1.0: Core Booking System (Stable)

**Overview of v1.0:**
- Multi-step ride booking flow with final review step.
- Google Places and Directions frontend integration with proper UI layering.
- Async Google Maps script loading.
- Manual route fallback for testing.
- Vehicle, extras, customer, booking, coupon, availability, and pricing-rule foundations.
- WooCommerce checkout handoff and order status sync.
- Admin dashboard with analytics foundation.
- Route page generator foundation.
- Route cache admin foundation.
- Editable booking email templates.
- Promo code apply UX.
- Browser compatibility polyfill for older `crypto.randomUUID` support.
- REST endpoint rate limiting.
- Server-side booking validation.
- Diagnostics admin screen.
- Google Routes API support before legacy Directions fallback.
- Google Place Autocomplete Element support for blank booking fields.
- **Fixed: Map z-index layering** - Form now properly displays above map on all screen sizes.
- Quote abandonment tracking.
- Coupon performance reports.
- Booking edit screen for all fields (pickup, drop-off, route, customer, price, driver, vehicle).
- Optional deposit/full payment mode.
- Admin notes and internal activity log enhancement.

## Version 1.2: Growth & Marketing

Goal: SEO, lead capture, and repeat customer management.

- Route page generator v2 with SEO schema.
- FAQ builder for route/service pages.
- TaxiService and LocalBusiness JSON-LD settings.
- Service area manager.
- Saved popular destinations.
- Airport transfer templates.
- Repeat customer segmentation.
- FluentCRM/MailPoet export/integration foundation.

## Version 1.3: Advanced Dispatch

Goal: visual dispatch and day-to-day ride management.

- Full dispatch calendar with drag/drop.
- Drag/drop driver assignment.
- Vehicle availability calendar UI.
- Driver availability calendar UI.
- Bulk import/export for vehicles/routes/customers.
- Dashboard charts enhancement for revenue, conversions, top routes.

## Future Versions

- Performance optimization for large booking/customer tables.
- Automated testing suite.
- Advanced security audit and role system.
- FluentCRM/MailPoet full integration.
- Mobile driver app.

## Version 1.0: Sellable Release

Goal: a stable commercial product.

- Complete booking flow with Google Maps, route caching, vehicles, extras, coupons, pricing rules, availability, customers, and WooCommerce.
- Polished admin dashboard and dispatch calendar.
- Route page generator with schema and SEO-ready content blocks.
- Customer CRM with booking history and review follow-up.
- WooCommerce checkout/payment sync.
- Email templates and notification workflows.
- Setup wizard, demo data, and documentation.
- Translation-ready strings and initial language template.
- Clear free/pro architecture for future monetization.

## API Cost Control Strategy

Google Maps can become expensive if every page view triggers route calculations. RideFleet should use several layers:

1. **Do not calculate routes on page load.** Only calculate after the user enters both locations or clicks search.
2. **Browser cache.** Store recent route distance/duration in localStorage for repeated searches on the same device.
3. **Server route cache.** Store origin/destination hash, distance, duration, polyline, and expiry in `rfb_route_cache`.
4. **Generated route pages use cache only.** SEO pages should show stored route facts and should not call Google on every visitor page view.
5. **Admin refresh button.** Let the owner refresh cached route facts manually.
6. **TTL settings.** Allow route cache to expire after a configured period.
7. **Separate browser key restrictions.** Restrict Google browser keys by domain and enabled APIs.
8. **Optional server key.** Later add a server-side key for controlled Directions/Routes API calls.

The key principle: route lookup should be an intentional event, not a passive page-load cost.
