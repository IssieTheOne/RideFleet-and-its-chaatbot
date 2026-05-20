# Testing The RideFleet Virtual Dispatcher API Without A Dispatcher

This guide explains how to test the RideFleet Virtual Dispatcher API manually before the real virtual dispatcher is built.

You can test with:

- Postman
- Insomnia
- Hoppscotch
- ReqBin
- Thunder Client
- cURL
- PowerShell

The API is server-to-server. It is not supposed to visibly change the frontend when you enable it. Enabling it gives external systems permission to call the dispatcher endpoints with an API key.

## Important Console Messages

These browser console messages are usually unrelated to the dispatcher API:

```text
w.js:1 Failed to load resource: net::ERR_BLOCKED_BY_CLIENT
maps.googleapis.com/maps/api/mapsjs/gen_204?csp_test=true Failed to load resource: net::ERR_BLOCKED_BY_CLIENT
JQMIGRATE: Migrate is installed
```

What they mean:

- `ERR_BLOCKED_BY_CLIENT`: usually an ad blocker, privacy extension, or browser protection blocking analytics or Google diagnostic requests.
- `JQMIGRATE`: normal WordPress/jQuery compatibility notice.
- `maps.googleapis.com/maps/api/mapsjs/gen_204?csp_test=true`: Google Maps diagnostic/CSP probe. If the map still works, this warning is usually harmless.

The dispatcher API should be tested with HTTP requests, not by looking for a visual frontend change.

## Step 1: Enable The API

In WordPress admin:

```text
RideFleet > Settings > Virtual Dispatcher API
```

Enable:

```text
Enable virtual dispatcher REST API
```

Then save settings.

If the `Dispatcher API Key` field was empty, RideFleet should generate a key after save:

```text
rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

If you do not see a key:

1. Confirm the checkbox stayed enabled after saving.
2. Clear the key field.
3. Save again.
4. Hard refresh the admin page.

## Step 2: Copy The API Base URL

The settings page shows:

```text
Base URL: https://your-site.com/wp-json/ridefleet/v1/dispatcher
```

Local example:

```text
http://taxi.local/wp-json/ridefleet/v1/dispatcher
```

## Step 3: Authentication Header

Every dispatcher request needs this header:

```http
Authorization: Bearer YOUR_DISPATCHER_API_KEY
```

Example:

```http
Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Alternative header:

```http
X-RideFleet-Dispatcher-Key: rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Use the `Authorization: Bearer` format unless your test tool makes that difficult.

## Step 4: Test With Postman

### Create A Collection

1. Open Postman.
2. Create a collection called `RideFleet Dispatcher API`.
3. Add a collection variable:

```text
base_url = http://taxi.local/wp-json/ridefleet/v1/dispatcher
```

4. Add another collection variable:

```text
api_key = rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Set Authorization

On the collection Authorization tab:

```text
Type: Bearer Token
Token: {{api_key}}
```

### Add Headers

For JSON requests:

```http
Content-Type: application/json
Accept: application/json
```

## Step 5: Test Quote Endpoint

Endpoint:

```http
POST {{base_url}}/quote
```

Body:

```json
{
  "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
  "dropoffAddress": "Brussels Airport, Zaventem, Belgium",
  "passengers": 2,
  "luggage": 1,
  "pickupTime": "2026-05-16 09:00"
}
```

Expected successful result:

```json
{
  "success": true,
  "quote_id": "uuid-here",
  "currency": "USD",
  "final_price": 108.17,
  "base_price": 108.17,
  "addons": {
    "vehicle_adjustment": 0,
    "extras_total": 0
  },
  "pricing_source": "standard",
  "zone_name": "",
  "distance_km": 44.281,
  "duration_minutes": 59,
  "requires_approval": false,
  "approval_message": "",
  "vehicle": {
    "id": 123,
    "name": "Vehicle Name",
    "passengers": 4,
    "luggage": 2
  },
  "compatible_vehicles": [],
  "notice": "This is a ride quote. The dispatcher can create the booking after the caller confirms."
}
```

The exact price, distance, duration, and vehicle depend on your RideFleet settings.

## Step 6: Test Create Booking Endpoint

After you have a quote, call:

```http
POST {{base_url}}/bookings
```

Body:

```json
{
  "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
  "dropoffAddress": "Brussels Airport, Zaventem, Belgium",
  "customerName": "Test Dispatcher",
  "customerPhone": "+32467000000",
  "customerEmail": "test@example.com",
  "passengers": 2,
  "luggage": 1,
  "pickupTime": "2026-05-16 09:00",
  "verifiedFinalPrice": 108.17,
  "note": "Manual API test booking."
}
```

Important:

- Replace `verifiedFinalPrice` with the exact `final_price` from the quote response.
- If you use the wrong price, RideFleet should reject the booking with `fare_changed`.

Expected success:

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
    "vehicle_id": 123,
    "total": 108.17,
    "currency": "USD",
    "customer": {
      "name": "Test Dispatcher",
      "email": "test@example.com",
      "phone": "+32467000000"
    }
  },
  "message": "Booking created from virtual dispatcher API."
}
```

Now check:

```text
RideFleet > Bookings
```

You should see a new booking with source `virtual_dispatcher`.

## Step 7: Test Lookup By Booking Number

Endpoint:

```http
GET {{base_url}}/bookings/RFB-20260516-A1B2C3
```

Expected:

```json
{
  "success": true,
  "booking": {
    "booking_number": "RFB-20260516-A1B2C3",
    "status": "confirmed",
    "payment_status": "unpaid"
  }
}
```

## Step 8: Test Lookup By Phone

Endpoint:

```http
GET {{base_url}}/bookings?phone=%2B32467000000
```

`%2B` is the URL-encoded `+` sign.

Expected:

```json
{
  "success": true,
  "bookings": [
    {
      "booking_number": "RFB-20260516-A1B2C3",
      "customer": {
        "phone": "+32467000000"
      }
    }
  ]
}
```

## Step 9: Test Change Request

Endpoint:

```http
POST {{base_url}}/bookings/RFB-20260516-A1B2C3/change-request
```

Body:

```json
{
  "dropoffAddress": "Leuven Station, Leuven, Belgium",
  "pickupTime": "2026-05-16 10:30",
  "note": "Caller wants to leave later and change destination."
}
```

Expected:

```json
{
  "success": true,
  "message": "Change request submitted for admin approval. The original booking remains active until approved.",
  "change_request": {
    "status": "pending_admin_review",
    "requested_by": "virtual_dispatcher_api"
  }
}
```

Now open the booking in:

```text
RideFleet > Bookings > booking detail
```

You should see a `Change Requests` panel.

## Step 10: Test With cURL

### Quote

```bash
curl -X POST "http://taxi.local/wp-json/ridefleet/v1/dispatcher/quote" \
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
curl -X POST "http://taxi.local/wp-json/ridefleet/v1/dispatcher/bookings" \
  -H "Authorization: Bearer rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{
    "pickupAddress": "Antwerp Central Station, Antwerp, Belgium",
    "dropoffAddress": "Brussels Airport, Zaventem, Belgium",
    "customerName": "Test Dispatcher",
    "customerPhone": "+32467000000",
    "customerEmail": "test@example.com",
    "passengers": 2,
    "luggage": 1,
    "pickupTime": "2026-05-16 09:00",
    "verifiedFinalPrice": 108.17,
    "note": "Manual cURL test booking."
  }'
```

## Step 11: Test With PowerShell

PowerShell is often easier on Windows than cURL quoting.

Set variables:

```powershell
$baseUrl = "http://taxi.local/wp-json/ridefleet/v1/dispatcher"
$apiKey = "rfb_live_dispatcher_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
$headers = @{
  Authorization = "Bearer $apiKey"
  "Content-Type" = "application/json"
}
```

Quote:

```powershell
$quoteBody = @{
  pickupAddress = "Antwerp Central Station, Antwerp, Belgium"
  dropoffAddress = "Brussels Airport, Zaventem, Belgium"
  passengers = 2
  luggage = 1
  pickupTime = "2026-05-16 09:00"
} | ConvertTo-Json

Invoke-RestMethod -Uri "$baseUrl/quote" -Method Post -Headers $headers -Body $quoteBody
```

Create booking:

```powershell
$bookingBody = @{
  pickupAddress = "Antwerp Central Station, Antwerp, Belgium"
  dropoffAddress = "Brussels Airport, Zaventem, Belgium"
  customerName = "Test Dispatcher"
  customerPhone = "+32467000000"
  customerEmail = "test@example.com"
  passengers = 2
  luggage = 1
  pickupTime = "2026-05-16 09:00"
  verifiedFinalPrice = 108.17
  note = "Manual PowerShell test booking."
} | ConvertTo-Json

Invoke-RestMethod -Uri "$baseUrl/bookings" -Method Post -Headers $headers -Body $bookingBody
```

## Step 12: Test With Online Tools

You can use online tools such as:

- ReqBin
- Hoppscotch
- Postman Web

However, online tools usually cannot reach local-only domains like:

```text
http://taxi.local
```

They can only reach public URLs. For local WordPress testing, use:

- Postman desktop
- Insomnia desktop
- Thunder Client in VS Code
- PowerShell
- cURL

If you want to test `taxi.local` from an online tool, you would need a tunnel such as ngrok or Cloudflare Tunnel. Do not expose a real dispatcher API key publicly while testing through a tunnel unless you understand the risk.

## Common Problems

### 1. I enabled the API but nothing changed visually

That is expected. The dispatcher API is a machine API. It does not add a frontend widget.

Check:

- Settings page shows `API enabled`.
- Key field contains `rfb_live_dispatcher_...`.
- You can call `/dispatcher/quote` with the key.

### 2. I get 401 or 403

Likely causes:

- API is not enabled.
- API key is empty.
- Wrong key.
- Missing `Authorization: Bearer ...` header.
- Security plugin blocks REST API.

### 3. I get missing_coordinates

RideFleet could not geocode the address.

Try:

- More complete pickup/drop-off addresses.
- Raw coordinates:

```json
{
  "pickupAddress": "Antwerp Central Station",
  "dropoffAddress": "Brussels Airport",
  "pickup_lat": 51.2172,
  "pickup_lng": 4.4211,
  "dropoff_lat": 50.9010,
  "dropoff_lng": 4.4856
}
```

### 4. I get no_vehicle_available

Your configured vehicles cannot handle the requested passenger/luggage count.

Fix:

- Lower passenger/luggage count.
- Add a larger vehicle in RideFleet.
- Adjust vehicle capacity in admin.

### 5. I get fare_changed

The `verifiedFinalPrice` in the create booking call does not match the current quote.

Fix:

1. Call `/dispatcher/quote` again.
2. Use the returned `final_price`.
3. Reconfirm with caller.
4. Retry `/dispatcher/bookings`.

### 6. Phone lookup returns no bookings

Phone lookup is exact-match against the stored phone value.

Try the exact format used at booking creation:

```text
+32467000000
0467000000
+32 467 00 00 00
```

Best practice: normalize phone numbers in the dispatcher before sending them to RideFleet.

## Suggested Test Checklist

- API disabled returns auth failure.
- API enabled with wrong key returns auth failure.
- API enabled with correct key returns quote.
- Normal quote works.
- Quote outside service area fails.
- One-side-outside route returns approval behavior if configured.
- Too many passengers returns `no_vehicle_available`.
- Create booking works with correct `verifiedFinalPrice`.
- Create booking fails with wrong `verifiedFinalPrice`.
- Booking appears in RideFleet admin.
- Lookup by booking number works.
- Lookup by phone works.
- Change request appears in booking detail.

