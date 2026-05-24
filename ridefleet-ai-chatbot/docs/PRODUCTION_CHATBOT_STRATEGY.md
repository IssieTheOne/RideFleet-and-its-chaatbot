# RideFleet AI Chatbot Production Strategy

This document captures the critical review of the current chatbot behavior and a production plan for turning it into a fast, intelligent, safe taxi booking assistant. The chatbot should remain a companion to the main RideFleet Booking plugin: the chatbot collects and understands customer intent, while the main plugin remains the source of truth for pricing, service areas, availability, vehicles, coupons, bookings, and dispatch.

## Executive Summary

The current chatbot has the right architectural direction: a deterministic booking funnel, AI-assisted field extraction, multilingual replies, and API calls into the main RideFleet plugin. The weak point is that these parts are not yet cleanly separated. The bot sometimes extracts useful data but does not trust it later, asks for fields it already has, loops on ambiguous locations, duplicates prompts, and treats manual dispatch as a failed quote path instead of a first-class booking mode.

The production target is:

- Deterministic where correctness matters.
- AI-assisted where human language is messy.
- Main-plugin-owned for all pricing and booking truth.
- Fast by default, with AI calls only when needed.
- Safe under duplicate clicks, retries, bad API responses, malformed user input, and partial outages.

## Observed Problems From The Transcript

## Concrete Code Findings From The Review

These are the specific implementation issues found during the transcript review. They should be treated as the first stabilization targets before larger intelligence upgrades.

### 1. Manual-Dispatch Booking Is Broken At The API Boundary

Original finding: the chatbot set `requires_manual_dispatch`, but `CoreApiClient::submit_core_booking()` did not pass that field through its allowlist. The local booking path also still tried to recalculate a fare instead of creating a manual-dispatch booking directly.

This explains the transcript's technical error after pickup time was provided:

```text
Provide pickup/drop-off addresses or raw pickup_lat, pickup_lng, dropoff_lat, and dropoff_lng values.
```

Relevant files:

- `ridefleet-ai-chatbot/includes/Services/CoreApiClient.php`, around line 126: `submit_core_booking()` allowlist.
- `ridefleet-ai-chatbot/includes/Services/ConversationEngine.php`, around line 673: booking payload construction.

Required fix:

- Add `requires_manual_dispatch` to the chatbot API payload allowlist.
- Ensure local core booking respects manual dispatch mode.
- Bypass quote recalculation when manual dispatch is explicitly requested.
- Create a booking/request with zero fare or pending fare, marked for dispatch confirmation.

Current status:

- Done. The chatbot passes `requires_manual_dispatch`.
- Done. Remote `/create-booking` and same-site local booking both bypass fare recalculation for manual dispatch.
- Done. Manual-dispatch bookings are marked with `_requires_manual_dispatch` and a dispatch-facing note.

### 2. Previously Collected Name And Phone Are Not Respected

The first message can contain both contact fields:

```text
My name is Ismail and my phone is +32 467626575
```

The extractor can store those values, but after quote confirmation the state machine blindly advances to `capture_name`, then `capture_phone`, instead of checking whether those fields already exist.

Relevant files:

- `ridefleet-ai-chatbot/includes/Services/ConversationEngine.php`, around line 346: confirm-price affirmative transition.
- `ridefleet-ai-chatbot/includes/Services/ConversationEngine.php`, around line 817: one-shot contact extraction.

Required fix:

- Replace blind state transitions with `next_missing_field()`.
- If `customer_name` exists, skip `capture_name`.
- If `customer_phone` exists, skip `capture_phone`.
- If `pickup_time` exists, skip `capture_pickup_time`.

Current status:

- Done for contact/time capture through `next_contact_state()`.
- Still future work: expand this into a full canonical booking draft and correction engine.

### 3. Returning-Customer Shortcut Is Too Invasive

Clicking "Yes, use saved" sends a normal chat message like:

```text
My name is Ismail and my phone is +32 467626575
```

If the current state is pickup capture, the engine may treat that as a failed pickup-location answer. The user thinks contact details were accepted, while the bot asks for pickup again or logs a location failure.

Relevant file:

- `ridefleet-ai-chatbot/assets/frontend/widget.js`, around line 818: returning customer shortcut.

Required fix:

- Do not inject saved contact as normal chat text.
- Send saved contact as structured request metadata.
- Merge saved contact into the booking draft server-side.
- Respond with an acknowledgement and continue to the next missing booking field.

Current status:

- Done. Saved contact is queued as structured `prefill_contact` metadata and merged server-side.
- Done. The shortcut no longer sends "My name is..." as a fake user message.

### 4. Duplicate Prompts Likely Come From Missing Request Idempotency

The frontend disables the input, but there is no robust one-active-request guard, no `client_message_id`, and no server-side duplicate-turn replay protection.

If an autocomplete click, Enter key submit, quick reply, retry, slow response, or browser race happens, the same user turn can produce duplicate assistant prompts.

Required fix:

- Generate a UUID `client_message_id` for every user turn.
- Store processed message IDs per session.
- Return the original response when a duplicate message ID arrives.
- Add a frontend `requestInFlight` guard.
- Disable quick replies and autocomplete options immediately after click.

Current status:

- Done. Each chat turn sends `client_message_id`.
- Done. Duplicate message IDs replay the cached server response.
- Done. The frontend has one active request at a time and disables clicked quick replies.
- Still future work: duplicate-turn events should be visible in admin diagnostics.

### 5. Location Confirmation UX Is Brittle

In `confirm_dropoff_city`, selecting a candidate such as:

```text
Burlington, VT, USA
```

should count as choosing the pending city or candidate. Instead, the bot can ask the user to reply `yes` again.

Relevant file:

- `ridefleet-ai-chatbot/includes/Services/ConversationEngine.php`, around line 267: `confirm_dropoff_city` handling.

Required fix:

- Accept `yes` as confirmation.
- Accept selected autocomplete candidates as confirmation.
- Accept a candidate matching the pending city as confirmation.

Current status:

- Done. Pending city confirmation accepts yes, city-level confirmation words, and selected candidates.
- Done. Autocomplete selections are sent with structured place metadata when available.
- Store the selected place as structured location data where possible.
- Only re-ask when the candidate conflicts with the pending city or remains ambiguous.

### 1. Early Contact Data Is Lost In The Flow

The user says: "My name is Ismail and my phone is +32 467626575."

The chatbot can extract this, but later still asks:

- "Op welke naam mogen we de boeking zetten?"
- "Op welk nummer kunnen we u bereiken voor deze taxi?"

This makes the assistant feel unintelligent. The core issue is not extraction; it is state advancement. The state machine should always ask for the next missing field, not the next hardcoded state.

### 2. Saved Contact Shortcut Acts Like A User Message

The returning customer shortcut sends a normal message: "My name is ... and my phone is ..."

If the current state is pickup capture, the engine tries to interpret contact details as a pickup location. This creates a bad first impression. Saved contact data should be sent as structured metadata or prefilled into the booking draft, not injected as chat text.

### 3. Manual Dispatch Is Not First-Class

When the quote cannot be calculated, the chatbot correctly offers manual dispatch. But the later submit path still depends on normal quote behavior in places. This can surface technical errors such as:

"Provide pickup/drop-off addresses or raw pickup_lat, pickup_lng, dropoff_lat, and dropoff_lng values."

Manual dispatch should be its own official path:

- no final fare required
- no quote recalculation required
- booking marked as requiring dispatch approval
- customer told that dispatch confirms the fare before pickup
- dispatch receives a clean request with all collected details

### 4. Location Ambiguity Is Not Resolved Smoothly

"Burlington city" and "Burlington, VT, USA" should resolve into a pending city confirmation. The bot currently asks the user to reply yes even after a candidate is selected. A selected autocomplete candidate should be treated as a structured location choice, not as another ambiguous free-text message.

### 5. Duplicate Prompts Suggest Missing Turn Idempotency

The transcript shows repeated assistant prompts. Possible causes include double submit, autocomplete/send races, retries, slow responses, or server replay. Production chat needs client message IDs and server-side duplicate-turn handling.

### 6. Language Quality Is Mixed

The bot is mostly replying in Dutch, but some messages appear in English and some Dutch phrasing is awkward. Language should be session-locked unless explicitly changed, and fixed workflow prompts should be professionally translated rather than localized on every turn.

## Product Principle

The chatbot is not a free-form AI. It is a booking agent.

Its job is to:

1. Understand what the customer said.
2. Update a booking draft.
3. Ask for the next missing or ambiguous detail.
4. Call the main RideFleet plugin for truth.
5. Confirm the booking or create a manual dispatch request.

The chatbot must never invent:

- prices
- service availability
- booking IDs
- vehicle availability
- coupon validity
- dispatch policies
- company rules

## Recommended Architecture

### Layer 1: Frontend Widget

Responsibilities:

- show chat messages
- prevent double sends
- send `client_message_id` with every request
- collect autocomplete selections as structured place objects
- optionally prefill saved contact as structured metadata
- display quote, manual dispatch, and booking summary cards
- show loading, timeout, offline, and retry states

The frontend should not decide booking truth. It can improve UX, but the server remains authoritative.

### Layer 2: Conversation Orchestrator

Responsibilities:

- load session
- classify intent
- extract fields
- merge fields into booking draft
- decide next action
- call API tools
- create final response
- persist state transition and audit log

This layer should be deterministic.

### Layer 3: AI Understanding

Responsibilities:

- classify messy natural language
- extract structured fields
- detect corrections
- identify ambiguity
- rewrite/localize user-facing phrasing when needed

The AI should not directly control state or call booking APIs.

### Layer 4: RideFleet Core API Client

Responsibilities:

- place search
- place resolve
- quote calculation
- manual dispatch request creation
- booking creation
- coupon validation
- availability lookup
- vehicle/extras lookup

This client should expose typed methods with stable result codes.

### Layer 5: Main RideFleet Booking Plugin

Responsibilities:

- pricing
- geofencing
- route rules
- fixed fares
- vehicles
- extras
- coupons
- booking records
- dispatch notifications
- payment state
- admin settings

The chatbot should never bypass this plugin for business decisions.

## Canonical Booking Draft

Create one canonical draft object in session data:

```json
{
  "pickup": {
    "text": "",
    "formatted_address": "",
    "place_id": "",
    "lat": null,
    "lng": null,
    "type": ""
  },
  "dropoff": {
    "text": "",
    "formatted_address": "",
    "place_id": "",
    "lat": null,
    "lng": null,
    "type": ""
  },
  "via_stop": null,
  "pickup_time": "",
  "customer": {
    "name": "",
    "phone": "",
    "email": ""
  },
  "passengers": 1,
  "luggage": 0,
  "vehicle": null,
  "extras": [],
  "coupon_code": "",
  "quote": null,
  "manual_dispatch": false,
  "language": "en"
}
```

All extraction, autocomplete, quick replies, and corrections update this draft. The next prompt is derived from this draft.

## Next Missing Field Logic

Replace hardcoded "state A always goes to state B" with a helper:

```text
next_missing_field(draft):
  if pickup missing: ask pickup
  if pickup ambiguous: disambiguate pickup
  if dropoff missing: ask dropoff
  if dropoff ambiguous: disambiguate dropoff
  if quote missing and route quoteable: calculate quote
  if quote unavailable: ask manual dispatch consent
  if name missing: ask name
  if phone missing: ask phone
  if pickup_time missing: ask pickup time
  if final confirmation missing: show summary and ask confirm
  else: submit booking
```

This one change would make the bot feel much smarter because it stops asking for information it already has.

## AI Strategy

Use AI for understanding, not authority.

### Fast Local Rules First

Do not call AI for:

- yes/no
- phone numbers
- datetime-local values
- quick-reply values
- selected autocomplete results
- exact booking confirmation
- reset/cancel

### Small Model For Extraction

Use a fast, inexpensive model for strict JSON extraction:

```json
{
  "intent": "booking_detail",
  "fields": {
    "customer_name": "Ismail",
    "customer_phone": "+32 467626575",
    "pickup": "BTV airport",
    "dropoff": "Burlington city",
    "pickup_time": null
  },
  "corrections": [],
  "confidence": 0.93
}
```

### Stronger Model Only For Hard Turns

Use a better model only when:

- the local classifier is uncertain
- there are multiple corrections in one message
- the message combines question + booking change
- the language is unclear
- customer phrasing is messy

### Correction Handling

Support messages like:

- "Actually make it tomorrow at 10."
- "No, Burlington Vermont."
- "Use the same phone."
- "Change pickup to the airport."
- "I meant the Hilton, not the airport."

Corrections should update the booking draft and then recalculate only what is affected.

## API Contract With RideFleet Booking

The main plugin API should return stable machine-readable codes. Avoid relying on free-text messages.

Recommended endpoints:

- `GET /place-search`
- `GET /place-resolve`
- `POST /calculate-price`
- `POST /create-booking`
- `POST /create-manual-dispatch-request`
- `POST /validate-coupon`
- `GET /vehicles`
- `GET /extras`
- `GET /availability`

Recommended error shape:

```json
{
  "success": false,
  "code": "QUOTE_UNAVAILABLE",
  "user_safe": true,
  "message": "No automatic fare is available for this route.",
  "fallback": "manual_dispatch"
}
```

Important codes:

- `PLACE_AMBIGUOUS`
- `PLACE_NOT_FOUND`
- `OUTSIDE_SERVICE_AREA`
- `QUOTE_UNAVAILABLE`
- `QUOTE_REQUIRES_APPROVAL`
- `FARE_MISMATCH`
- `VEHICLE_UNAVAILABLE`
- `COUPON_INVALID`
- `BOOKING_DUPLICATE`
- `BOOKING_CREATED`
- `MANUAL_DISPATCH_CREATED`

## Manual Dispatch Mode

Manual dispatch should be a first-class mode, not a recovery hack.

When automatic price fails:

1. Keep pickup and dropoff.
2. Mark `manual_dispatch = true`.
3. Tell the customer dispatch will confirm fare.
4. Continue collecting missing name, phone, and pickup time.
5. Show final summary.
6. Submit through a manual dispatch endpoint.

The booking record should include:

- pickup
- dropoff
- pickup time
- customer name
- customer phone
- passengers
- luggage
- note: fare to be confirmed
- source: chatbot
- status: pending dispatch/manual approval

## Place Intelligence

For production, avoid plain text-only locations.

Store:

- `place_id`
- formatted address
- lat/lng
- locality
- country
- type: airport, station, hotel, street, city, landmark
- whether the location is broad or exact

City-level drop-offs can be allowed, but they should be explicit:

"Do you want a general drop-off in Burlington, Vermont? Dispatch may contact you for an exact address."

If the customer selects an autocomplete result, treat it as a chosen candidate, not another text message to classify.

## Language And Tone

The bot should lock language per session:

- seed from browser locale
- update from clear user language
- change only when user asks

Use professional translated prompt templates for high-frequency workflow messages:

- ask pickup
- ask dropoff
- city confirmation
- quote confirmation
- manual dispatch consent
- ask name
- ask phone
- ask pickup time
- final summary
- booking confirmation
- fallback/error messages

Use AI localization only for dynamic or uncommon responses.

## Frontend Production Requirements

### Request Safety

Every chat request should include:

```json
{
  "session_id": "...",
  "client_message_id": "uuid",
  "message": "...",
  "client_locale": "nl-BE",
  "metadata": {}
}
```

The server should return the same response for duplicate `client_message_id`.

### In-Flight Guard

The frontend should prevent:

- double submit with Enter
- click while request is pending
- autocomplete selection plus form submit race
- quick-reply double click

### Structured Autocomplete Selection

When the customer selects a place, send:

```json
{
  "message": "Burlington, VT, USA",
  "metadata": {
    "source": "place_autocomplete",
    "place_id": "...",
    "description": "Burlington, VT, USA"
  }
}
```

### Saved Contact Prefill

Do not send saved contact as normal chat text. Send it as metadata:

```json
{
  "message": "yes",
  "metadata": {
    "action": "use_saved_contact",
    "customer_name": "Ismail",
    "customer_phone": "+32 467626575"
  }
}
```

## Safety And Reliability

### Idempotency

Use idempotency for:

- chat turns
- quote requests
- booking creation
- manual dispatch creation
- payment links

### User-Safe Errors

Never expose technical messages to customers:

- `pickup_lat`
- `pickup_lng`
- `raw`
- stack traces
- HTTP errors
- database errors
- API keys
- plugin internals

Map all failures to safe replies and log the technical detail.

### Audit Trail

Each turn should log:

- session ID
- client message ID
- user message
- state before
- extracted fields
- draft before
- draft after
- API calls made
- API result code
- state after
- assistant response

This makes transcript debugging fast.

### Privacy

Phone numbers and names are personal data. Production should include:

- retention policy
- admin-only transcript access
- masked phone display where possible
- export/delete support if needed
- no PII in third-party logs beyond what is needed for extraction

## Performance Plan

### Avoid Unnecessary AI Calls

Most taxi turns are simple. Use local rules and structured UI before AI.

### Cache

Cache:

- place search results
- fixed prompt translations
- company FAQ replies
- failed quote fallback classification
- route quotes where safe

### Timeouts

Suggested timeouts:

- local rules: immediate
- place search: 2-5 seconds
- quote: 5-12 seconds
- AI extraction: 3-8 seconds
- booking creation: 10-20 seconds

### Graceful Degradation

If AI is unavailable, the bot should still support:

- pickup
- dropoff
- name
- phone
- time
- quote
- manual dispatch
- booking creation

AI should improve the experience, not be required for the basic funnel.

## Testing Strategy

### Transcript Tests

Turn real transcripts into automated tests. The transcript from this discussion should become a fixture.

Expected behavior:

1. "My name is Ismail and my phone is +32..." stores name and phone.
2. Bot asks for pickup, not phone again.
3. "BTV airport" resolves to Burlington International Airport.
4. "Burlington city" becomes pending city dropoff.
5. "yes" confirms broad Burlington dropoff.
6. Quote failure enters manual dispatch mode.
7. Bot asks only for missing pickup time.
8. Booking submits as manual dispatch without requiring a fare.
9. No technical API message reaches the user.
10. No duplicate assistant prompts are stored.

### Unit Tests

Test:

- phone validation
- name validation
- date/time normalization
- affirmative/negative detection
- city-level confirmation
- next missing field
- manual dispatch payload
- quote unavailable fallback
- language lock
- duplicate message ID replay

### Integration Tests

Test with main RideFleet plugin:

- place search
- place resolve
- quote success
- quote unavailable
- manual dispatch create
- booking create
- coupon valid/invalid
- vehicle unavailable

### Browser Tests

Test:

- no double send
- quick replies
- autocomplete selection
- saved contact use
- reset/new booking
- mobile layout
- language-specific prompts

## Better Ideal Conversation

```text
User: My name is Ismail and my phone is +32 467626575
Bot: Thanks Ismail, I have your phone for this ride. Where should we pick you up?

User: BTV airport
Bot: Pickup set to Burlington International Airport. Where are you going?

User: Burlington city
Bot: Do you mean a general drop-off in Burlington, Vermont? Reply yes, or send an exact address, hotel, station, or landmark.

User: yes
Bot: I cannot get an exact automatic fare for this route. Dispatch will confirm the price before pickup. What pickup date and time should I request?

User: tomorrow 09:30
Bot: Ready to submit: Burlington International Airport to Burlington, Vermont, for Ismail at tomorrow 09:30. Dispatch will confirm the fare. Submit request?

User: yes
Bot: Done. Your request number is RF-1234. Dispatch will confirm the fare before pickup.
```

## Implementation Roadmap

### Phase 1: Stabilize Current Flow

- Fix manual dispatch payload and local booking path.
- Add `next_missing_field()` logic.
- Skip name/phone/time if already collected.
- Prevent duplicate frontend sends.
- Add client message IDs.
- Hide technical API messages.
- Improve city confirmation behavior.

### Phase 2: Improve Intelligence

- Introduce canonical booking draft.
- Add correction handling.
- Send autocomplete selections as structured metadata.
- Replace saved contact text injection with metadata.
- Add place IDs and coordinates to draft.
- Add better multilingual prompt templates.

### Phase 3: Harden API Contract

- Add stable API result codes in the main plugin.
- Add first-class manual dispatch endpoint.
- Add quote IDs or quote fingerprints.
- Add idempotency to manual dispatch and chat turns.
- Add typed API client responses in chatbot plugin.

### Phase 4: Production Observability

- Add structured turn audit logs.
- Add admin API/conversation diagnostics.
- Add admin transcript diagnostics.
- Add failure dashboards.
- Add rate limit and AI budget reporting.
- Add test fixtures for real transcripts.

### Phase 5: Premium Assistant Experience

- Add return trip flow.
- Add flight-aware pickup prompts.
- Add "same as last time" flows.
- Add admin-configurable prompt templates.
- Add WhatsApp/SMS handoff if configured.
- Add dispatch escalation when confidence is low.

## Final Checklist

Status legend used below:

- `[x]` implemented in the current codebase.
- `[ ]` not implemented yet.
- `(Partial)` started, but not complete enough to call production-finished.

### Conversation Brain

- [ ] Canonical booking draft exists. Current implementation still uses flat `collected_data`.
- [ ] (Partial) Every turn updates collected data before choosing the next action.
- [ ] (Partial) `next_missing_field()` determines the next prompt. Implemented as `next_contact_state()` for contact/time fields only. Passenger/luggage/flight-number states are also skipped when already collected via greedy one-shot extraction.
- [x] Already collected name is not requested again.
- [x] Already collected phone is not requested again.
- [x] Already collected pickup time is not requested again.
- [ ] (Partial) Corrections update existing fields instead of restarting. Some quote-stage corrections exist, but this is not complete.
- [x] Final summary is shown before submission via `confirm_booking_details`.

### AI Use

- [x] Local rules run before AI.
- [x] AI returns strict JSON only through `response_format: json_object` for classifier/extractor/localizer calls.
- [x] AI extracts fields but does not control booking state.
- [x] AI cannot invent prices, booking IDs, or policies in the booking flow.
- [ ] (Partial) Stronger model is used only for hard turns. Fast/quality split exists, but classifier/localizer calls can still be broader than ideal.
- [ ] (Partial) AI outage still allows basic booking. Local rules cover many turns, but one-shot extraction quality degrades.
- [x] Classifier and field extractor are merged into one AI call. Fields extracted by the classifier are reused in `apply_one_shot_fields()` instead of making a second round-trip (saves 300–800 ms per turn).
- [x] Greedy one-shot extraction now captures passenger count and luggage count from the opener, skipping those capture states when already present.
- [x] State advance after one-shot extraction uses `next_state_after_dropoff()` instead of hard-coding `quote_requested`, so flight-number and passenger states are properly skipped only when data is already collected.

### RideFleet Main Plugin API

- [ ] Place search endpoint is stable.
- [ ] Place resolve endpoint returns place ID, address, lat/lng, and type.
- [ ] Quote endpoint returns stable success/error codes.
- [x] Booking endpoint is idempotent for chatbot submissions with `Idempotency-Key` / `idempotency_key`.
- [ ] (Partial) Manual dispatch endpoint exists. It is implemented as a branch of `/create-booking`, not a separate endpoint.
- [ ] Coupon endpoint returns stable validation codes.
- [ ] Vehicle/extras/availability endpoints are available if needed.
- [ ] Chatbot API key has limited permissions only.

### Manual Dispatch

- [x] Manual dispatch is a first-class mode in chatbot submission.
- [x] No fare is required for manual dispatch.
- [x] No quote recalculation is required for manual dispatch in both remote REST and local same-site paths.
- [x] Booking is marked as requiring dispatch confirmation.
- [x] Customer is clearly told dispatch confirms fare.
- [x] Dispatch receives all required collected details.
- [x] Technical quote errors are mapped away from customers in the chatbot flow.

### Locations

- [x] Autocomplete selections are sent as structured metadata.
- [x] Place IDs are stored when returned by place search.
- [x] Lat/lng are resolved through place details and stored when available.
- [x] Lat/lng are passed to core pricing and booking submission when available.
- [x] Place search uses shorter timeouts and transient caching.
- [x] Broad city drop-offs require explicit confirmation.
- [x] Selected candidate can confirm a pending city.
- [x] Ambiguous locations show choices. Disambiguation candidate list uses geographic bias (pickup coords or service-area centre) so the nearest region ranks first.
- [x] Text-only fallback still works.

### Frontend

- [x] One in-flight message at a time.
- [x] Every request has `client_message_id`.
- [x] Duplicate clicks are blocked client-side, and duplicate message IDs replay server-side.
- [x] Quick replies cannot be double-submitted.
- [x] Saved contact is sent as metadata, not chat text.
- [x] Timeout state is friendly and retryable.
- [ ] Mobile layout is verified.
- [x] Header uses taxi icon, not truck icon.
- [x] Autocomplete selection disables suggestion buttons while place details resolve.

### Language

- [x] Session language is locked.
- [x] User can explicitly switch language.
- [ ] High-frequency workflow prompts are professionally translated.
- [ ] (Partial) No random English fallback inside Dutch/French conversations. Phone validation and Belgian phone notes are localized; all edge/failure text still needs professional copy review.
- [x] Admin transcript stores language, intent, extracted fields, and summaries.

### Safety

- [x] No internal API errors shown to users in the main chatbot booking flow.
- [ ] PII is protected in logs and admin screens.
- [x] Rate limits exist for chat, places, booking, and coupon validation.
- [x] AI daily budget/circuit breaker is active.
- [x] OpenRouter model fallback is in place for invalid/unavailable selected models.
- [ ] (Partial) All customer-facing failures have safe messages.
- [x] Booking creation is idempotent for chatbot submissions.
- [x] Chat turns are idempotent by `client_message_id`.

### Observability

- [x] Turn audit log includes state before/after through the API Logs diagnostic event table.
- [x] Extracted fields are stored.
- [x] API calls and summarized results are stored as structured diagnostic events.
- [x] Failed quote and failed booking reasons are logged in booking events/error paths.
- [x] Duplicate turn detection is visible in admin API Logs.
- [ ] Real transcripts can be replayed in tests.

### Testing

- [ ] Transcript fixture for this discussion exists.
- [ ] Unit tests cover validators and next missing field.
- [ ] Integration tests cover quote, booking, and manual dispatch.
- [ ] Browser tests cover autocomplete and double-send prevention.
- [ ] Multilingual tests cover English, Dutch, and French.
- [ ] Regression tests verify no duplicate prompts.

### Production Launch

- [ ] Main plugin API URL and key configured.
- [ ] Google Places configured or fallback behavior documented.
- [ ] Dispatch contact configured.
- [ ] Data retention configured.
- [ ] Admin notifications configured.
- [x] Error logging reviewed for the changed paths.
- [ ] Test booking flow completed end to end.
- [ ] Manual dispatch flow completed end to end.
- [ ] Payment flow verified if enabled.
- [ ] Final UI checked on desktop and mobile.

## 2026-05-22 Hardening Pass

This pass addresses the issues found in the exported API log discussion and the saved-contact follow-up.

- OpenRouter calls now retry with known fallback models when the configured model returns invalid-model style failures such as HTTP 400/404. Fallback attempts are recorded in the diagnostic API log.
- "New booking" is classified locally before generic location detection, so phrases like `new booking` are not treated as pickup places in diagnostics.
- Active capture states now take priority in local classification. During `capture_name`, a simple name such as `Ismail` is treated as a name; during `capture_phone`, invalid phone text stays phone validation instead of becoming a location.
- Starting a new booking from a completed session preserves known name/phone and tells the customer those details are being reused.
- Manual-dispatch bookings are created as `pending_dispatch` with `fare_pending` payment status, instead of being represented as confirmed zero-fare bookings.
- The chatbot booking event log now records manual-dispatch submissions as `pending_dispatch`.
- The main booking admin views now include `pending_dispatch` in pending counts, filters, badges, and calendar coloring.
- Place autocomplete now has a place-details step. On selection, the widget resolves coordinates from the main RideFleet plugin and sends them as structured metadata.
- The chatbot stores selected place coordinates and forwards them to quote and booking requests, reducing failures for broad or formatted addresses.
- Place search and place details are cached and use shorter upstream timeouts, reducing slow Google/API calls.
- Dutch/French phone notes and invalid-phone validation are no longer mixed with English in the common capture path.
