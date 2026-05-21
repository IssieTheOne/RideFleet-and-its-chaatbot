<?php
/**
 * Thin Stripe REST API wrapper — creates one-time payment links for bookings.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

use RideFleetAIChatbot\Support\Options;

if (!defined('ABSPATH')) {
    exit;
}

final class StripeClient {
    private const API_BASE = 'https://api.stripe.com/v1';

    /**
     * Create a Stripe Checkout Session (hosted payment page) for a booking.
     *
     * @param float  $amount_cents  Amount in the smallest currency unit (cents).
     * @param string $currency      ISO 4217 currency code, lowercase (e.g. 'usd').
     * @param string $booking_id    Booking reference for metadata.
     * @param string $description   Human-readable line item description.
     * @return string|null          The Checkout session URL, or null on failure.
     */
    public static function create_payment_url(float $amount_cents, string $currency, string $booking_id, string $description): ?string {
        $secret_key = trim((string) Options::get('stripe_secret_key', ''));
        if ('' === $secret_key) {
            return null;
        }

        $home = home_url('/');

        $body = [
            'mode'                                         => 'payment',
            'line_items[0][price_data][currency]'          => strtolower($currency),
            'line_items[0][price_data][unit_amount]'       => (int) round($amount_cents),
            'line_items[0][price_data][product_data][name]'=> $description,
            'line_items[0][quantity]'                      => '1',
            'metadata[booking_id]'                         => $booking_id,
            'success_url'                                  => $home . '?rfac_payment=success&booking=' . rawurlencode($booking_id),
            'cancel_url'                                   => $home . '?rfac_payment=cancelled&booking=' . rawurlencode($booking_id),
        ];

        $resp = wp_remote_post(
            self::API_BASE . '/checkout/sessions',
            [
                'timeout'  => 10,
                'headers'  => [
                    'Authorization' => 'Bearer ' . $secret_key,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ],
                'body'     => $body,
            ]
        );

        if (is_wp_error($resp)) {
            return null;
        }

        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        $url  = (string) ($data['url'] ?? '');
        return '' !== $url ? $url : null;
    }
}
