# Driver Notification & Reminder System

**Plugin:** `ridefleet-booking`  
**Status:** Design — ready to implement  
**Scope:** Email-first. Every layer is built to accept SMS (or any other channel) as a drop-in addition later without touching business logic.

---

## 1. The Problem

A booking made 90 days in advance generates a confirmation email today. That email is completely useless to the driver on the day of the trip — it's buried, forgotten, and the action links inside it are cold. The driver needs the right information at the right moment, not a single fire-and-forget blast.

---

## 2. The Ideal Flow (solo operator)

```
Customer books (any date in the future)
  │
  ├─ Operator gets: awareness email + .ics calendar attachment
  │   → Event lands in phone calendar with built-in reminder
  │
  ├─ T-24h: automated "Tomorrow's job" reminder email
  │   → Full details, Google Maps link, fresh action links
  │
  ├─ T-2h: automated "In 2 hours" reminder email   ← the operational one
  │   → Big tap-to-confirm buttons: [I'm on my way] [Job done]
  │
  ├─ Driver taps "I'm on my way"
  │   → No login. Token validates, booking status → en_route
  │   → Customer gets: "Your driver is on the way" email
  │
  └─ Driver taps "Job done"
      → Booking status → completed
      → Customer gets: receipt / review request (already built)
```

No app. No login. No hunting through old emails. The right notification arrives when it matters.

---

## 3. Architecture: Notification Channel Abstraction

This is the most important architectural decision. All notification dispatch goes through a channel interface so that adding SMS later means registering one new class — nothing else changes.

```
DriverNotificationDispatcher
  │
  ├─ EmailDriverChannel        ← built now
  └─ SmsDriverChannel          ← registered later (Twilio, MessageBird, etc.)
```

### 3.1 The channel contract

```php
// includes/Notifications/Contracts/DriverNotificationChannel.php

namespace RideFleetBooking\Notifications\Contracts;

interface DriverNotificationChannel {
    /**
     * Send a notification to the driver/operator.
     *
     * @param string              $recipient  Email address OR phone number depending on channel.
     * @param DriverNotification  $notification
     * @return bool               True if the channel accepted the message (not necessarily delivered).
     */
    public function send(string $recipient, DriverNotification $notification): bool;

    /**
     * Whether this channel is configured and ready to use.
     * Guards against sending via an unconfigured SMS gateway, etc.
     */
    public function is_ready(): bool;
}
```

### 3.2 The notification value object

```php
// includes/Notifications/DriverNotification.php

namespace RideFleetBooking\Notifications;

final class DriverNotification {
    // Notification type — determines template and urgency
    public const TYPE_BOOKING_NEW    = 'booking_new';    // sent on booking creation
    public const TYPE_REMINDER_24H   = 'reminder_24h';   // sent ~24h before pickup
    public const TYPE_REMINDER_2H    = 'reminder_2h';    // sent ~2h before pickup
    public const TYPE_BOOKING_CANCEL = 'booking_cancel'; // sent when booking cancelled

    public string $type;
    public object $booking;      // row from rfb_bookings
    public ?object $customer;    // row from rfb_customers (nullable)
    public string $token_enroute;   // signed token for "I'm on my way" action
    public string $token_complete;  // signed token for "Job done" action
    public string $url_enroute;     // full URL the driver taps
    public string $url_complete;    // full URL the driver taps
    public string $url_maps;        // Google Maps directions URL

    /** Short plain-text summary — used directly by SMS channel, as preview by email */
    public string $sms_body;
}
```

### 3.3 The dispatcher

```php
// includes/Notifications/DriverNotificationDispatcher.php

namespace RideFleetBooking\Notifications;

use RideFleetBooking\Notifications\Contracts\DriverNotificationChannel;

final class DriverNotificationDispatcher {
    /** @var DriverNotificationChannel[] */
    private static array $channels = [];

    public static function register(DriverNotificationChannel $channel): void {
        self::$channels[] = $channel;
    }

    public static function dispatch(DriverNotification $notification, string $recipient): void {
        foreach (self::$channels as $channel) {
            if (!$channel->is_ready()) {
                continue;
            }
            try {
                $channel->send($recipient, $notification);
            } catch (\Throwable $e) {
                // Log and continue — one channel failure must not block others
                error_log('[RideFleet] Driver notification channel error: ' . $e->getMessage());
            }
        }
    }
}
```

### 3.4 Channel registration (plugin bootstrap)

```php
// In ridefleet-booking.php or a Providers/NotificationProvider.php

use RideFleetBooking\Notifications\DriverNotificationDispatcher;
use RideFleetBooking\Notifications\Channels\EmailDriverChannel;

DriverNotificationDispatcher::register(new EmailDriverChannel());

// Future — uncomment when Twilio credentials are configured:
// DriverNotificationDispatcher::register(new SmsDriverChannel());
```

Adding SMS later = one line of code. Nothing else changes.

---

## 4. Database Changes

### 4.1 New table: `rfb_booking_tokens`

Stores short-lived signed tokens for each driver action link.

```sql
CREATE TABLE {prefix}rfb_booking_tokens (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token       CHAR(64)        NOT NULL,           -- hex(32 bytes random)
    booking_id  BIGINT UNSIGNED NOT NULL,
    action      VARCHAR(32)     NOT NULL,           -- 'enroute' | 'complete'
    expires_at  DATETIME        NOT NULL,           -- pickup_at + 48h
    used_at     DATETIME        DEFAULT NULL,       -- set on first use (single-use)
    created_at  DATETIME        NOT NULL,
    PRIMARY KEY (id),
    UNIQUE  KEY uq_token      (token),
    KEY         idx_booking   (booking_id),
    KEY         idx_expires   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Token lifetime:** expires 48 hours after the scheduled pickup time. This means if a trip runs late, the driver can still mark it complete.  
**Single-use:** once `used_at` is set, the token is rejected on reuse.  
**Fresh tokens per reminder:** each reminder email generates new tokens. Old tokens from the original booking email remain valid until expiry (belt-and-suspenders), but the reminder email always carries fresh ones.

### 4.2 New columns on `rfb_bookings`

```sql
ALTER TABLE {prefix}rfb_bookings
    ADD COLUMN driver_status        VARCHAR(32)  DEFAULT NULL        AFTER status,
    ADD COLUMN driver_notified_at   DATETIME     DEFAULT NULL        AFTER driver_status,
    ADD COLUMN reminder_24h_sent_at DATETIME     DEFAULT NULL        AFTER driver_notified_at,
    ADD COLUMN reminder_2h_sent_at  DATETIME     DEFAULT NULL        AFTER reminder_24h_sent_at;
```

`driver_status` values: `NULL` (not yet dispatched) → `en_route` → `completed`

These columns are checked by the reminder scheduler to avoid duplicate sends.

---

## 5. Token Service

```php
// includes/Notifications/BookingTokenService.php

namespace RideFleetBooking\Notifications;

final class BookingTokenService {

    /**
     * Generate a pair of tokens (enroute + complete) for a booking.
     * Existing unexpired tokens are NOT invalidated — both old and new work until expiry.
     * This means reminder emails can always carry fresh tokens.
     *
     * @return array{enroute: string, complete: string}
     */
    public static function generate_pair(int $booking_id, string $pickup_at): array {
        $expires_at = date('Y-m-d H:i:s', strtotime($pickup_at) + 48 * 3600);

        return [
            'enroute'  => self::create($booking_id, 'enroute',  $expires_at),
            'complete' => self::create($booking_id, 'complete', $expires_at),
        ];
    }

    /**
     * Validate a token and return the booking row if valid.
     * Returns null if the token is unknown, expired, or already used.
     */
    public static function consume(string $token): ?object {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rfb_booking_tokens
             WHERE token = %s AND used_at IS NULL AND expires_at > %s
             LIMIT 1",
            $token,
            current_time('mysql')
        ));

        if (!$row) {
            return null;
        }

        // Mark as used immediately (prevent replay)
        $wpdb->update(
            $wpdb->prefix . 'rfb_booking_tokens',
            ['used_at' => current_time('mysql')],
            ['id' => (int) $row->id],
            ['%s'], ['%d']
        );

        // Attach action for the caller
        $booking = (object) array_merge(
            (array) \RideFleetBooking\Booking\BookingRepository::find((int) $row->booking_id),
            ['_token_action' => (string) $row->action]
        );

        return $booking ?: null;
    }

    private static function create(int $booking_id, string $action, string $expires_at): string {
        global $wpdb;
        $token = bin2hex(random_bytes(32)); // 64 hex chars
        $wpdb->insert(
            $wpdb->prefix . 'rfb_booking_tokens',
            [
                'token'      => $token,
                'booking_id' => $booking_id,
                'action'     => $action,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s']
        );
        return $token;
    }
}
```

---

## 6. Public Action Endpoint

The driver taps a link like:
```
https://yoursite.com/wp-json/rfb/v1/driver-action?token=abc123def456...
```

No login. No cookies. The token IS the authentication.

```php
// includes/Api/DriverActionEndpoint.php

namespace RideFleetBooking\Api;

use RideFleetBooking\Notifications\BookingTokenService;
use RideFleetBooking\Notifications\CustomerNotifier;

final class DriverActionEndpoint {

    public static function register(): void {
        register_rest_route('rfb/v1', '/driver-action', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true', // token IS the auth
            'args'                => [
                'token' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public static function handle(\WP_REST_Request $request): void {
        $token   = (string) $request->get_param('token');
        $booking = BookingTokenService::consume($token);

        // Always return a full HTML page — the driver is on a phone, not calling an API
        status_header(200);
        header('Content-Type: text/html; charset=UTF-8');

        if (!$booking) {
            self::render_page('error', [
                'title'   => 'Link expired or already used',
                'message' => 'This link has either expired or was already tapped. Check your latest reminder email for a fresh link.',
            ]);
            exit;
        }

        $action = (string) ($booking->_token_action ?? '');

        if ('enroute' === $action) {
            self::apply_status($booking, 'en_route');
            CustomerNotifier::driver_on_the_way((int) $booking->id);
            self::render_page('success', [
                'emoji'   => '🚗',
                'title'   => 'On your way!',
                'message' => 'Status updated. The customer has been notified that you\'re on the way.',
                'booking' => $booking,
            ]);
        } elseif ('complete' === $action) {
            self::apply_status($booking, 'completed');
            // booking_completed() already sends the review email to customer
            do_action('rfb_booking_status_changed', (int) $booking->id, 'completed');
            self::render_page('success', [
                'emoji'   => '✅',
                'title'   => 'Job done!',
                'message' => 'Booking marked as complete. Great work!',
                'booking' => $booking,
            ]);
        } else {
            self::render_page('error', [
                'title'   => 'Unknown action',
                'message' => 'Something went wrong with this link. Please contact support.',
            ]);
        }

        exit;
    }

    private static function apply_status(object $booking, string $status): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'rfb_bookings',
            ['driver_status' => $status, 'updated_at' => current_time('mysql')],
            ['id' => (int) $booking->id],
            ['%s', '%s'], ['%d']
        );
    }

    private static function render_page(string $type, array $data): void {
        $is_error = $type === 'error';
        $color    = $is_error ? '#dc2626' : '#0f766e';
        $bg       = $is_error ? '#fef2f2' : '#f0fdf9';
        $emoji    = $data['emoji'] ?? ($is_error ? '⚠️' : '✅');
        $title    = esc_html($data['title'] ?? '');
        $message  = esc_html($data['message'] ?? '');
        $booking_num = isset($data['booking']) ? esc_html((string) $data['booking']->booking_number) : '';

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>{$title}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: {$bg}; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
         display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 24px; }
  .card { background: #fff; border-radius: 16px; padding: 40px 32px; max-width: 400px; width: 100%;
          text-align: center; box-shadow: 0 8px 32px rgba(0,0,0,.1); }
  .emoji { font-size: 56px; margin-bottom: 16px; }
  h1 { font-size: 22px; color: {$color}; margin-bottom: 12px; font-weight: 700; }
  p { font-size: 15px; color: #64748b; line-height: 1.6; }
  .ref { display: inline-block; margin-top: 20px; font-size: 13px; font-weight: 700;
         letter-spacing: .06em; color: {$color}; background: {$bg}; padding: 6px 14px;
         border-radius: 20px; }
</style>
</head>
<body>
  <div class="card">
    <div class="emoji">{$emoji}</div>
    <h1>{$title}</h1>
    <p>{$message}</p>
    {$booking_num ? "<div class=\"ref\">#{$booking_num}</div>" : ''}
  </div>
</body>
</html>
HTML;
    }
}
```

---

## 7. Reminder Scheduler (WP-Cron)

```php
// includes/Notifications/ReminderScheduler.php

namespace RideFleetBooking\Notifications;

use RideFleetBooking\Booking\BookingRepository;

final class ReminderScheduler {

    public static function register(): void {
        // Custom interval: every 15 minutes
        add_filter('cron_schedules', [self::class, 'add_interval']);
        add_action('rfb_process_reminders', [self::class, 'process']);

        if (!wp_next_scheduled('rfb_process_reminders')) {
            wp_schedule_event(time(), 'rfb_every_15min', 'rfb_process_reminders');
        }
    }

    public static function add_interval(array $schedules): array {
        $schedules['rfb_every_15min'] = [
            'interval' => 900,
            'display'  => 'Every 15 minutes',
        ];
        return $schedules;
    }

    /**
     * Main cron handler — runs every 15 minutes.
     * Checks two windows and sends reminders if not yet sent.
     */
    public static function process(): void {
        global $wpdb;
        $now = current_time('mysql');

        // ── 24-hour reminder ──────────────────────────────────────────────────
        // Window: pickup_at is between now+23h and now+25h (2-hour window to
        // guarantee the cron catches it even if it fires slightly late)
        $candidates_24h = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rfb_bookings
             WHERE status NOT IN ('cancelled','completed','refunded','failed')
               AND reminder_24h_sent_at IS NULL
               AND pickup_at BETWEEN %s AND %s",
            date('Y-m-d H:i:s', strtotime($now) + 23 * 3600),
            date('Y-m-d H:i:s', strtotime($now) + 25 * 3600)
        ));

        foreach ($candidates_24h as $row) {
            self::send_reminder((int) $row->id, DriverNotification::TYPE_REMINDER_24H);
            $wpdb->update(
                $wpdb->prefix . 'rfb_bookings',
                ['reminder_24h_sent_at' => $now],
                ['id' => (int) $row->id],
                ['%s'], ['%d']
            );
        }

        // ── 2-hour reminder ───────────────────────────────────────────────────
        // Window: pickup_at is between now+1h45m and now+2h15m
        $candidates_2h = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rfb_bookings
             WHERE status NOT IN ('cancelled','completed','refunded','failed')
               AND reminder_2h_sent_at IS NULL
               AND pickup_at BETWEEN %s AND %s",
            date('Y-m-d H:i:s', strtotime($now) + 105 * 60),  // 1h45m
            date('Y-m-d H:i:s', strtotime($now) + 135 * 60)   // 2h15m
        ));

        foreach ($candidates_2h as $row) {
            self::send_reminder((int) $row->id, DriverNotification::TYPE_REMINDER_2H);
            $wpdb->update(
                $wpdb->prefix . 'rfb_bookings',
                ['reminder_2h_sent_at' => $now],
                ['id' => (int) $row->id],
                ['%s'], ['%d']
            );
        }
    }

    private static function send_reminder(int $booking_id, string $type): void {
        $booking  = BookingRepository::find($booking_id);
        $customer = $booking ? BookingRepository::customer((int) $booking->customer_id) : null;
        if (!$booking) {
            return;
        }

        $tokens   = BookingTokenService::generate_pair($booking_id, (string) $booking->pickup_at);
        $base_url = rest_url('rfb/v1/driver-action');

        $notification                = new DriverNotification();
        $notification->type          = $type;
        $notification->booking       = $booking;
        $notification->customer      = $customer;
        $notification->token_enroute  = $tokens['enroute'];
        $notification->token_complete = $tokens['complete'];
        $notification->url_enroute   = add_query_arg('token', $tokens['enroute'],  $base_url);
        $notification->url_complete  = add_query_arg('token', $tokens['complete'], $base_url);
        $notification->url_maps      = 'https://www.google.com/maps/dir/' .
                                       urlencode((string) $booking->pickup_address) . '/' .
                                       urlencode((string) $booking->dropoff_address);
        $notification->sms_body      = self::build_sms_body($notification);

        $driver_email = self::driver_email();
        $driver_phone = self::driver_phone();

        // Email is always attempted; phone only if set (future SMS channel)
        DriverNotificationDispatcher::dispatch($notification, $driver_email);
        if ($driver_phone) {
            DriverNotificationDispatcher::dispatch($notification, $driver_phone);
        }
    }

    private static function build_sms_body(DriverNotification $n): string {
        $b        = $n->booking;
        $ts       = $b->pickup_at ? strtotime((string) $b->pickup_at) : 0;
        $time_str = $ts ? date('D d/m H:i', $ts) : '?';
        $prefix   = $n->type === DriverNotification::TYPE_REMINDER_2H ? '⏰ In 2h' : '📅 Tomorrow';
        return sprintf(
            '%s — %s | %s → %s | %s',
            $prefix,
            $time_str,
            (string) $b->pickup_address,
            (string) $b->dropoff_address,
            $n->url_enroute
        );
    }

    public static function driver_email(): string {
        $email = trim((string) \RideFleetBooking\Support\Options::get('driver_notification_email', ''));
        return $email ?: (string) get_option('admin_email', '');
    }

    public static function driver_phone(): string {
        return trim((string) \RideFleetBooking\Support\Options::get('driver_notification_phone', ''));
    }
}
```

---

## 8. Email Channel Implementation

```php
// includes/Notifications/Channels/EmailDriverChannel.php

namespace RideFleetBooking\Notifications\Channels;

use RideFleetBooking\Notifications\Contracts\DriverNotificationChannel;
use RideFleetBooking\Notifications\DriverNotification;

final class EmailDriverChannel implements DriverNotificationChannel {

    public function is_ready(): bool {
        return true; // wp_mail() is always available
    }

    public function send(string $recipient, DriverNotification $n): bool {
        if (!is_email($recipient)) {
            return false;
        }

        $subject = $this->subject($n);
        $body    = $this->html_body($n);
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Attach ICS calendar file so trip lands in driver's phone calendar
        $attachments = [];
        $ics_path    = $this->write_ics_tmp($n);
        if ($ics_path) {
            $attachments[] = $ics_path;
        }

        $result = wp_mail($recipient, $subject, $body, $headers, $attachments);

        // Clean up temp ICS file
        if ($ics_path && file_exists($ics_path)) {
            @unlink($ics_path);
        }

        return $result;
    }

    private function subject(DriverNotification $n): string {
        $b   = $n->booking;
        $ts  = $b->pickup_at ? strtotime((string) $b->pickup_at) : 0;
        $day = $ts ? date('D d/m \a\t H:i', $ts) : '?';

        switch ($n->type) {
            case DriverNotification::TYPE_BOOKING_NEW:
                return '🚕 New booking — ' . (string) $b->booking_number . ' · ' . $day;
            case DriverNotification::TYPE_REMINDER_24H:
                return '📅 Tomorrow: ' . (string) $b->booking_number . ' · ' . $day;
            case DriverNotification::TYPE_REMINDER_2H:
                return '⏰ In 2 hours: ' . (string) $b->booking_number . ' · ' . $day;
            case DriverNotification::TYPE_BOOKING_CANCEL:
                return '❌ Cancelled: ' . (string) $b->booking_number;
            default:
                return 'RideFleet — Booking ' . (string) $b->booking_number;
        }
    }

    private function html_body(DriverNotification $n): string {
        $b            = $n->booking;
        $c            = $n->customer;
        $ts           = $b->pickup_at ? strtotime((string) $b->pickup_at) : 0;
        $pickup_date  = $ts ? date('l, F j Y', $ts)  : '—';
        $pickup_time  = $ts ? date('g:i A', $ts)      : '—';
        $booking_num  = esc_html((string) $b->booking_number);
        $pickup       = esc_html((string) $b->pickup_address);
        $dropoff      = esc_html((string) $b->dropoff_address);
        $customer_name = $c ? esc_html(trim($c->first_name . ' ' . $c->last_name)) : 'Customer';
        $customer_phone = $c ? esc_html((string) ($c->phone ?? '')) : '';
        $maps_url     = esc_url($n->url_maps);
        $url_enroute  = esc_url($n->url_enroute);
        $url_complete = esc_url($n->url_complete);

        $urgency_bar = '';
        if ($n->type === DriverNotification::TYPE_REMINDER_2H) {
            $urgency_bar = '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:10px 16px;font-size:13px;color:#92400e;margin-bottom:20px;">
                ⏰ <strong>Pickup in approximately 2 hours</strong> — tap the buttons below when you\'re ready.
            </div>';
        }

        $cancel_note = '';
        if ($n->type === DriverNotification::TYPE_BOOKING_CANCEL) {
            return $this->cancel_html($n);
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Job #{$booking_num}</title>
<style>
  body { margin:0; padding:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
  .wrap { max-width:580px; margin:24px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 2px 16px rgba(0,0,0,.08); }
  .header { background:linear-gradient(135deg,#0f766e,#115e59); padding:24px 28px; }
  .header h1 { margin:0; color:#fff; font-size:18px; font-weight:700; }
  .header p { margin:4px 0 0; color:rgba(255,255,255,.75); font-size:13px; }
  .body { padding:24px 28px; }
  .row { display:flex; gap:12px; padding:11px 0; border-bottom:1px solid #f1f5f9; align-items:flex-start; }
  .row:last-child { border-bottom:none; }
  .row-icon { font-size:16px; flex-shrink:0; margin-top:2px; }
  .row-label { font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; font-weight:600; margin-bottom:2px; }
  .row-value { font-size:14px; color:#1e293b; font-weight:500; line-height:1.4; }
  .actions { padding:20px 28px; background:#f8fafc; border-top:1px solid #e2e8f0; }
  .actions p { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin:0 0 12px; }
  .btn { display:block; text-align:center; padding:14px; border-radius:10px; font-size:16px; font-weight:700; text-decoration:none; margin-bottom:10px; }
  .btn-primary { background:#0f766e; color:#fff; }
  .btn-complete { background:#1e293b; color:#fff; }
  .btn-maps { background:#e0f2fe; color:#0369a1; font-size:14px; font-weight:600; }
  .footer { padding:14px 28px; background:#f8fafc; border-top:1px solid #e2e8f0; font-size:12px; color:#94a3b8; text-align:center; }
</style>
</head>
<body>
<div class="wrap">
  <div class="header">
    <h1>🚖 Job #{$booking_num}</h1>
    <p>{$pickup_date} at <strong style="color:#fff">{$pickup_time}</strong></p>
  </div>
  <div class="body">
    {$urgency_bar}
    <div class="row">
      <div class="row-icon">👤</div>
      <div>
        <div class="row-label">Passenger</div>
        <div class="row-value">{$customer_name}
          {$customer_phone ? "<br><a href='tel:{$customer_phone}' style='color:#0f766e;text-decoration:none;font-size:13px;'>{$customer_phone}</a>" : ''}
        </div>
      </div>
    </div>
    <div class="row">
      <div class="row-icon">📍</div>
      <div>
        <div class="row-label">Pickup</div>
        <div class="row-value">{$pickup}</div>
      </div>
    </div>
    <div class="row">
      <div class="row-icon">🏁</div>
      <div>
        <div class="row-label">Drop-off</div>
        <div class="row-value">{$dropoff}</div>
      </div>
    </div>
    <div class="row">
      <div class="row-icon">👥</div>
      <div>
        <div class="row-label">Passengers / Luggage</div>
        <div class="row-value">{$b->passengers} pax · {$b->luggage} bags</div>
      </div>
    </div>
  </div>

  <div class="actions">
    <p>Tap when ready</p>
    <a href="{$url_enroute}" class="btn btn-primary">🚗 I'm on my way</a>
    <a href="{$url_complete}" class="btn btn-complete">✅ Job done</a>
    <a href="{$maps_url}" class="btn btn-maps" target="_blank" rel="noopener">🗺️ Open in Google Maps</a>
  </div>

  <div class="footer">
    Buttons work once · No login required · Link expires 48h after pickup
  </div>
</div>
</body>
</html>
HTML;
    }

    private function cancel_html(DriverNotification $n): string {
        $b   = $n->booking;
        $ts  = $b->pickup_at ? strtotime((string) $b->pickup_at) : 0;
        $day = $ts ? date('D d/m \a\t H:i', $ts) : '?';
        $num = esc_html((string) $b->booking_number);
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;padding:24px;background:#fef2f2;font-family:sans-serif;display:flex;justify-content:center;}
.card{background:#fff;border-radius:12px;padding:28px;max-width:500px;width:100%;box-shadow:0 2px 12px rgba(0,0,0,.08);text-align:center;}
h1{color:#dc2626;font-size:20px;margin-bottom:12px;}p{color:#64748b;font-size:14px;line-height:1.6;}</style>
</head><body><div class="card">
<div style="font-size:44px;margin-bottom:12px">❌</div>
<h1>Booking Cancelled — #{$num}</h1>
<p>The booking originally scheduled for <strong>{$day}</strong> has been cancelled by the customer.</p>
<p style="margin-top:16px;font-size:12px;color:#94a3b8;">No action required.</p>
</div></body></html>
HTML;
    }

    /**
     * Write a .ics file to sys_temp_dir so wp_mail() can attach it.
     * Returns the file path or null on failure.
     */
    private function write_ics_tmp(DriverNotification $n): ?string {
        $b       = $n->booking;
        $ts      = $b->pickup_at ? strtotime((string) $b->pickup_at) : 0;
        if (!$ts) { return null; }

        $dtstart  = gmdate('Ymd\THis\Z', $ts);
        $dtend    = gmdate('Ymd\THis\Z', $ts + 3600);
        $dtstamp  = gmdate('Ymd\THis\Z');
        $uid      = sanitize_key((string) $b->booking_number) . '-' . $b->id . '@ridefleet';
        $summary  = 'Transfer: ' . str_replace(',', '\\,', (string) $b->pickup_address) . ' → ' . str_replace(',', '\\,', (string) $b->dropoff_address);
        $location = str_replace(',', '\\,', (string) $b->pickup_address);

        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RideFleet//Driver//EN',
            'BEGIN:VEVENT',
            'UID:'       . $uid,
            'DTSTAMP:'   . $dtstamp,
            'DTSTART:'   . $dtstart,
            'DTEND:'     . $dtend,
            'SUMMARY:'   . $summary,
            'LOCATION:'  . $location,
            'DESCRIPTION:Booking #' . (string) $b->booking_number . '. Tap link in email to update status.',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $path = sys_get_temp_dir() . '/rfb-' . sanitize_key((string) $b->booking_number) . '.ics';
        return file_put_contents($path, $ics) !== false ? $path : null;
    }
}
```

---

## 9. Customer Notifier (status updates)

When the driver taps "I'm on my way", the customer gets a short email. This is separate from `NotificationService` because it's triggered by a driver action, not a booking lifecycle event.

```php
// includes/Notifications/CustomerNotifier.php

namespace RideFleetBooking\Notifications;

use RideFleetBooking\Booking\BookingRepository;

final class CustomerNotifier {

    /**
     * Fired when driver taps "I'm on my way".
     */
    public static function driver_on_the_way(int $booking_id): void {
        $booking  = BookingRepository::find($booking_id);
        $customer = $booking ? BookingRepository::customer((int) $booking->customer_id) : null;
        if (!$booking || !$customer || !$customer->email) {
            return;
        }

        $ts   = $booking->pickup_at ? strtotime((string) $booking->pickup_at) : 0;
        $time = $ts ? date('H:i', $ts) : '';
        $name = trim((string) ($customer->first_name ?? '')) ?: 'there';

        $subject = '🚗 Your driver is on the way — ' . (string) $booking->booking_number;
        $body    = self::on_the_way_html(esc_html($name), esc_html((string) $booking->pickup_address), $time, esc_html((string) $booking->booking_number));

        wp_mail($customer->email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    private static function on_the_way_html(string $name, string $pickup, string $time, string $ref): string {
        return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
.wrap{max-width:520px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 16px rgba(0,0,0,.08);}
.header{background:linear-gradient(135deg,#0f766e,#115e59);padding:28px;text-align:center;}
.header-icon{font-size:48px;margin-bottom:8px;}
.header h1{margin:0;color:#fff;font-size:22px;font-weight:700;}
.body{padding:28px;text-align:center;}
.greeting{font-size:16px;color:#1e293b;margin-bottom:16px;line-height:1.6;}
.detail{display:inline-block;background:#f0fdf9;border:1px solid #99f6e4;color:#0f766e;border-radius:8px;padding:10px 20px;font-size:14px;font-weight:600;margin-bottom:20px;}
.note{font-size:13px;color:#64748b;line-height:1.6;}
.footer{padding:14px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#94a3b8;}
</style></head><body>
<div class="wrap">
  <div class="header">
    <div class="header-icon">🚗</div>
    <h1>Your driver is on the way!</h1>
  </div>
  <div class="body">
    <p class="greeting">Hi <strong>{$name}</strong>,<br>Good news — your driver is heading to your pickup location now.</p>
    <div class="detail">📍 {$pickup}</div>
    <p class="note">Scheduled pickup: <strong>{$time}</strong><br>Booking reference: <strong>#{$ref}</strong></p>
  </div>
  <div class="footer">Please be ready at your pickup location · Ref #{$ref}</div>
</div>
</body></html>
HTML;
    }
}
```

---

## 10. SMS Channel Stub (ready for future wiring)

```php
// includes/Notifications/Channels/SmsDriverChannel.php

namespace RideFleetBooking\Notifications\Channels;

use RideFleetBooking\Notifications\Contracts\DriverNotificationChannel;
use RideFleetBooking\Notifications\DriverNotification;

/**
 * SMS notification channel — stub ready for Twilio / MessageBird / any HTTP SMS gateway.
 *
 * To activate:
 *  1. Fill in send_via_gateway() with your provider's API call.
 *  2. Add 'driver_notification_phone' in plugin settings.
 *  3. Register this channel in plugin bootstrap:
 *     DriverNotificationDispatcher::register(new SmsDriverChannel());
 */
final class SmsDriverChannel implements DriverNotificationChannel {

    public function is_ready(): bool {
        $phone = trim((string) \RideFleetBooking\Support\Options::get('driver_notification_phone', ''));
        // Also check that API credentials are configured
        $api_key = trim((string) \RideFleetBooking\Support\Options::get('sms_api_key', ''));
        return '' !== $phone && '' !== $api_key;
    }

    public function send(string $recipient, DriverNotification $notification): bool {
        // Only send SMS for time-critical notifications
        $sms_types = [DriverNotification::TYPE_REMINDER_2H, DriverNotification::TYPE_BOOKING_CANCEL];
        if (!in_array($notification->type, $sms_types, true)) {
            return true; // silently skip non-urgent types
        }

        return $this->send_via_gateway($recipient, $notification->sms_body);
    }

    private function send_via_gateway(string $to, string $body): bool {
        // TODO: implement with your SMS provider
        // Example (Twilio):
        //
        // $account_sid = Options::get('twilio_account_sid');
        // $auth_token  = Options::get('twilio_auth_token');
        // $from        = Options::get('twilio_from_number');
        //
        // $response = wp_remote_post("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json", [
        //     'headers' => ['Authorization' => 'Basic ' . base64_encode("{$account_sid}:{$auth_token}")],
        //     'body'    => ['To' => $to, 'From' => $from, 'Body' => $body],
        // ]);
        //
        // return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 201;

        return false; // stub
    }
}
```

---

## 11. Integration: Hook into Existing `NotificationService`

The existing `NotificationService::booking_created()` already sends the operator email. We need to augment it to also include action tokens — OR we call the new system alongside it. The cleanest approach: add a hook in `BookingRepository::create()` that fires after the existing notification.

```php
// In BookingRepository::create(), after NotificationService::booking_created():

NotificationService::booking_created($booking_id);

// NEW: fire driver notification with action tokens
do_action('rfb_send_driver_notification', $booking_id, DriverNotification::TYPE_BOOKING_NEW);
```

Then hook this in the plugin bootstrap or a service provider:

```php
add_action('rfb_send_driver_notification', function(int $booking_id, string $type) {
    $scheduler = new \RideFleetBooking\Notifications\ReminderScheduler();
    // Reuse the send_reminder logic for initial notification too
    \RideFleetBooking\Notifications\ReminderScheduler::send_notification($booking_id, $type);
}, 10, 2);
```

This keeps `BookingRepository` clean and makes the driver notification system entirely opt-out via hook removal.

---

## 12. Settings Page Additions

New fields in the **Settings → Notifications** tab:

| Field | Key | Default | Notes |
|---|---|---|---|
| Driver notification email | `driver_notification_email` | admin_email | Who gets new booking + reminder emails |
| Driver phone (SMS) | `driver_notification_phone` | *(empty)* | Leave blank = SMS disabled |
| 24h reminder | `reminder_24h_enabled` | `true` | Toggle |
| 2h reminder | `reminder_2h_enabled` | `true` | Toggle |
| SMS API key | `sms_api_key` | *(empty)* | Only shown when phone is set |
| SMS sender name/number | `sms_from` | *(empty)* | |

---

## 13. File Structure

```
ridefleet-booking/
└── includes/
    ├── Notifications/
    │   ├── Contracts/
    │   │   └── DriverNotificationChannel.php   ← interface
    │   ├── Channels/
    │   │   ├── EmailDriverChannel.php           ← built now
    │   │   └── SmsDriverChannel.php             ← stub, wire later
    │   ├── DriverNotification.php               ← value object
    │   ├── DriverNotificationDispatcher.php     ← fan-out
    │   ├── BookingTokenService.php              ← token CRUD
    │   ├── ReminderScheduler.php                ← WP-Cron handler
    │   └── CustomerNotifier.php                 ← "on the way" customer email
    └── Api/
        └── DriverActionEndpoint.php             ← public REST endpoint
```

---

## 14. Implementation Order

Build in this sequence — each step is independently testable:

1. **DB migration** — add columns to `rfb_bookings`, create `rfb_booking_tokens` table
2. **`BookingTokenService`** — generate + consume, test with a unit test
3. **`DriverActionEndpoint`** — register REST route, test by visiting `?token=invalid` manually
4. **`EmailDriverChannel`** + **`DriverNotificationDispatcher`** — wire up, trigger manually from a test booking
5. **Hook into `BookingRepository`** — new bookings now get driver emails with action links
6. **`CustomerNotifier`** — wire up to `DriverActionEndpoint` handle()
7. **`ReminderScheduler`** — register cron, test by temporarily lowering the window to 5 minutes
8. **Settings page fields** — expose driver email/phone in admin UI
9. **`SmsDriverChannel` stub** — already written, just register it when credentials are added

---

## 15. Key Design Decisions

**Why tokenized URLs instead of a login-protected page?**  
A solo operator on the road doesn't want to remember a password or deal with a WordPress session cookie on their phone. A tappable link that just works is zero friction. Security is provided by the 64-character random token — brute-forcing it is computationally infeasible.

**Why separate tokens per action and per reminder?**  
Single-use + expires at pickup+48h. If the driver accidentally taps "Job done" before the trip, the token is spent and can't be replayed. Each reminder email carries fresh tokens so the operator always has working links.

**Why email-only now with the SMS abstraction already in place?**  
SMS requires a paid gateway, credentials, number registration, and regional compliance. Email works today with zero dependencies. The channel interface means adding SMS is registering one class — nothing in the business logic changes. The stub is already written.

**Why WP-Cron every 15 minutes instead of a real cron job?**  
WP-Cron fires on page load, which means it's reliable enough for a small operation where the admin visits the site daily. If the site ever gets dedicated hosting, a real system cron (`* * * * * wp cron event run --due-now`) can replace it with no code changes — same hooks, same handlers.

**Why not assign a driver user role?**  
For a one-person operation this adds complexity with zero benefit. The operator IS the driver. If the business grows to multiple drivers, a `rfb_drivers` table can be added and the `DriverNotificationDispatcher` can fan out to each assigned driver — the interface already supports this.
