# Core Booking & Geofencing Rules

## Settings Schema

Stored in the existing `rfb_settings` option under `core_booking_rules`:

```json
{
	"global_service_area": {
		"enabled": true,
		"type": "radius",
		"data": "{\"center\":{\"lat\":44.4759,\"lng\":-73.2121},\"radius\":25}",
		"error_message": "This ride is outside our service area."
	},
	"flat_rates": [
		{
			"id": 1,
			"name": "Airport to Downtown",
			"hub_coordinates": { "lat": 44.4719, "lng": -73.1533 },
			"zone_type": "radius",
			"zone_data": "{\"center\":{\"lat\":44.4759,\"lng\":-73.2121},\"radius\":5}",
			"price": 45
		}
	],
	"priority_rule": "flat_rate_only",
	"abuse_protection_enabled": true,
	"abuse_protection_type": "force_flat_rate",
	"buffer_zone_enabled": true,
	"buffer_zone_distance": 500,
	"buffer_zone_fee_type": "flat_plus_fee",
	"buffer_zone_penalty_value": 10,
	"chatbot_api_key": "shared-secret"
}
```

## Execution Matrix

Implemented in `RideFleetBooking\Booking\CoreBookingPricingEngine::process_taxi_booking()`.

1. Validate pickup and drop-off against `global_service_area`.
2. Match pickup against a configured hub and drop-off against the corresponding flat-rate zone.
3. Resolve conflicts with `flat_rate_only`, `cheapest`, or `expensive`.
4. If no direct zone match exists, inspect the nearest configured zone border for buffer pricing.
5. Fall back to standard meter pricing from the existing RideFleet fare settings.
6. If abuse protection is enabled, prevent just-outside-zone fares from undercutting the adjacent flat rate.

## Chatbot REST Contract

### `GET /wp-json/taxi-booking/v1/calculate-price`

Read-only. Accepts:

- `pickup_address`
- `dropoff_address`
- or `pickup_lat`, `pickup_lng`, `dropoff_lat`, `dropoff_lng`

Returns:

- `final_price`
- `standard_price`
- `zone_name`
- `zone_classification_name`
- `pricing_source`
- `currency`
- service-area error flags when rejected

### `POST /wp-json/taxi-booking/v1/create-booking`

Write-only for booking records. Accepts:

- `pickup_address`
- `dropoff_address`
- `customer_name`
- `customer_phone`
- `pickup_time`
- `final_price`

The endpoint recalculates the fare server-side and rejects the booking if `final_price` does not match the verified engine price. It does not expose or mutate settings, geofences, flat rates, matrices, or admin configuration.

If `chatbot_api_key` is configured, both chatbot endpoints require the `X-RideFleet-Chatbot-Key` header.

## Admin UI

The screen is registered at:

`RideFleet > Core Rules`

Title:

`Core Booking & Geofencing Rules`

Sections:

- Global Service Area
- Flat Rate Zone Management
- Priority Resolution
- Buffer and Abuse Protection
- Chatbot API Contract
