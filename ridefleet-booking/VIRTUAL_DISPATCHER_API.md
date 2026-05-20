# RideFleet Virtual Dispatcher API

Version: RideFleet Booking `1.0.25+`  
Audience: external virtual dispatcher developers, phone AI dispatcher builders, voice-agent integrators, and backend engineers.

This document describes the secure REST API surface that allows an external virtual dispatcher to quote rides, create phone bookings, look up bookings, and submit booking change requests into RideFleet Booking.

The dispatcher API is intentionally narrow. It is designed to let a phone dispatcher behave like another booking channel, not like an administrator.

## Core Principle

The virtual dispatcher may:

- Request a verified RideFleet quote.
- Create a booking after a caller confirms the quote.
- Look up an existing booking by booking number or customer phone.
- Submit a change request for admin review.

The virtual dispatcher must not:

- Modify RideFleet settings.
- Modify pricing rules.
- Modify service areas or geofences.
- Modify flat rates.
- Modify vehicles.
- Modify drivers.
- Modify extras.
- Modify coupons.
- Delete bookings.
- Mark bookings as paid.
- Assign drivers.
- Override fares silently.

Payments are intentionally out of scope for this API. In this product flow, payment normally happens after the ride is finished.

## Base URL

All endpoints are WordPress REST API endpoints under the RideFleet namespace:

```text
https://example.com/wp-json/ridefleet/v1
```

For local development, this may look like:

```text
http://taxi.local/wp-json/ridefleet/v1
```

## Enable The API

In WordPress admin:

```text
RideFleet > Settings > Virtual Dispatcher API
```

Enable:

```text
Enable virtual dispatcher REST API
```

Then set or generate:

```text
Dispatcher API Key
```

If the API is enabled and the key field is empty, RideFleet auto-generates a key like:

```text
rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Keep this key private.

## Authentication

All dispatcher endpoints require an API key.

Preferred header:

```http
Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Fallback header also supported:

```http
X-RideFleet-Dispatcher-Key: rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Use HTTPS in production. Never expose this key in a browser, mobile app, public JavaScript bundle, or prompt-visible client-side code.

## Content Type

Use JSON request bodies:

```http
Content-Type: application/json
Accept: application/json
```

## Rate Limits

RideFleet applies rate limiting at the REST layer.

Current dispatcher limits:

- Quote: `80` requests per minute.
- Create booking: `30` attempts per `10` minutes.

If limited, the API responds with:

```http
429 Too Many Requests
```

Example:

```json
{
  "message": "Too many dispatcher quote requests. Please wait a moment and try again."
}
```

## Booking Lifecycle For Phone Dispatch

Recommended phone flow:

1. Caller gives pickup location.
2. Caller gives destination.
3. Dispatcher collects passenger count and luggage count.
4. Dispatcher optionally collects extras and vehicle preference.
5. Dispatcher calls `POST /dispatcher/quote`.
6. Dispatcher reads the quote aloud to the caller.
7. Caller confirms.
8. Dispatcher collects name, phone, pickup time, optional email, and any note.
9. Dispatcher calls `POST /dispatcher/bookings`.
10. Dispatcher gives the returned booking number to the caller.

Important:

- A quote is not a booking.
- A booking should only be created after the caller agrees.
- If `requires_approval` is true, the dispatcher should tell the caller the ride was requested and must be reviewed by dispatch.
- If `no_vehicle_available` is returned, the dispatcher should not accept the ride automatically.

## Required Dispatcher Prompt Rules

The virtual dispatcher system prompt must include these operational rules:

```text
You are a phone dispatcher for a taxi/private transfer company using RideFleet.

You must always call RideFleet for a quote before giving a fare.

If RideFleet returns outside_service_area, politely tell the caller the requested ride is outside the company's service area and do not continue collecting booking details.

If RideFleet returns a successful quote with requires_approval=true, explain that one side of the trip is outside the normal service area. You may still submit the ride request if the caller agrees, but you must clearly say that the booking is not fully confirmed until a human dispatcher/admin reviews it. The fare may be adjusted and dispatch may contact the caller.

If RideFleet creates a booking with status pending_payment or requires_approval behavior, tell the caller: "I have sent the ride request to dispatch for review. Your booking/request number is [booking_number]. The original request is not final until the taxi company confirms it."

Never promise that an approval-required ride is final.
Never override a fare.
Never modify service areas, pricing, vehicles, drivers, coupons, or settings.
```

The most important distinction:

- `outside_service_area`: stop the booking flow.
- `requires_approval: true`: the dispatcher may submit the booking request, but must say it needs human confirmation.

## Service Area Behavior

RideFleet has two related service-area concepts:

- Core global service area rules.
- Operational geofence zones.

The dispatcher API uses the same geofencing/pricing engine as the booking form and chatbot.

Possible outcomes:

- Both pickup and destination are inside normal service area:
  - Quote succeeds normally.
  - Booking can be created and usually becomes `confirmed`.
  - Dispatcher wording should treat this as a normal booking.

- One side is outside the normal service area:
  - Quote can succeed.
  - `requires_approval` should be treated as true when returned.
  - Booking can be created, but is flagged for admin review.
  - The booking may be returned as `pending_payment` / `unpaid` instead of `confirmed`.
  - The dispatcher must tell the caller that a human dispatcher/admin must confirm the trip.
  - The dispatcher must tell the caller the fare may be adjusted after review.
  - The dispatcher must not say "your ride is confirmed" for this case.

- Both sides are outside the service area:
  - Quote is blocked.
  - Booking should not be created.
  - API returns `outside_service_area`.
  - The dispatcher should read or paraphrase the RideFleet error message and stop the ride-booking flow.

Example blocked response:

```json
{
  "success": false,
  "error": true,
  "code": "outside_service_area",
  "message": "This ride is outside our service area.",
  "final_price": null,
  "currency": "USD"
}
```

Example one-side-outside quote response:

```json
{
  "success": true,
  "quote_id": "97265ed1-cef8-4569-9c0a-694321cc21ee",
  "currency": "USD",
  "final_price": 263.02,
  "base_price": 263.02,
  "pricing_source": "standard",
  "zone_name": "",
  "distance_km": 119.011,
  "duration_minutes": 159,
  "requires_approval": true,
  "approval_message": "This trip can be requested, but dispatch should review it because part of the route is outside the normal operating area.",
  "vehicle": {
    "id": 50,
    "name": "Executive Sedan",
    "passengers": 3,
    "luggage": 2
  }
}
```

For that response, the dispatcher should say something like:

```text
This route can be sent to dispatch, but because part of it is outside the normal service area, it needs human review before it is final. The quoted fare is USD 263.02 and it may be adjusted after review. Would you like me to send the request to dispatch?
```

Example one-side-outside booking response:

```json
{
  "success": true,
  "booking": {
    "booking_number": "RFB-20260515-NZ53KY",
    "status": "pending_payment",
    "payment_status": "unpaid",
    "source": "virtual_dispatcher",
    "pickup_address": "BTV Airport",
    "dropoff_address": "Montreal Canada",
    "total": 263.02,
    "currency": "USD"
  },
  "message": "Booking created from virtual dispatcher API."
}
```

For that response, the dispatcher should say:

```text
I have sent the ride request to dispatch for review. Your request number is RFB-20260515-NZ53KY. The taxi company still needs to confirm this trip, and they may contact you if the route or fare needs adjustment.
```

## Vehicle Capacity Behavior

RideFleet checks configured vehicles against passenger and luggage count.

If no configured vehicle can handle the request, the API returns:

```http
409 Conflict
```

Example:

```json
{
  "success": false,
  "code": "no_vehicle_available",
  "message": "No configured vehicle can handle this passenger/luggage count. The dispatcher should contact the business owner before accepting the ride.",
  "passengers": 7,
  "luggage": 5
}
```

The dispatcher should not invent a vehicle, split the ride, or force the booking unless the business explicitly creates a workflow for that later.

## Fare Behavior

The dispatcher API uses the RideFleet core quote engine.

Pricing may include:

- Standard distance estimate.
- Flat rates.
- Flat-rate priority rules.
- Buffer-zone surcharge rules.
- Edge-of-zone abuse protection.
- Vehicle price adjustment.
- Extras.
- Service-area approval flags.

The dispatcher should never hallucinate, negotiate, or override price.

If the caller negotiates, the virtual dispatcher should submit that as a human approval workflow later, not as a final booking fare. The current dispatcher API does not include a negotiation endpoint.

## Supported Endpoints

### 1. Quote Ride

```http
POST /wp-json/ridefleet/v1/dispatcher/quote
```

Purpose:

Return a verified quote for a phone dispatcher before creating a booking.

Authentication:

Required.

Rate limit:

`80` requests per minute.

#### Request Body

```json
{
  "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
  "dropoffAddress": "Brussels Airport, Zaventem, Belgium",
  "passengers": 2,
  "luggage": 1,
  "pickupTime": "2026-05-16 09:00",
  "extras": [
    {
      "id": 123,
      "quantity": 1
    }
  ],
  "vehicleId": 456
}
```

#### Request Fields

| Field | Type | Required | Notes |
|---|---:|---:|---|
| `pickupAddress` | string | Yes, unless coordinates are provided | Natural address/place text. Also accepts `pickup_address`. |
| `dropoffAddress` | string | Yes, unless coordinates are provided | Natural address/place text. Also accepts `dropoff_address`. |
| `pickup_lat` | number | Optional | Raw pickup latitude. |
| `pickup_lng` | number | Optional | Raw pickup longitude. |
| `dropoff_lat` | number | Optional | Raw drop-off latitude. |
| `dropoff_lng` | number | Optional | Raw drop-off longitude. |
| `passengers` | integer | Optional | Defaults to `1`. Minimum `1`. |
| `luggage` | integer | Optional | Defaults to `0`. Minimum `0`. |
| `pickupTime` | string | Optional for quote | Human-readable or ISO-like time, e.g. `2026-05-16 09:00`. |
| `pickupDate` | string | Optional | Alternative split date, e.g. `2026-05-16`. |
| `pickupClock` | string | Optional | Alternative split time, e.g. `09:00`. |
| `vehicleId` | integer | Optional | If omitted, RideFleet selects the first compatible configured vehicle. Also accepts `vehicle_id`. |
| `extras` | array | Optional | List of extra IDs and quantities. |
| `transferType` | string | Optional | Defaults to `one_way`. |

If both address and coordinates are provided, the core pricing engine may use coordinates for location evaluation while preserving the addresses in the request context.

#### Successful Response

```http
200 OK
```

```json
{
  "success": true,
  "quote_id": "1ea0bc80-a7a9-4c60-bf6a-91d70d7504dd",
  "currency": "USD",
  "final_price": 108.17,
  "base_price": 103.17,
  "addons": {
    "vehicle_adjustment": 0,
    "extras_total": 5
  },
  "pricing_source": "standard",
  "zone_name": "",
  "distance_km": 44.281,
  "duration_minutes": 59,
  "requires_approval": false,
  "approval_message": "",
  "vehicle": {
    "id": 456,
    "name": "Standard Sedan",
    "passengers": 4,
    "luggage": 2
  },
  "compatible_vehicles": [
    {
      "id": 456,
      "name": "Standard Sedan",
      "passengers": 4,
      "luggage": 2,
      "priceAdjustment": 0
    }
  ],
  "notice": "This is a ride quote. The dispatcher can create the booking after the caller confirms."
}
```

#### Response Fields

| Field | Type | Meaning |
|---|---:|---|
| `success` | boolean | Whether quote succeeded. |
| `quote_id` | string | Generated UUID for client-side tracking. It is not currently required to create a booking. |
| `currency` | string | RideFleet configured currency. |
| `final_price` | number | Total quoted fare including vehicle adjustment and extras. |
| `base_price` | number | Fare from the core pricing engine before add-ons. |
| `addons.vehicle_adjustment` | number | Additional vehicle price adjustment. |
| `addons.extras_total` | number | Total price of requested extras. |
| `pricing_source` | string | Source of price, e.g. `standard`, `flat_rate`, `buffer_zone`, `abuse_force_flat_rate`. |
| `zone_name` | string | Matched flat-rate zone name, if any. |
| `distance_km` | number | Estimated route distance in kilometers from the core pricing engine. |
| `duration_minutes` | integer | Estimated duration. |
| `requires_approval` | boolean | Whether dispatch/admin should review before considering it final. |
| `approval_message` | string | Human-readable approval notice. |
| `vehicle` | object/null | Selected or auto-selected vehicle. |
| `compatible_vehicles` | array | Vehicles that can handle passenger/luggage count. |

#### Missing Coordinates / Geocoding Failure

If RideFleet cannot resolve pickup/drop-off to coordinates:

```http
400 Bad Request
```

```json
{
  "success": false,
  "error": true,
  "code": "missing_coordinates",
  "message": "Provide pickup/drop-off addresses or raw pickup_lat, pickup_lng, dropoff_lat, and dropoff_lng values."
}
```

The virtual dispatcher should then ask for a clearer pickup/drop-off or provide coordinates from its own maps provider.

#### Outside Service Area

```http
400 Bad Request
```

```json
{
  "success": false,
  "error": true,
  "code": "outside_service_area",
  "message": "This ride is outside our service area.",
  "final_price": null,
  "currency": "USD"
}
```

The dispatcher should stop the booking flow and read the message to the caller.

#### No Vehicle Available

```http
409 Conflict
```

```json
{
  "success": false,
  "code": "no_vehicle_available",
  "message": "No configured vehicle can handle this passenger/luggage count. The dispatcher should contact the business owner before accepting the ride.",
  "passengers": 7,
  "luggage": 5
}
```

### 2. Create Booking

```http
POST /wp-json/ridefleet/v1/dispatcher/bookings
```

Purpose:

Create a confirmed phone-dispatch booking after the caller accepts the quote.

Authentication:

Required.

Rate limit:

`30` attempts per `10` minutes.

#### Request Body

```json
{
  "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
  "dropoffAddress": "Brussels Airport, Zaventem, Belgium",
  "customerName": "Ismail Boushabi",
  "customerPhone": "+32467626575",
  "customerEmail": "ismail@example.com",
  "passengers": 2,
  "luggage": 1,
  "pickupTime": "2026-05-16 09:00",
  "vehicleId": 456,
  "extras": [
    {
      "id": 123,
      "quantity": 1
    }
  ],
  "verifiedFinalPrice": 108.17,
  "note": "Caller asked for pickup near the station entrance."
}
```

#### Required Fields

| Field | Required | Notes |
|---|---:|---|
| `pickupAddress` or coordinates | Yes | Address or raw pickup coordinates. |
| `dropoffAddress` or coordinates | Yes | Address or raw drop-off coordinates. |
| `pickupTime` or `pickupDate` + `pickupClock` | Yes | Must parse into a valid datetime. |
| `customerName` or `customerFirstName` | Yes | If `customerName` is provided, RideFleet splits first/last name. |
| `customerPhone` | Yes | Phone is required for dispatcher bookings. |

Optional:

- `customerEmail`
- `passengers`
- `luggage`
- `vehicleId`
- `extras`
- `note`
- `verifiedFinalPrice`

`verifiedFinalPrice` is strongly recommended. If provided, RideFleet recalculates the fare and rejects the booking if the submitted price does not match the current verified fare.

#### Successful Response

```http
201 Created
```

```json
{
  "success": true,
  "booking": {
    "id": 28,
    "booking_number": "RFB-20260516-A1B2C3",
    "status": "confirmed",
    "payment_status": "unpaid",
    "source": "virtual_dispatcher",
    "pickup_address": "Antwerp Central Station, Antwerp, Belgium",
    "dropoff_address": "Brussels Airport, Zaventem, Belgium",
    "pickup_at": "2026-05-16 09:00:00",
    "distance": 44.281,
    "distance_unit": "km",
    "duration_minutes": 59,
    "passengers": 2,
    "luggage": 1,
    "vehicle_id": 456,
    "total": 108.17,
    "currency": "USD",
    "customer": {
      "name": "Ismail Boushabi",
      "email": "ismail@example.com",
      "phone": "+32467626575"
    },
    "created_at": "2026-05-15 13:10:00",
    "updated_at": "2026-05-15 13:10:00"
  },
  "message": "Booking created from virtual dispatcher API."
}
```

#### Status Rules

Normal successful dispatcher bookings:

```json
{
  "status": "confirmed",
  "payment_status": "unpaid"
}
```

If RideFleet detects that dispatch/admin approval is needed:

```json
{
  "status": "pending_payment",
  "payment_status": "unpaid"
}
```

The booking is also flagged internally with:

- `_approval_required = 1`
- `_service_area_status`

This makes it visible in the admin approval workflow/dashboard.

#### Fare Changed

If `verifiedFinalPrice` does not match the recalculated RideFleet fare:

```http
409 Conflict
```

```json
{
  "success": false,
  "code": "fare_changed",
  "message": "The fare changed. Reconfirm the current quote with the caller before creating the booking.",
  "verified_price": 112.81,
  "currency": "USD"
}
```

The dispatcher should read the new price to the caller and ask for confirmation before retrying.

#### Pickup Time Not Available

```http
409 Conflict
```

```json
{
  "message": "This pickup time is not available."
}
```

The dispatcher should ask for a different pickup time.

### 3. Find Bookings

```http
GET /wp-json/ridefleet/v1/dispatcher/bookings
```

Purpose:

Find existing bookings by booking number or customer phone number.

Authentication:

Required.

#### Query By Booking Number

```http
GET /wp-json/ridefleet/v1/dispatcher/bookings?booking=RFB-20260516-A1B2C3
```

Response:

```json
{
  "success": true,
  "bookings": [
    {
      "id": 28,
      "booking_number": "RFB-20260516-A1B2C3",
      "status": "confirmed",
      "payment_status": "unpaid",
      "source": "virtual_dispatcher",
      "pickup_address": "Antwerp Central Station, Antwerp, Belgium",
      "dropoff_address": "Brussels Airport, Zaventem, Belgium",
      "pickup_at": "2026-05-16 09:00:00",
      "distance": 44.281,
      "distance_unit": "km",
      "duration_minutes": 59,
      "passengers": 2,
      "luggage": 1,
      "vehicle_id": 456,
      "total": 108.17,
      "currency": "USD",
      "customer": {
        "name": "Ismail Boushabi",
        "email": "ismail@example.com",
        "phone": "+32467626575"
      },
      "created_at": "2026-05-15 13:10:00",
      "updated_at": "2026-05-15 13:10:00"
    }
  ]
}
```

#### Query By Phone

```http
GET /wp-json/ridefleet/v1/dispatcher/bookings?phone=%2B32467626575
```

Response:

```json
{
  "success": true,
  "bookings": [
    {
      "booking_number": "RFB-20260516-A1B2C3",
      "status": "confirmed",
      "pickup_at": "2026-05-16 09:00:00"
    }
  ]
}
```

The actual response includes the full booking response object for each booking.

Phone lookup returns up to the 10 most recent bookings for that exact stored phone value.

If no `booking` or `phone` is supplied:

```http
400 Bad Request
```

```json
{
  "message": "Provide a booking number or phone number."
}
```

### 4. Get Booking By Booking Number

```http
GET /wp-json/ridefleet/v1/dispatcher/bookings/{booking_number}
```

Example:

```http
GET /wp-json/ridefleet/v1/dispatcher/bookings/RFB-20260516-A1B2C3
```

Response:

```json
{
  "success": true,
  "booking": {
    "id": 28,
    "booking_number": "RFB-20260516-A1B2C3",
    "status": "confirmed",
    "payment_status": "unpaid",
    "source": "virtual_dispatcher",
    "pickup_address": "Antwerp Central Station, Antwerp, Belgium",
    "dropoff_address": "Brussels Airport, Zaventem, Belgium",
    "pickup_at": "2026-05-16 09:00:00",
    "distance": 44.281,
    "distance_unit": "km",
    "duration_minutes": 59,
    "passengers": 2,
    "luggage": 1,
    "vehicle_id": 456,
    "total": 108.17,
    "currency": "USD",
    "customer": {
      "name": "Ismail Boushabi",
      "email": "ismail@example.com",
      "phone": "+32467626575"
    },
    "created_at": "2026-05-15 13:10:00",
    "updated_at": "2026-05-15 13:10:00"
  }
}
```

If not found:

```http
404 Not Found
```

```json
{
  "message": "Booking not found."
}
```

### 5. Submit Change Request

```http
POST /wp-json/ridefleet/v1/dispatcher/bookings/{booking_number}/change-request
```

Purpose:

Submit requested booking changes for admin review.

This endpoint does not directly update the booking route/time/passengers. It stores a pending change request and marks the booking as requiring approval.

Example:

```http
POST /wp-json/ridefleet/v1/dispatcher/bookings/RFB-20260516-A1B2C3/change-request
```

Request:

```json
{
  "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
  "dropoffAddress": "Leuven Station, Leuven, Belgium",
  "pickupTime": "2026-05-16 10:30",
  "passengers": 3,
  "luggage": 2,
  "note": "Caller wants to change destination and leave 90 minutes later."
}
```

Supported change fields:

| Field | Type | Meaning |
|---|---:|---|
| `pickupAddress` | string | Requested new pickup address. |
| `dropoffAddress` | string | Requested new drop-off address. |
| `pickupTime` | string | Requested new pickup datetime. |
| `passengers` | integer | Requested passenger count. |
| `luggage` | integer | Requested luggage count. |
| `note` | string | Human note from dispatcher/caller. |

Successful response:

```http
201 Created
```

```json
{
  "success": true,
  "message": "Change request submitted for admin approval. The original booking remains active until approved.",
  "booking": {
    "booking_number": "RFB-20260516-A1B2C3",
    "status": "confirmed"
  },
  "change_request": {
    "status": "pending_admin_review",
    "requested_at": "2026-05-15 13:30:00",
    "requested_by": "virtual_dispatcher_api",
    "changes": {
      "dropoffAddress": "Leuven Station, Leuven, Belgium",
      "pickupTime": "2026-05-16 10:30",
      "note": "Caller wants to change destination and leave 90 minutes later."
    }
  }
}
```

If the request body contains no meaningful changes:

```http
400 Bad Request
```

```json
{
  "message": "Describe what should change before submitting the request."
}
```

## Booking Response Object

RideFleet returns bookings using this shape:

```json
{
  "id": 28,
  "booking_number": "RFB-20260516-A1B2C3",
  "status": "confirmed",
  "payment_status": "unpaid",
  "source": "virtual_dispatcher",
  "pickup_address": "Antwerp Central Station, Antwerp, Belgium",
  "dropoff_address": "Brussels Airport, Zaventem, Belgium",
  "pickup_at": "2026-05-16 09:00:00",
  "distance": 44.281,
  "distance_unit": "km",
  "duration_minutes": 59,
  "passengers": 2,
  "luggage": 1,
  "vehicle_id": 456,
  "total": 108.17,
  "currency": "USD",
  "customer": {
    "name": "Ismail Boushabi",
    "email": "ismail@example.com",
    "phone": "+32467626575"
  },
  "created_at": "2026-05-15 13:10:00",
  "updated_at": "2026-05-15 13:10:00"
}
```

## Status Values

Booking status values commonly used by RideFleet:

| Status | Meaning |
|---|---|
| `pending_payment` | Request exists but needs payment/admin review/next action depending on workflow. |
| `confirmed` | Ride is accepted/confirmed. |
| `completed` | Ride completed. |
| `cancelled` | Ride cancelled. |
| `refunded` | Refunded. |
| `failed` | Failed booking/payment flow. |

Payment status values:

| Status | Meaning |
|---|---|
| `unpaid` | Not paid yet. This is normal for phone dispatch if payment happens after the ride. |
| `paid` | Paid. |
| `partially_paid` | Partial payment. |
| `failed` | Payment failed. |
| `refunded` | Payment refunded. |

## Error Handling

Use HTTP status codes first:

| HTTP Status | Meaning |
|---:|---|
| `200` | Successful read/quote. |
| `201` | Booking or change request created. |
| `400` | Invalid request, missing data, missing coordinates, outside service area. |
| `401` or `403` | Authentication failed or API disabled. WordPress may return its default REST auth shape. |
| `404` | Booking not found. |
| `409` | Business conflict, e.g. fare changed, no vehicle, time unavailable. |
| `429` | Rate limited. |
| `500` | Server or integration failure. |

Dispatcher behavior:

- `400`: Ask caller for clearer information or stop if service area blocked.
- `401/403`: Integration configuration issue. Do not continue call flow as if booking worked.
- `404`: Ask caller to repeat booking number or phone.
- `409`: Explain the operational conflict and ask for confirmation/alternative.
- `429`: Retry later, back off.
- `500`: Apologize and tell caller dispatch will follow up, depending on business policy.

## Example cURL Calls

### Quote

```bash
curl -X POST "https://example.com/wp-json/ridefleet/v1/dispatcher/quote" \
  -H "Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
    "dropoffAddress": "Brussels Airport, Zaventem, Belgium",
    "passengers": 2,
    "luggage": 1,
    "pickupTime": "2026-05-16 09:00"
  }'
```

### Create Booking

```bash
curl -X POST "https://example.com/wp-json/ridefleet/v1/dispatcher/bookings" \
  -H "Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
    "dropoffAddress": "Brussels Airport, Zaventem, Belgium",
    "customerName": "Ismail Boushabi",
    "customerPhone": "+32467626575",
    "customerEmail": "ismail@example.com",
    "passengers": 2,
    "luggage": 1,
    "pickupTime": "2026-05-16 09:00",
    "verifiedFinalPrice": 108.17
  }'
```

### Look Up By Booking Number

```bash
curl "https://example.com/wp-json/ridefleet/v1/dispatcher/bookings/RFB-20260516-A1B2C3" \
  -H "Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

### Look Up By Phone

```bash
curl "https://example.com/wp-json/ridefleet/v1/dispatcher/bookings?phone=%2B32467626575" \
  -H "Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
```

### Submit Change Request

```bash
curl -X POST "https://example.com/wp-json/ridefleet/v1/dispatcher/bookings/RFB-20260516-A1B2C3/change-request" \
  -H "Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "dropoffAddress": "Leuven Station, Leuven, Belgium",
    "pickupTime": "2026-05-16 10:30",
    "note": "Caller requested a later pickup and new destination."
  }'
```

## Recommended Voice Dispatcher Script Logic

The virtual dispatcher should follow this pattern:

1. Ask for pickup.
2. Ask for destination.
3. Ask for pickup time.
4. Ask for passenger count.
5. Ask for luggage count.
6. Call quote endpoint.
7. If quote fails:
   - Missing coordinates: ask for a clearer place/address.
   - Outside service area: read the RideFleet message and stop.
   - No vehicle: tell caller dispatch needs manual follow-up.
8. If quote succeeds:
   - Read the fare.
   - If `requires_approval` is true, explain that the trip can be requested but is not final until human dispatch/admin confirms it.
   - Ask caller if they want to book.
9. If caller confirms:
   - Collect name and phone.
   - Call create booking endpoint.
10. Read booking number.
11. If the created booking status is `pending_payment` or the quote had `requires_approval=true`, call it a ride request, not a confirmed ride.

Suggested caller wording for normal quote:

```text
I have a quoted fare of USD 108.17. Would you like me to book that ride?
```

Suggested caller wording when approval is required:

```text
I can submit this ride request, but dispatch needs to review it because part of the route is outside the normal operating area. The fare may be adjusted after review. Would you like me to send the request?
```

Suggested caller wording after creating an approval-required booking:

```text
I have sent the ride request to dispatch for review. Your request number is RFB-20260515-NZ53KY. The taxi company still needs to confirm it, and they may contact you if the route or fare needs adjustment.
```

Suggested caller wording when both pickup and destination are outside service area:

```text
I'm sorry, that ride is outside the company's service area, so I cannot create a booking for it.
```

Suggested caller wording when no vehicle is available:

```text
I do not see a configured vehicle that can handle that passenger and luggage count. I will need the taxi company to follow up manually before confirming.
```

## Security Requirements For Dispatcher Developers

Required:

- Store the API key server-side only.
- Use HTTPS in production.
- Never reveal the API key in voice transcripts, logs shown to customers, browser JavaScript, mobile app bundles, or prompts.
- Treat all RideFleet error messages as operational data, not as a reason to bypass the flow.
- Reconfirm fare if RideFleet returns `fare_changed`.
- Never create bookings without customer confirmation.
- Never mark payment as paid from the dispatcher side.

Recommended:

- Log your own request ID per call.
- Store RideFleet `booking_number` after booking creation.
- Use idempotency on your side to prevent double booking if a phone call reconnects.
- Before retrying create booking after a timeout, look up by phone and recent pickup time to avoid duplicates.
- Keep a clear transcript of the caller confirmation.

## Idempotency Notes

The current API does not yet expose a first-class `Idempotency-Key` header.

Until that exists, the dispatcher should prevent duplicate booking creation with its own call/session logic:

- Generate a dispatcher-side call/session ID.
- Store the latest successful booking number.
- On network timeout after `POST /dispatcher/bookings`, search by customer phone before retrying.
- If a matching recent booking exists, confirm that booking instead of creating a second one.

Future improvement:

- Add support for an `Idempotency-Key` header.
- Store `_dispatcher_idempotency_key` in booking meta.
- Return the original booking when the same key is replayed.

## Current Implementation Notes

Main implementation file:

```text
includes/Rest/Routes.php
```

Main methods:

```text
can_dispatcher_access()
dispatcher_quote()
dispatcher_create_booking()
dispatcher_find_bookings()
dispatcher_get_booking()
dispatcher_change_request()
dispatcher_payload()
dispatcher_location_params()
validate_dispatcher_payload()
dispatcher_vehicle_for_payload()
compatible_vehicles()
dispatcher_vehicle_summary()
booking_by_number()
booking_response()
```

Settings implementation:

```text
includes/Admin/SettingsPage.php
```

Default settings:

```text
includes/Core/Installer.php
```

Booking change request admin visibility:

```text
includes/Admin/BookingsPage.php
assets/admin/admin.css
```

## Data Storage Side Effects

Creating a dispatcher booking:

- Inserts a row into `wp_rfb_bookings`.
- Creates or updates a customer in `wp_rfb_customers`.
- Saves booking metadata in `wp_rfb_booking_meta`.
- Stores quote snapshot.
- Stores selected extras.
- Stores source as `virtual_dispatcher`.
- Sends normal RideFleet booking notification.

Change request:

- Adds `_change_request` metadata.
- Adds `_approval_required = 1`.
- Does not overwrite original booking fields.
- Shows on the booking detail admin page.

Example admin display for a change request:

```text
Change Requests
These requests need admin approval before the original booking is changed.

2026-05-15 08:09:55 / virtual_dispatcher_api
DropoffAddress: Leuven Station, Leuven, Belgium
PickupTime: 2026-05-16 10:30
Note: PowerShell test: caller requested a later pickup and different destination.
```

Dispatcher behavior after submitting a change request:

```text
I have sent your change request to dispatch for approval. Your original booking remains active until the taxi company confirms the change.
```

## What Developers Should Not Depend On

Do not depend on:

- WordPress internal database table names.
- Numeric internal booking `id` as the public reference.
- Exact internal meta keys.
- Payment status changing during dispatcher booking.
- The quote UUID being required for booking creation.

Use:

- `booking_number` as the customer-facing reference.
- `status` and `payment_status` from API responses.
- `verifiedFinalPrice` to protect against stale quotes.

## Production Checklist

Before going live:

- Enable dispatcher API in RideFleet Settings.
- Generate a long dispatcher API key.
- Confirm Google Maps key is configured if address geocoding is expected.
- Configure vehicles with realistic passenger/luggage capacities.
- Configure extras with prices and max quantities.
- Configure service area/geofences.
- Test quote inside service area.
- Test quote outside service area.
- Test one-side-outside approval case.
- Test too many passengers/luggage.
- Test create booking with matching `verifiedFinalPrice`.
- Test create booking with stale/wrong `verifiedFinalPrice`.
- Test lookup by booking number.
- Test lookup by phone.
- Test change request and verify it appears in the admin booking detail page.

## Minimal Happy Path

1. Quote:

```json
{
  "pickupAddress": "Antwerp Central Station",
  "dropoffAddress": "Brussels Airport",
  "passengers": 1,
  "luggage": 0,
  "pickupTime": "2026-05-16 09:00"
}
```

2. Read `final_price`.

3. Caller confirms.

4. Create booking:

```json
{
  "pickupAddress": "Antwerp Central Station",
  "dropoffAddress": "Brussels Airport",
  "customerName": "Jane Caller",
  "customerPhone": "+32467000000",
  "passengers": 1,
  "luggage": 0,
  "pickupTime": "2026-05-16 09:00",
  "verifiedFinalPrice": 108.17
}
```

5. Read back:

```text
Your ride is booked. Your booking number is RFB-20260516-A1B2C3.
```
