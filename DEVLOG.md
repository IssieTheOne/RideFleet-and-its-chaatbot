# RideFleet Plugin Suite — Development Log & Production Guide

> **Plugins:** `ridefleet-ai-chatbot` · `ridefleet-booking`  
> **Repository:** https://github.com/IssieTheOne/RideFleet-and-its-chaatbot  
> **Last updated:** 2026-05-21  
> **Status:** Production-ready (v1.0.0)

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [What Was Built — Chatbot Plugin](#2-what-was-built--chatbot-plugin)
3. [What Was Built — Booking Plugin](#3-what-was-built--booking-plugin)
4. [Integration Between the Two Plugins](#4-integration-between-the-two-plugins)
5. [Production Hardening](#5-production-hardening)
6. [Admin Panel Guide](#6-admin-panel-guide)
7. [Over-the-Air (OTA) Updates](#7-over-the-air-ota-updates)
8. [Licensing](#8-licensing)
9. [CI / Automated Tests](#9-ci--automated-tests)
10. [Security Checklist](#10-security-checklist)
11. [Deferred Items](#11-deferred-items)

---

## 1. Architecture Overview

```
┌──────────────────────────────────────────────────────────────┐
│  WordPress Site                                              │
│                                                              │
│  ┌─────────────────────┐    REST API    ┌─────────────────┐  │
│  │  ridefleet-          │◄─────────────►│  ridefleet-     │  │
│  │  ai-chatbot          │               │  booking        │  │
│  │                      │               │                 │  │
│  │  ConversationEngine  │               │  PricingEngine  │  │
│  │  OpenRouterClient    │               │  CouponService  │  │
│  │  SessionRepository   │               │  RouteService   │  │
│  │  UsageMeter          │               │  GeofenceZones  │  │
│  │  SessionToken (HMAC) │               │  CoreApiClient  │  │
│  └─────────────────────┘               └─────────────────┘  │
│           │                                     │            │
│           ▼                                     ▼            │
│    wp_rfac_* tables                    wp_rfb_* tables       │
└──────────────────────────────────────────────────────────────┘
           │                                     │
           ▼                                     ▼
    OpenRouter API                       Google Maps API
    (AI completions)                     (Geocoding + Places)
```

### Database Tables

| Table | Plugin | Purpose |
|---|---|---|
| `wp_rfac_chat_sessions` | chatbot | One row per user session |
| `wp_rfac_chat_messages` | chatbot | Full conversation history |
| `wp_rfac_booking_events` | chatbot | Booking outcomes & quotes |
| `wp_rfac_change_requests` | chatbot | Post-booking change / price negotiation |
| `wp_rfac_error_log` | chatbot | Structured error telemetry |
| `wp_rfac_ai_usage` | chatbot | Daily token spend + per-IP request counts |
| `wp_rfb_vehicles` | booking | Vehicle catalog |
| `wp_rfb_extras` | booking | Optional extras (child seat, etc.) |
| `wp_rfb_routes` | booking | Fixed-price route overrides |
| `wp_rfb_coupons` | booking | Coupon / promo codes |
| `wp_rfb_geofence_zones` | booking | Service area polygon/radius zones |
| `wp_rfb_bookings` | booking | Confirmed bookings |
| `wp_rfb_booking_extras` | booking | Many-to-many: bookings ↔ extras |

---

## 2. What Was Built — Chatbot Plugin

### Frontend Widget (`assets/frontend/`)

| Feature | Detail |
|---|---|
| **Animated typing indicator** | 3-dot bounce animation; CSS bug fixed so `[hidden]` correctly hides it |
| **Smart autocomplete** | `looksLikeAddress()` skips Places API on conversational phrases ("Hi how are you?", "I want a discount") — only fires on real address signals (digits, commas, street keywords, or 2+ capitalised words) |
| **Quick-reply chips** | Contextual chips per conversation state: Confirm/Negotiate/Change (price confirm), 1–8 (passengers), 0–5 (luggage), Yes/No (questions) |
| **Native datetime picker** | `datetime-local` input for pickup time with `min` set to 5 minutes from now |
| **New chat reset button** | Circular-arrow button clears localStorage session, resets UI to default greeting |
| **Language detection** | Browser locale (`navigator.language`) sent as `client_locale` on every request; engine seeds session language on first turn |
| **Accessibility** | `role="dialog"`, `aria-modal`, `role="log"`, `aria-live="polite"`, Esc-to-close, focus trap |
| **HMAC session tokens** | Session IDs signed with HMAC-SHA256; stored in localStorage; verified server-side on every request |

### Conversation Engine (`includes/Services/ConversationEngine.php`)

- **State machine:** `capture_pickup` → `capture_dropoff` → `capture_passengers` → `capture_luggage` → `capture_pickup_time` → `confirm_price` → `capture_contact` → `booking_complete`
- **Language lock:** Detects user's preferred language (EN/FR/NL) from message content or browser locale; locks for the session; responds in kind
- **Coupon detection:** Regex matches `coupon SAVE10`, `promo: NEWYEAR2026`, `voucher WELCOME-25`, `kortingscode HOLIDAY5`, `code promo ETE2026`, etc. Validates immediately with booking plugin; gives user real-time "applied" or "invalid" feedback
- **Service area awareness:** Reads `pickup_allowed` / `dropoff_allowed` from price quote; appends localised warning in EN/FR/NL if location is outside service area
- **Smart location parsing:** `broad_location_name()` heuristic normalises city-only inputs globally (e.g. "new york city" → "New York"); no hardcoded city lists
- **Change requests & price negotiation:** Customer can request changes or counter-offer after booking; stored as `wp_rfac_change_requests`; admin notified via email

### AI Layer (`includes/Services/OpenRouterClient.php`)

- **Circuit breaker:** 4 consecutive failures → 120-second cooldown (stored in WP transients)
- **Budget guard:** Configurable daily token budget; requests rejected when exceeded
- **Methods:** `classify_turn()`, `localize_reply()`, `rewrite_text()` (4 modes: concise/professional/friendly/grammar)
- **Telemetry:** Every call records `tokens_in` + `tokens_out` to `wp_rfac_ai_usage`

### Admin Pages

| Page | What It Shows |
|---|---|
| **Dashboard** | Stat cards (sessions today/week, bookings today/week, live AI spend), recent activity (last 5 sessions + last 5 bookings) |
| **Settings** | Company info, theme colour picker with live preview, model picker (10 popular models + custom), AI bio rewrite buttons, production safeguards (token budget / per-IP cap), OTA update endpoint + license key |
| **Chat History** | Session cards with state pill, language pill, route preview; filter by state/language; full-text search; chat bubble transcript with extracted fields |
| **Change Requests** | Status tabs (Pending/Approved/Rejected/Counteroffer); price negotiation block with original → proposed price diff; per-card resolution form |
| **Error Log** | Level tabs with counts; source chips filter; purge button (entries older than 30 days) |
| **Privacy / GDPR** | Search by any PII; preview match counts; cascade-delete across all 4 tables with audit log |

---

## 3. What Was Built — Booking Plugin

### Core Pricing Engine

- **Fixed routes:** Checks `wp_rfb_routes` first; falls back to metered calculation
- **Service area geofencing:** Validates pickup and dropoff against `wp_rfb_geofence_zones`; returns `pickup_allowed`, `dropoff_allowed`, `requires_approval` flags
- **Geocoding with graceful degradation:** On success, persists coordinates to `wp_options` (non-autoloaded) as last-known-good. On Google API failure, returns stored coordinates with `degraded: true` flag instead of hard-failing

### Coupon Service (`includes/Booking/CouponService.php`)

Atomic `mark_used()` — single SQL statement, no TOCTOU race:

```sql
UPDATE wp_rfb_coupons
   SET used_count = used_count + 1, updated_at = NOW()
 WHERE code = %s
   AND is_active = 1
   AND (starts_at IS NULL OR starts_at <= NOW())
   AND (ends_at   IS NULL OR ends_at   >= NOW())
   AND (usage_limit IS NULL OR used_count < usage_limit)
 LIMIT 1
```

Returns `true` only if `affected_rows > 0`. No coupon can ever be over-redeemed, even under concurrent requests.

### REST Endpoints

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/taxi-booking/v1/calculate-price` | Quote a trip; returns price, service area flags, route type |
| `POST` | `/taxi-booking/v1/create-booking` | Create booking; supports `Idempotency-Key` header |
| `POST` | `/taxi-booking/v1/validate-coupon` | Validate a coupon code without redeeming it |

**Idempotency:** Booking endpoint caches the response in a 1-hour transient keyed on `rfb_idem_{sha256(payload)}`. Retries return the identical response; no double-booking possible.

---

## 4. Integration Between the Two Plugins

The chatbot calls the booking plugin's REST API as if it were an external service. This means they can run on the same WordPress installation or on different servers — the integration is URL-based.

### Call Flow

```
User message → ConversationEngine
    → OpenRouterClient (classify intent)
    → CoreApiClient::calculate_price()
        → POST /taxi-booking/v1/calculate-price
        ← { price, currency, service_area, requires_approval }
    ← ConversationEngine builds confirm_price state
    → User confirms
    → CoreApiClient::submit_booking()
        → POST /taxi-booking/v1/create-booking  [+ Idempotency-Key]
        → CouponService::mark_used() (atomic)
        ← { booking_id, status }
    ← ConversationEngine → booking_complete state
```

### Configuration

In **RideFleet Chatbot → Settings**, set:

- **Core API URL** — base URL of the booking plugin site (e.g. `https://yourtaxisite.com`)
- **Core API Key** — the secret shared between the two plugins

In **RideFleet Booking → Settings**, add the chatbot's key to the allowed API keys list.

---

## 5. Production Hardening

### Security

| Feature | Implementation |
|---|---|
| HMAC-signed session tokens | `SessionToken::sign()` / `::verify()` — constant-time `hash_equals()` comparison; legacy unsigned IDs accepted for backward compat |
| Per-IP daily request cap | `UsageMeter::ip_over_limit()` — hashed IP stored in `wp_rfac_ai_usage` |
| Daily token budget | `UsageMeter::over_budget()` — configurable in admin |
| Circuit breaker | 4 failures → 120s open; prevents cascading OpenRouter failures |
| Coupon TOCTOU fix | Atomic `UPDATE ... WHERE used_count < usage_limit` |
| Idempotent bookings | `Idempotency-Key` header; transient-cached for 1 hour |
| Secrets redacted in logs | `Logger::sanitize_context()` redacts keys matching `/key|secret|token|password|authorization/i` |
| Uninstall cleanup | Both plugins drop all tables and options on uninstall; no data leaks |

### GDPR

- **Data retention:** Configurable in admin (default: 90 days); cron job purges expired sessions + messages
- **Right to erasure:** Privacy admin page — search by any PII → preview → cascade delete across all 4 tables; action audit-logged
- **What is stored:** Session key (UUID), message text, extracted trip fields, IP hash (never raw IP)

### Error Telemetry

Structured logs written to `wp_rfac_error_log` via `Logger::log()`. Viewable in **Error Log** admin page. Auto-purged after 30 days. Falls back to `error_log()` if DB write fails.

---

## 6. Admin Panel Guide

### First-Time Setup Checklist

1. **RideFleet Booking → Settings**
   - Add your Google Maps API key
   - Configure vehicles, extras, and service area zones

2. **RideFleet Chatbot → Settings**
   - Paste your OpenRouter API key
   - Set Core API URL + Core API Key (points to booking plugin)
   - Choose AI model (recommended: `google/gemini-flash-1.5`)
   - Set notification email (defaults to WordPress admin email)
   - Set AI daily token budget (recommended: 250 000 tokens/day to start)
   - Set per-IP daily cap (recommended: 200 requests/day)

3. **Test connections** using the "Test" buttons on the Settings page — both should return green ✓

4. Add the chatbot to any page with the shortcode `[ridefleet_chatbot]`

### Daily Monitoring

- **Dashboard → Production Safeguards panel:** today's token spend vs budget
- **Error Log:** check for Critical/Error level entries after deployments
- **Change Requests:** review Pending items and action them

---

## 7. Over-the-Air (OTA) Updates

Both plugins include a full `UpdateChecker` class that hooks into WordPress's native update system. When configured, plugins will show "Update available" in **Dashboard → Plugins** exactly like any WordPress.org plugin — customers click "Update now" and it just works.

### How It Works

1. WordPress periodically fires `pre_set_site_transient_update_plugins`
2. `UpdateChecker::inject_update()` fetches a JSON manifest from your configured endpoint (cached for 6 hours)
3. If the manifest version is newer than the installed version, it injects an update record into the WordPress transient
4. WordPress shows the update badge; clicking "Update now" downloads from the URL in the manifest
5. Your license key + site URL are sent as headers on the manifest fetch and as query parameters on the download URL — your server validates them before serving the file

### Manifest Format

Host a JSON file at a stable URL (e.g. `https://updates.yourdomain.com/ridefleet-chatbot/manifest.json`):

```json
{
  "version": "1.1.0",
  "requires": "6.0",
  "tested": "6.5",
  "requires_php": "8.1",
  "download_url": "https://updates.yourdomain.com/ridefleet-chatbot/ridefleet-ai-chatbot-1.1.0.zip",
  "last_updated": "2026-06-01",
  "sections": {
    "description": "AI-powered chatbot for taxi and fleet booking.",
    "changelog": "<h4>1.1.0</h4><ul><li>Added multi-vehicle selection</li></ul>"
  }
}
```

### Setting Up the Update Server

#### Option A — GitHub Releases (simplest, free)

1. Create a GitHub Release tagged `v1.1.0` and attach the plugin ZIP
2. Host the manifest JSON at a static URL (GitHub Pages, Cloudflare Pages, Vercel, etc.)
3. Use the GitHub Release download URL directly in `download_url`
4. **No license validation** — anyone with the manifest URL can download. Suitable if you distribute the plugin for free or want to rely on honour-system licensing.

```
Manifest URL example:
https://yourusername.github.io/ridefleet-updates/chatbot/manifest.json
```

#### Option B — Self-hosted with License Validation (recommended for paid plugins)

Build a small API endpoint (PHP, Node, Python — anything) that:

1. Receives `GET /manifest?license=KEY&site=https://customer.com`
2. Validates the license key against your database
3. Returns the manifest JSON if valid, `403` if not
4. For the download URL: a signed, time-limited URL (e.g. via AWS S3 presigned URLs or your own token system)

Example minimal PHP endpoint:

```php
<?php
// /var/www/updates/chatbot/manifest.php

$license = $_GET['license'] ?? '';
$site    = $_GET['site']    ?? '';

// Validate against your licenses DB
if (!valid_license($license, $site)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid license']);
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    'version'      => '1.1.0',
    'requires'     => '6.0',
    'tested'       => '6.5',
    'requires_php' => '8.1',
    'download_url' => 'https://updates.yourdomain.com/download?license=' . urlencode($license) . '&v=1.1.0',
    'last_updated' => '2026-06-01',
    'sections'     => [
        'changelog' => '<h4>1.1.0</h4><ul><li>Improvements</li></ul>',
    ],
]);
```

#### Option C — Use an existing update service

- **Freemius** — Full SaaS: handles licenses, payments, updates, telemetry. Free tier available. Best for commercial plugins.
- **Easy Digital Downloads + Software Licensing** — Self-hosted. Mature, widely used in the WordPress ecosystem.
- **WP Update Server** — Open-source self-hosted update server by YahnisElsts (same author as `plugin-update-checker` library).

### Configuring the Plugin

In **RideFleet Chatbot → Settings → Over-the-air Updates**:

| Field | Value |
|---|---|
| Update Manifest URL | `https://updates.yourdomain.com/chatbot/manifest.json` |
| License Key | The key issued to this customer |

Same fields exist for **RideFleet Booking → Settings**.

The plugin sends these headers on the manifest fetch:

```
X-License-Key: customer-license-key-here
X-Site-URL: https://customer-site.com
```

And appends these query params on the ZIP download:

```
?license=customer-license-key-here&site=https%3A%2F%2Fcustomer-site.com
```

---

## 8. Licensing

### Approaches

| Model | Complexity | Revenue model |
|---|---|---|
| **Free / open source (GPL)** | None — just distribute on GitHub | Donations, support contracts |
| **Freemium (Freemius)** | Low — integrate their SDK | Paid upgrade tiers, annual renewals |
| **Manual license keys** | Medium — build your own validation API | One-time or annual per-site licenses |
| **SaaS / hosted chatbot** | High — multi-tenant infrastructure | Monthly subscription per site |

### Recommended Path for Commercial Launch

1. Sign up at **freemius.com** (free account)
2. Create a product for each plugin
3. Replace the `UpdateChecker` class with Freemius's SDK — it handles everything: checkout, license issuance, update delivery, deactivation tracking
4. Set pricing tiers (e.g. Single site / 3 sites / Unlimited)
5. Freemius hosts the update manifest and ZIP delivery for you

Freemius takes ~7% of revenue. This is worth it to avoid building the licensing/update infrastructure yourself.

### If You Want Self-Hosted Licensing

The `UpdateChecker` class is already wired up — you just need to build the manifest endpoint described in [§7 Option B](#option-b--self-hosted-with-license-validation-recommended-for-paid-plugins). A basic license table looks like:

```sql
CREATE TABLE licenses (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    license_key   VARCHAR(64) UNIQUE NOT NULL,
    email         VARCHAR(255) NOT NULL,
    plan          ENUM('single','agency','unlimited') DEFAULT 'single',
    site_limit    INT DEFAULT 1,
    sites         JSON,            -- array of activated site URLs
    expires_at    DATETIME,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

On activation: add site URL to `sites` array if `JSON_LENGTH(sites) < site_limit`.  
On manifest fetch: verify `license_key` exists, is not expired, and the requesting `site` is in `sites`.

---

## 9. CI / Automated Tests

### GitHub Actions (`.github/workflows/ci.yml`)

Two jobs run on every push and pull request:

**Job 1 — PHP Lint (matrix: 8.1, 8.2, 8.3)**
```
php -l on every .php file
WPCS advisory scan (continue-on-error: true — warnings don't break the build)
```

**Job 2 — PHPUnit**
```
composer install in tests/
./vendor/bin/phpunit --testdox
```

### Test Cases (`tests/cases/`)

| File | What it tests |
|---|---|
| `SessionTokenTest.php` | Sign/verify HMAC tokens; tamper detection; legacy unsigned ID compat |
| `DetectCouponTest.php` | Coupon regex — 7 valid cases, 5 invalid cases |
| `LoggerSanitizationTest.php` | Secret redaction in log context (flat and nested) |

### Running Tests Locally

```bash
cd wp-content/plugins/tests
composer install
./vendor/bin/phpunit --testdox
```

### Adding a New Test

1. Create `tests/cases/YourTest.php` extending `PHPUnit\Framework\TestCase`
2. Use `Brain\Monkey\Functions\stubs()` to stub WordPress functions you need
3. Run `phpunit` — it auto-discovers all `*Test.php` files in `tests/cases/`

---

## 10. Security Checklist

Before going live, verify each item:

- [ ] OpenRouter API key is in WordPress options (encrypted at rest if you use a plugin like WP Encryption), never hard-coded
- [ ] Google Maps API key has HTTP referrer restrictions set to your domain only
- [ ] Core API shared secret is a random 32+ character string (use `wp_generate_password(32)`)
- [ ] HMAC signing secret was auto-generated on install (check `rfac_settings` → `session_signing_secret` is not empty)
- [ ] `uninstall.php` reviewed — confirm you want all data deleted on uninstall
- [ ] Rate limiting: per-IP cap set appropriately for your traffic (start at 200/day)
- [ ] Daily token budget set (start at 250 000 tokens/day ≈ $0.25/day on Gemini Flash)
- [ ] Error log page is only accessible to `manage_options` capability users
- [ ] Privacy page reviewed — test the PII search + erase flow before a real erasure request
- [ ] GDPR data retention set (default 90 days) — adjust to match your privacy policy
- [ ] SSL certificate active on site (sessions are transmitted in HTTP headers)
- [ ] WordPress debug mode OFF (`WP_DEBUG=false`) in production — errors must not leak to frontend
- [ ] Circuit breaker threshold reviewed (default: 4 failures / 120s cooldown)

---

## 11. Deferred Items

These were explicitly out of scope for this phase:

| Item | Notes |
|---|---|
| **Stripe / payment hooks** | Booking plugin creates bookings as `pending_payment`; wiring Stripe webhooks to flip status is a separate milestone |
| **SMS / Slack admin notifications** | `Notifier.php` currently sends email only; Twilio/Slack webhook fallback can be added as channels in that class |
| **i18n / .po translations** | All user-facing strings are in `ConversationEngine::say()` — already EN/FR/NL; formal WordPress `.pot` file extraction and Transifex integration deferred |
| **Deep accessibility audit** | Basics done (ARIA roles, focus trap, Esc key); a full WCAG 2.1 AA audit with screen reader testing is recommended before public release |
| **Stripe Connect for fleet owners** | Multi-operator payout splitting — future milestone |
| **Push notifications** | Browser push for booking confirmations — future milestone |
| **OTA update server** | Client-side plumbing is done; the actual manifest host + download server needs to be built (see §7) |

---

## Changelog

### v1.0.0 — 2026-05-21

**RideFleet AI Chatbot**
- Complete chatbot state machine with 8-step booking flow
- OpenRouter AI integration with circuit breaker + budget guard
- HMAC-signed session tokens
- Per-IP rate limiting
- Real-time coupon detection and validation
- Service area awareness with localised warnings
- Smart address autocomplete (skips conversational input)
- Quick-reply chips per conversation state
- Native datetime picker for pickup time
- Multilingual support (EN/FR/NL) with language lock
- AI bio rewrite (4 modes)
- New chat reset button
- Typing indicator CSS bug fixed
- Fully redesigned admin: Dashboard, Settings, Chat History, Change Requests, Error Log, Privacy/GDPR
- Structured error telemetry with sanitised context
- OTA update checker
- PHPUnit test suite with Brain Monkey stubs
- GitHub Actions CI (PHP 8.1/8.2/8.3 lint + WPCS + PHPUnit)
- GDPR cascade delete with audit log
- Uninstall cleanup

**RideFleet Booking**
- Atomic coupon redemption (TOCTOU-safe)
- Idempotent booking endpoint
- Geocoding graceful degradation (last-known-good fallback)
- Coupon validation REST endpoint
- OTA update checker
- Uninstall cleanup
