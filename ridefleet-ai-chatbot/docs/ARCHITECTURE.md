# RideFleet AI Chatbot Architecture

## Purpose

RideFleet AI Chatbot is a standalone WordPress plugin that acts as a customer-facing booking assistant for the core `ridefleet-booking` system. It owns only chatbot configuration, chat sessions, message logs, and booking submission audit events.

It does not store geofences, price matrices, route rules, dispatch schedules, fleet settings, or core administrative variables.

## Security Boundary

The chatbot has two allowed core-plugin actions:

- Read price quote: `GET /wp-json/taxi-booking/v1/calculate-price`
- Create reservation: `POST /wp-json/taxi-booking/v1/create-booking`

The chatbot codebase contains no API client methods for updating, deleting, or reading core configuration resources. Payloads sent to the booking endpoint are allowlisted to customer and ride fields only:

- `pickup_address`
- `dropoff_address`
- `customer_name`
- `customer_phone`
- `pickup_time`
- `final_price`
- `currency`

## Database Schema

### `wp_rfac_chat_sessions`

Stores resumable conversation state and the last verified quote.

```sql
CREATE TABLE wp_rfac_chat_sessions (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	session_key varchar(100) NOT NULL DEFAULT '',
	state varchar(60) NOT NULL DEFAULT 'greeting',
	collected_data longtext NULL,
	last_quote longtext NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY session_key (session_key),
	KEY state (state),
	KEY updated_at (updated_at)
);
```

### `wp_rfac_chat_messages`

Stores sanitized user and assistant messages for short conversation history and support review.

```sql
CREATE TABLE wp_rfac_chat_messages (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	session_id bigint unsigned NOT NULL,
	role varchar(20) NOT NULL DEFAULT 'user',
	message longtext NOT NULL,
	admin_summary text NULL,
	detected_language varchar(10) NOT NULL DEFAULT '',
	intent varchar(40) NOT NULL DEFAULT '',
	extracted_fields longtext NULL,
	created_at datetime NOT NULL,
	PRIMARY KEY (id),
	KEY session_id (session_id),
	KEY role (role),
	KEY intent (intent)
);
```

### `wp_rfac_booking_events`

Stores chatbot-side audit logs for booking submissions to the core system.

```sql
CREATE TABLE wp_rfac_booking_events (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	session_id bigint unsigned NULL,
	core_booking_id varchar(80) NOT NULL DEFAULT '',
	pickup_address text NULL,
	dropoff_address text NULL,
	customer_name varchar(190) NOT NULL DEFAULT '',
	customer_phone varchar(80) NOT NULL DEFAULT '',
	pickup_time varchar(120) NOT NULL DEFAULT '',
	verified_price decimal(12,2) NOT NULL DEFAULT 0.00,
	currency varchar(10) NOT NULL DEFAULT 'USD',
	status varchar(40) NOT NULL DEFAULT 'submitted',
	created_at datetime NOT NULL,
	PRIMARY KEY (id),
	KEY session_id (session_id),
	KEY core_booking_id (core_booking_id),
	KEY created_at (created_at)
);
```

### `rfac_settings` Option

```json
{
	"openrouter_key": "sk-or-v1-...",
	"selected_model": "openai/gpt-4o-mini",
	"company_bio": "Long-form fleet, FAQ, and policy context.",
	"core_plugin_api_url": "https://my-taxi-site.com",
	"core_plugin_api_key": "shared-secret",
	"chatbot_ui_theme": {
		"primary": "#0f766e",
		"primary_dark": "#0b5f59",
		"surface": "#ffffff",
		"text": "#17202a",
		"muted": "#64748b"
	},
	"max_price_negotiation_discount": 20
}
```

`openrouter_key` is validated with the `sk-or-v1-` prefix before save.

## Backend Services

### `CoreApiClient`

- `get_core_trip_price($pickup_address, $dropoff_address)`
- `submit_core_booking($booking_data)`

This client only calls the two allowlisted core API endpoints. It never calls settings, geofence, flat-rate, route-matrix, or administrative endpoints.

When `core_plugin_api_key` is configured, the client sends it as `X-RideFleet-Chatbot-Key`.

### `OpenRouterClient`

Uses OpenRouter only for structured turn classification and field extraction. It returns strict JSON such as:

```json
{
	"intent": "location",
	"confidence": 0.93,
	"language": "nl",
	"pickup": "Antwerp Centraal",
	"dropoff": null,
	"reply_tone": "neutral"
}
```

The deterministic booking engine remains authoritative. Classification failure falls back to a local classifier.

### `ConversationEngine`

Core flow states:

1. `greeting`
2. `capture_pickup`
3. `capture_dropoff`
4. `quote_requested`
5. `confirm_price`
6. `capture_name`
7. `capture_phone`
8. `capture_pickup_time`
9. `booking_requested`
10. `complete` or `halted`

Pricing is requested only after pickup and drop-off are known. Booking is submitted only after the user confirms the verified core price and provides name, phone, and pickup time.

Additional v5 behavior:

- intent-first routing before state mutation
- language lock plus explicit language switches
- Google lookup only for confident `location` turns
- repair mode after repeated uncertainty
- fare approval requests that never auto-confirm negotiated fares
- booking edit request status lookup
- admin transcript metadata: intent, language, extracted fields, English summary
- durable browser session id across refreshes

## Public REST API

### `POST /wp-json/ridefleet-chatbot/v1/message`

Request:

```json
{
	"session_id": "rfac_browser_session",
	"message": "I need a taxi from 10 Main St to Burlington Airport"
}
```

Response:

```json
{
	"session_id": "rfac_browser_session",
	"state": "confirm_price",
	"message": "Your verified fare is USD 45.00. Would you like me to reserve this ride?",
	"data": {
		"collected": {
			"pickup_address": "10 Main St",
			"dropoff_address": "Burlington Airport"
		},
		"quote": {
			"final_price": 45,
			"currency": "USD",
			"zone_name": null
		}
	}
}
```

### `GET /wp-json/ridefleet-chatbot/v1/settings/public`

Returns only frontend-safe theme and configuration status. It never returns the OpenRouter key, company bio internals, chat logs, or core settings.

## Admin UI Layout

Dashboard title: **AI Chatbot Connector**

Sections:

1. OpenRouter Gateway
   - OpenRouter API key
   - Model selector
2. Core Booking API
   - Core plugin base URL
   - Fixed endpoint contract display
3. Guardrailed Assistant
   - Company bio textarea
4. Frontend Widget
   - Theme color controls
5. Embed
   - `[ridefleet_ai_chatbot]`

## Frontend Widget

The widget is embedded with `[ridefleet_ai_chatbot]` and renders a floating bottom-right chat bubble. Messages are sent with `fetch()` to the chatbot REST route. A typing indicator is displayed while the request is in flight.

The browser session id is retained in `localStorage`, allowing page reloads to continue the same conversation thread until the user ends or cancels the flow.
