<?php
/**
 * Admin email notifications for new change requests and fare negotiations.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

use RideFleetAIChatbot\Support\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class Notifier {
    public static function on_new_change_request(int $request_id, array $session, string $request_text): void {
        if (!self::enabled()) {
            return;
        }

        $data       = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
        $name       = (string) ($data['customer_name'] ?? __('unknown', 'ridefleet-ai-chatbot'));
        $phone      = (string) ($data['customer_phone'] ?? '');
        $booking_id = (string) ($data['last_booking_id'] ?? '');

        $approve_url = self::action_url($request_id, 'approve');
        $reject_url  = self::action_url($request_id, 'reject');
        $review_url  = admin_url('admin.php?page=ridefleet-ai-chatbot-changes');

        $subject = sprintf(
            __('[%1$s] New booking change request #%2$d', 'ridefleet-ai-chatbot'),
            (string) get_bloginfo('name'),
            $request_id
        );

        $rows = [
            __('Customer',    'ridefleet-ai-chatbot') => esc_html($name),
            __('Phone',       'ridefleet-ai-chatbot') => esc_html($phone ?: '—'),
            __('Booking ref', 'ridefleet-ai-chatbot') => esc_html($booking_id ?: '—'),
            __('Request',     'ridefleet-ai-chatbot') => nl2br(esc_html($request_text)),
        ];

        $body = self::html_email(
            __('New Booking Change Request', 'ridefleet-ai-chatbot'),
            __('A customer has submitted a booking change request through the chatbot.', 'ridefleet-ai-chatbot'),
            $rows,
            [
                ['url' => $approve_url, 'label' => '✅ ' . __('Approve', 'ridefleet-ai-chatbot'), 'primary' => true],
                ['url' => $reject_url,  'label' => '❌ ' . __('Reject',  'ridefleet-ai-chatbot'), 'primary' => false],
                ['url' => $review_url,  'label' => __('Review in Admin', 'ridefleet-ai-chatbot'), 'primary' => false],
            ]
        );

        self::send_html($body, $subject);
    }

    public static function on_new_price_negotiation(int $request_id, array $session): void {
        if (!self::enabled()) {
            return;
        }

        $data     = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
        $quote    = is_array($session['last_quote'] ?? null) ? $session['last_quote'] : [];
        $name     = (string) ($data['customer_name'] ?? __('unknown', 'ridefleet-ai-chatbot'));
        $phone    = (string) ($data['customer_phone'] ?? '');
        $pickup   = (string) ($data['pickup_address'] ?? '—');
        $dropoff  = (string) ($data['dropoff_address'] ?? '—');
        $original = (float)  ($quote['final_price'] ?? 0);
        $proposed = (float)  ($data['proposed_price'] ?? 0);
        $currency = (string) ($quote['currency'] ?? 'USD');
        $discount = $original > 0 ? round((($original - $proposed) / $original) * 100, 1) : 0;

        $approve_url = self::action_url($request_id, 'approve');
        $reject_url  = self::action_url($request_id, 'reject');
        $review_url  = admin_url('admin.php?page=ridefleet-ai-chatbot-changes');

        $subject = sprintf(
            __('[%1$s] New fare negotiation request #%2$d', 'ridefleet-ai-chatbot'),
            (string) get_bloginfo('name'),
            $request_id
        );

        $rows = [
            __('Customer',      'ridefleet-ai-chatbot') => esc_html($name),
            __('Phone',         'ridefleet-ai-chatbot') => esc_html($phone ?: '—'),
            __('Route',         'ridefleet-ai-chatbot') => esc_html($pickup . ' → ' . $dropoff),
            __('Original fare', 'ridefleet-ai-chatbot') => '<strong>' . esc_html($currency . ' ' . number_format($original, 2)) . '</strong>',
            __('Proposed fare', 'ridefleet-ai-chatbot') => '<strong style="color:#dc2626;">' . esc_html($currency . ' ' . number_format($proposed, 2)) . '</strong> <span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:8px;font-size:12px;">−' . esc_html(number_format($discount, 1)) . '%</span>',
        ];

        $body = self::html_email(
            __('Fare Negotiation Request', 'ridefleet-ai-chatbot'),
            __('A customer has proposed a lower fare through the chatbot.', 'ridefleet-ai-chatbot'),
            $rows,
            [
                ['url' => $approve_url, 'label' => '✅ ' . __('Accept Proposed Fare', 'ridefleet-ai-chatbot'), 'primary' => true],
                ['url' => $reject_url,  'label' => '❌ ' . __('Reject',  'ridefleet-ai-chatbot'), 'primary' => false],
                ['url' => $review_url,  'label' => __('Counteroffer in Admin', 'ridefleet-ai-chatbot'), 'primary' => false],
            ]
        );

        self::send_html($body, $subject);
    }

    private static function action_url(int $request_id, string $action): string {
        return add_query_arg([
            'page'              => 'ridefleet-ai-chatbot-changes',
            'rfac_quick_action' => $action,
            'id'                => $request_id,
            '_wpnonce'          => wp_create_nonce('rfac_quick_action_' . $request_id),
        ], admin_url('admin.php'));
    }

    private static function html_email(string $title, string $intro, array $rows, array $buttons): string {
        $site  = esc_html((string) get_bloginfo('name'));
        $color = '#0f766e';

        $rows_html = '';
        foreach ($rows as $label => $value) {
            $rows_html .= '<tr><td style="padding:8px 12px;font-weight:600;color:#475569;white-space:nowrap;vertical-align:top;font-size:13px;">' . esc_html($label) . '</td><td style="padding:8px 12px;color:#17202a;font-size:13px;">' . $value . '</td></tr>';
        }

        $btns_html = '';
        foreach ($buttons as $btn) {
            $bg  = $btn['primary'] ? $color : '#f1f5f9';
            $clr = $btn['primary'] ? '#ffffff' : '#374151';
            $btns_html .= '<a href="' . esc_url($btn['url']) . '" style="display:inline-block;padding:10px 20px;background:' . $bg . ';color:' . $clr . ';border-radius:8px;text-decoration:none;font-weight:700;font-size:13px;margin:0 6px 6px 0;">' . $btn['label'] . '</a>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:32px 16px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:14px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);">
<tr><td style="background:linear-gradient(135deg,rgba(15,118,110,.38),transparent 44%),#17202a;padding:28px 32px;">
    <p style="margin:0 0 4px;color:#7dd3c7;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">' . esc_html($site) . '</p>
    <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:800;">' . esc_html($title) . '</h1>
</td></tr>
<tr><td style="padding:28px 32px;">
    <p style="margin:0 0 20px;color:#475569;font-size:14px;">' . esc_html($intro) . '</p>
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:24px;">' . $rows_html . '</table>
    <div>' . $btns_html . '</div>
    <p style="margin:24px 0 0;font-size:12px;color:#94a3b8;">Buttons above are one-click admin actions. They require you to be logged into WordPress.</p>
</td></tr>
</table>
</td></tr>
</table>
</body></html>';
    }

    private static function enabled(): bool {
        return !empty(Options::get('notifications_enabled', 1));
    }

    private static function send_html(string $html_body, string $subject): void {
        $to = sanitize_email((string) Options::get('notification_email', ''));
        if ('' === $to || !is_email($to)) {
            $to = (string) get_option('admin_email');
        }
        if ('' === $to) {
            return;
        }
        add_filter('wp_mail_content_type', static fn() => 'text/html');
        wp_mail($to, $subject, $html_body);
        remove_filter('wp_mail_content_type', static fn() => 'text/html');
    }
}
