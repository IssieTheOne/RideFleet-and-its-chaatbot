<?php
/**
 * Deterministic booking funnel with AI-assisted wording.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

use RideFleetAIChatbot\Support\DiagnosticLogger;

if (!defined('ABSPATH')) {
	exit;
}

final class ConversationEngine {
	private SessionRepository $sessions;

	private CoreApiClient $core;

	private OpenRouterClient $ai;

	private array $current_turn = [];

	public function __construct(?SessionRepository $sessions = null, ?CoreApiClient $core = null, ?OpenRouterClient $ai = null) {
		$this->sessions = $sessions ?: new SessionRepository();
		$this->core = $core ?: new CoreApiClient();
		$this->ai = $ai ?: new OpenRouterClient();
	}

	public function handle(string $session_key, string $message, string $client_locale = '', array $prefill_contact = [], array $metadata = []): array {
		$session = $this->sessions->get_or_create($session_key);
		$message = trim(wp_strip_all_tags($message));
		$state_before_turn = (string) ($session['state'] ?? 'greeting');

		DiagnosticLogger::log((int) $session['id'], (string) $session['session_key'], 'turn_start', 'conversation', 'User turn started.', [
			'state_before' => $state_before_turn,
			'client_locale' => $client_locale,
			'message' => $message,
			'prefill_contact_present' => !empty($prefill_contact['name']) || !empty($prefill_contact['phone']),
			'metadata' => $metadata,
		]);

		if ('' === (string) ($session['collected_data']['language'] ?? '')) {
			// 1. Admin language setting takes highest priority (when not "auto").
			$admin_lang = sanitize_key((string) \RideFleetAIChatbot\Support\Options::get('chatbot_language', 'auto'));
			if ('auto' !== $admin_lang && in_array($admin_lang, ['en', 'fr', 'nl'], true)) {
				$session['collected_data']['language'] = $admin_lang;
			} elseif ('' !== $client_locale) {
				// 2. Fall back to browser locale when admin chose "auto".
				$seed = strtolower(substr($client_locale, 0, 2));
				if (in_array($seed, ['en', 'fr', 'nl'], true)) {
					$session['collected_data']['language'] = $seed;
				}
			}
		}

		// Returning-customer prefill: merge saved contact without injecting it as fake chat text.
		if (!empty($prefill_contact['name']) && '' === trim((string) ($session['collected_data']['customer_name'] ?? ''))) {
			$session['collected_data']['customer_name'] = sanitize_text_field($prefill_contact['name']);
		}
		if (!empty($prefill_contact['phone']) && '' === trim((string) ($session['collected_data']['customer_phone'] ?? ''))) {
			$session['collected_data']['customer_phone'] = sanitize_text_field($prefill_contact['phone']);
		}

		$turn = $this->classify_turn($message, $session);
		$turn = $this->state_scoped_turn($turn, (string) ($session['state'] ?? 'greeting'));
		$this->current_turn = $turn;
		DiagnosticLogger::log((int) $session['id'], (string) $session['session_key'], 'turn_classified', 'conversation', 'Turn classified and scoped to current state.', [
			'state' => (string) ($session['state'] ?? ''),
			'classification' => $turn,
		]);
		$language = $this->locked_language($message, $session, $turn);
		$session['collected_data']['language'] = $language;
		$session['collected_data']['last_intent'] = $turn['intent'];
		$session['collected_data']['last_extracted_fields'] = $turn['fields'];

		if ('' === $message) {
			$response = $this->reply($session, __('Tell me where you would like to be picked up.', 'ridefleet-ai-chatbot'));
			return $this->format_response($response);
		}

		$this->sessions->add_message((int) $session['id'], 'user', $message, $this->admin_summary($message, $language, 'customer', $turn), $this->message_meta($turn, $language));

		if ('cancel' === $turn['intent'] && $this->asks_to_close_chat($message)) {
			$session['ui_action'] = 'close_chat';
			$response = $this->reply($session, __('Of course. I will close the chat now. You can reopen it any time.', 'ridefleet-ai-chatbot'));
			return $this->commit_response($response);
		}

		$external_edit = $this->external_booking_edit_request($message);
		if ($external_edit) {
			$data = $session['collected_data'];
			$data['last_booking_id'] = $external_edit;
			$session['collected_data'] = $data;
			$session['state'] = 'capture_change_request';
			$response = $this->reply($session, sprintf(__('I found booking %s. Tell me what you want to change. I will send the request to dispatch for admin approval, and the final price may change.', 'ridefleet-ai-chatbot'), $external_edit));
			return $this->commit_response($response);
		}

		if ('complete' === $session['state'] && $this->asks_to_edit_booking($message)) {
			$session['state'] = 'capture_change_request';
			$response = $this->reply($session, __('Sure. Tell me what you want to change. I will send it to dispatch for admin approval, and the final price may change.', 'ridefleet-ai-chatbot'));
			return $this->commit_response($response);
		}

		// Cancellation detection — allowed from 'complete' or 'confirm_cancellation' states.
		if (in_array($session['state'], ['complete', 'confirm_cancellation', 'capture_pickup', 'capture_dropoff', 'capture_pickup_time', 'confirm_price', 'confirm_booking_details'], true) && $this->is_cancel_booking_request($message)) {
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			$booking_number = $this->extract_booking_number($message);
			// If no number in message, fall back to the booking number from this session.
			if (!$booking_number && !empty($session['collected_data']['last_booking_number'])) {
				$booking_number = (string) $session['collected_data']['last_booking_number'];
			}
			if (!$booking_number && !empty($session['collected_data']['last_booking_id'])) {
				// Try to resolve ID → number via booking plugin.
				if (class_exists('\\RideFleetBooking\\Booking\\BookingRepository')) {
					$booking_obj = \RideFleetBooking\Booking\BookingRepository::find((int) $session['collected_data']['last_booking_id']);
					if ($booking_obj) {
						$booking_number = (string) ($booking_obj->booking_number ?? '');
					}
				}
			}
			if (!$booking_number) {
				// We cannot identify the booking; ask the user for the reference.
				$response = $this->reply($session, 'nl' === $lang
					? __('Welk boekingsnummer wilt u annuleren? Vermeld uw boekingnummer (bijv. RFB-0042).', 'ridefleet-ai-chatbot')
					: ('fr' === $lang
						? __('Quel numéro de réservation souhaitez-vous annuler ? Indiquez votre numéro de réservation (ex. RFB-0042).', 'ridefleet-ai-chatbot')
						: __('Which booking would you like to cancel? Please provide your booking number (e.g. RFB-0042).', 'ridefleet-ai-chatbot')));
				return $this->commit_response($response);
			}
			$session['collected_data']['pending_cancel_number'] = strtoupper($booking_number);
			$session['state'] = 'confirm_cancellation';
			if ('nl' === $lang) {
				$confirm_msg = sprintf(__('Weet u zeker dat u boeking %s wilt annuleren? Antwoord ja om te bevestigen of nee om te behouden.', 'ridefleet-ai-chatbot'), $booking_number);
			} elseif ('fr' === $lang) {
				$confirm_msg = sprintf(__('Êtes-vous sûr de vouloir annuler la réservation %s ? Répondez oui pour confirmer ou non pour conserver.', 'ridefleet-ai-chatbot'), $booking_number);
			} else {
				$confirm_msg = sprintf(__('Are you sure you want to cancel booking %s? Reply yes to confirm the cancellation or no to keep it.', 'ridefleet-ai-chatbot'), $booking_number);
			}
			$response = $this->reply($session, $confirm_msg);
			return $this->commit_response($response);
		}

		if ('change_pending' === $session['state'] && $this->is_acknowledgement($message)) {
			$response = $this->reply($session, __('Yes. Your change request is pending admin approval, and the original booking stays active until dispatch confirms the change.', 'ridefleet-ai-chatbot'));
			return $this->commit_response($response);
		}

		if ($this->asks_request_status($message)) {
			$response = $this->reply($session, $this->request_status_message($session, $message));
			return $this->commit_response($response);
		}

		if ($this->asks_language_switch($message)) {
			$target = $this->requested_language($message);
			if ($target) {
				$session['collected_data']['language'] = $target;
				$response = $this->reply($session, $this->language_switched_message($target));
				return $this->commit_response($response);
			}
		}

		if ('complete' === $session['state'] && $this->asks_return_trip($message)) {
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			$orig_pickup = (string) ($session['collected_data']['pickup_address'] ?? '');
			$orig_dropoff = (string) ($session['collected_data']['dropoff_address'] ?? '');
			$session['state'] = 'confirm_price';
			$session['collected_data'] = [
				'language' => $lang,
				'pickup_address' => $orig_dropoff,
				'dropoff_address' => $orig_pickup,
			];
			$session['last_quote'] = [];
			$session['state'] = 'quote_requested';
			$return_note = 'fr' === $lang
				? __('Parfait. Je calcule le prix du trajet retour.', 'ridefleet-ai-chatbot')
				: ('nl' === $lang
					? __('Prima. Ik bereken de prijs voor de terugrit.', 'ridefleet-ai-chatbot')
					: __('Great. Let me get a quote for the return trip.', 'ridefleet-ai-chatbot'));
			$result = $this->quote_trip($session);
			$result['message'] = $return_note . ' ' . $result['message'];
			return $this->commit_response($result);
		}

		if ('complete' === $session['state'] && $this->asks_for_new_booking($message)) {
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			$contact = $this->preserved_contact_data($session['collected_data'] ?? []);
			$session['state'] = 'capture_pickup';
			$session['collected_data'] = array_merge(['language' => $lang], $contact);
			$session['last_quote'] = [];
			$response = $this->reply($session, $this->new_booking_prompt($session, !empty($contact)));
			return $this->commit_response($response);
		}

		if ('complete' === $session['state'] && !$this->is_reset($message)) {
			if ($this->is_gratitude_or_goodbye($message)) {
				$session['ui_action'] = 'offer_close';
				$response = $this->reply($session, $this->say($session, 'end_graceful'));
			} elseif ($this->is_acknowledgement($message)) {
				$session['ui_action'] = 'offer_close';
				$response = $this->reply($session, $this->say($session, 'done_confirmed'));
			} else {
				$session['ui_action'] = 'offer_close';
				$response = $this->reply($session, $this->say($session, 'done_options'));
			}
			return $this->commit_response($response);
		}

		if ('halted' === $session['state'] && !$this->is_reset($message)) {
			$response = $this->reply($session, __('This booking flow is closed. Say new booking if you want to start another taxi request.', 'ridefleet-ai-chatbot'));
			return $this->commit_response($response);
		}

		if ($this->is_reset($message)) {
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			$session['state'] = 'capture_pickup';
			$session['collected_data'] = ['language' => $lang];
			$session['last_quote'] = [];
			$response = $this->reply($session, $this->say($session, 'new_booking'));
			return $this->commit_response($response);
		}

		if ($this->asks_for_new_booking($message)) {
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			$contact = $this->preserved_contact_data($session['collected_data'] ?? []);
			$session['state'] = 'capture_pickup';
			$session['collected_data'] = array_merge(['language' => $lang], $contact);
			$session['last_quote'] = [];
			$response = $this->reply($session, $this->new_booking_prompt($session, !empty($contact)));
			return $this->commit_response($response);
		}

		if ('cancel' === $turn['intent'] && $this->is_cancel($message)) {
			$session['state'] = 'halted';
			$session['collected_data'] = [];
			$session['last_quote'] = [];
			$response = $this->reply($session, $this->say($session, 'cancelled'));
			return $this->commit_response($response);
		}

		$coupon = $this->detect_coupon_code($message);
		if ('' !== $coupon) {
			$data = $session['collected_data'];
			$data['coupon_code'] = $coupon;

			// Round-trip validation against the booking plugin so we can tell the
			// user "applied" or "rejected" immediately instead of at booking time.
			$subtotal = (float) ($session['last_quote']['final_price'] ?? 0);
			$validation = $this->core->validate_coupon($coupon, $subtotal);
			if (!empty($validation['valid'])) {
				$coupon_info = [
					'code' => $coupon,
					'status' => 'applied',
					'amount' => (float) ($validation['amount'] ?? 0),
				];
				$session['validation_error'] = sprintf(
					__('Coupon %1$s applied: −%2$s %3$.2f off the quote.', 'ridefleet-ai-chatbot'),
					$coupon,
					(string) ($session['last_quote']['currency'] ?? 'USD'),
					(float) ($validation['amount'] ?? 0)
				);
			} else {
				$coupon_info = [
					'code' => $coupon,
					'status' => 'rejected',
					'message' => (string) ($validation['message'] ?? ''),
				];
				unset($data['coupon_code']);
				$session['validation_error'] = sprintf(
					__('Coupon %1$s could not be applied. %2$s', 'ridefleet-ai-chatbot'),
					$coupon,
					(string) ($validation['message'] ?? __('It may be expired, invalid, or fully used.', 'ridefleet-ai-chatbot'))
				);
			}

			$session['collected_data'] = $data;
			if (!empty($session['last_quote'])) {
				$last_quote = $session['last_quote'];
				$last_quote['coupon'] = $coupon_info;
				$session['last_quote'] = $last_quote;
			}
		}

		$interruption = $this->handle_interruption($session, $message);
		if ($interruption) {
			return $this->commit_response($interruption);
		}

		if ($this->should_repair($session, $turn)) {
			$session = $this->increment_uncertainty($session);
			$response = $this->reply($session, $this->repair_message($session));
			return $this->commit_response($response);
		}

		// One-shot field extraction: try to fill multiple slots from a single rich message.
		// IMPORTANT: capture $state_before_capture BEFORE apply_one_shot_fields() changes state,
		// so that merge_place_metadata() knows whether the incoming place belongs to pickup or dropoff.
		// merge_place_metadata() must also run BEFORE capture_for_state() so the _metadata_applied
		// flag is set and capture_for_state() can bypass resolve_place_text() for autocomplete places.
		$state_before_capture = (string) ($session['state'] ?? 'greeting');
		$session = $this->apply_one_shot_fields($session, $message);
		$session = $this->merge_place_metadata($session, $metadata, $state_before_capture);
		$session = $this->capture_for_state($session, $message, $turn);
		if (!empty($session['validation_error']) && 'unknown' === (string) ($turn['intent'] ?? 'unknown')) {
			$session = $this->increment_uncertainty($session);
		}
		$response = $this->next_action($session, $message);

		return $this->commit_response($response);
	}

	/**
	 * Returns the first contact-capture state that still needs a value,
	 * skipping any fields already present in collected_data.
	 * If all three fields are already filled the session jumps straight to
	 * booking_requested — avoiding the "bot asks for name it already knows" loop.
	 */
	/**
	 * After dropoff is collected, go to the next required state.
	 * Order: flight number (airport pickups only) → passengers → quote.
	 * On re-quotes passengers are already known — skip straight to pricing.
	 */
	private function next_state_after_dropoff(array $data): string {
		if (isset($data['passengers'])) {
			return 'quote_requested';
		}
		// Ask for flight number before pax count when pickup is an airport and number not yet given.
		if ($this->is_airport_pickup($data) && !isset($data['flight_number'])) {
			return 'capture_flight_number';
		}
		return 'capture_passengers';
	}

	/**
	 * Returns true when the pickup address looks like an airport.
	 * Checks the stored place types first (most reliable), then falls back to keyword matching.
	 */
	private function is_airport_pickup(array $data): bool {
		$types = (array) ($data['pickup_place']['types'] ?? []);
		if (in_array('airport', $types, true)) {
			return true;
		}
		$address = strtolower((string) ($data['pickup_address'] ?? ''));
		return (bool) preg_match('/\b(airport|aeroport|aéroport|luchthaven|flughafen|aeroporto|btv|jfk|lga|ewr|bos|ord|lax|sfo|mia|atl|dfw|den|sea|pdx|bwi|iad|dca|cvg|pit|buf|alb|bur|msp|mci|tul|hnl|phx|slc|sbn|grr|cls|btl|gtb|msy|ric|clt|rdu|gso|orf|roa|cho|shr|sbn|isp|hvn|hvr|bgr|pwm|swf|ack|hya|leb|mvl|bvt)\b/', $address);
	}

	/**
	 * Extracts a positive integer from a free-text message (e.g. "3", "three", "deux").
	 * Returns -1 if no valid count is found.
	 */
	private function extract_count_from_message(string $message): int {
		$msg = trim($message);
		if (preg_match('/^\d+$/', $msg)) {
			return (int) $msg;
		}
		static $words = [
			'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
			'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
			'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
			'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20,
			'een' => 1, 'twee' => 2, 'drie' => 3, 'vier' => 4, 'vijf' => 5,
			'zes' => 6, 'zeven' => 7, 'acht' => 8, 'negen' => 9, 'tien' => 10,
			'un' => 1, 'une' => 1, 'deux' => 2, 'trois' => 3, 'quatre' => 4, 'cinq' => 5,
			'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10,
		];
		$lower = strtolower($msg);
		if (isset($words[$lower])) {
			return $words[$lower];
		}
		if (preg_match('/\b(\d+)\b/', $msg, $m)) {
			return (int) $m[1];
		}
		return -1;
	}

	/**
	 * Tries to extract a luggage count from a combined passengers+luggage message.
	 * Returns null when no bags keyword is found (caller should default to 0).
	 * Examples: "3 passengers 2 bags", "2 pax no bags", "4 with 0 suitcases".
	 */
	private function extract_luggage_count(string $message): ?int {
		// Explicit zero: "no bags", "geen bagage", "sans bagages", "0 bags", "0 suitcases"
		if (1 === preg_match('/\b(no\s+bag|without\s+bag|geen\s+bag|geen\s+bagage|sans\s+bag|aucun\s+bag|0\s+bag|0\s+suit|0\s+koffer|0\s+valise)\w*/i', $message)) {
			return 0;
		}
		// Count before keyword: "2 bags", "1 suitcase", "3 koffers", "2 valises"
		if (1 === preg_match('/(\d+)\s+(?:bag|suitcase|koffer|valise|luggage|bagage|piece)\w*/i', $message, $m)) {
			return (int) $m[1];
		}
		// Keyword before count: "bags: 2", "bagages 1"
		if (1 === preg_match('/(?:bag|suitcase|koffer|valise|luggage|bagage)\w*\s*[:\-]?\s*(\d+)/i', $message, $m)) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Parses the popular_destinations option into structured preset location chips.
	 * Each entry can be either:
	 *   - "Location Name"              → text chip only
	 *   - "Location Name|lat,lng"      → chip with embedded coordinates (no autocomplete needed)
	 *
	 * Returns an array of objects: { label: string, lat?: float, lng?: float }
	 */
	private function parse_preset_locations(): array {
		$raw = (array) \RideFleetAIChatbot\Support\Options::get('popular_destinations', []);
		$chips = [];
		foreach ($raw as $entry) {
			$entry = sanitize_text_field(trim((string) $entry));
			if ('' === $entry) {
				continue;
			}
			if (str_contains($entry, '|')) {
				[$label, $coords] = explode('|', $entry, 2);
				$label  = trim($label);
				$parts  = explode(',', trim($coords), 2);
				if (count($parts) === 2 && is_numeric(trim($parts[0])) && is_numeric(trim($parts[1]))) {
					$chips[] = [
						'label' => $label,
						'lat'   => (float) trim($parts[0]),
						'lng'   => (float) trim($parts[1]),
					];
					continue;
				}
			}
			// Plain name — no coordinates.
			$chips[] = ['label' => $entry];
		}
		return array_values(array_slice($chips, 0, 8)); // cap at 8 chips to avoid UI overflow
	}

	private function next_contact_state(array $data): string {
		if ('' === trim((string) ($data['customer_name'] ?? ''))) {
			return 'capture_name';
		}
		if ('' === trim((string) ($data['customer_phone'] ?? ''))) {
			return 'capture_phone';
		}
		// Email is optional — only ask if not yet answered (empty string = not asked; null = skipped).
		if (!array_key_exists('customer_email', $data)) {
			return 'capture_email';
		}
		if ('' === trim((string) ($data['pickup_time'] ?? ''))) {
			return 'capture_pickup_time';
		}
		return 'confirm_booking_details';
	}

	private function preserved_contact_data(array $data): array {
		$contact = [];
		if ('' !== trim((string) ($data['customer_name'] ?? ''))) {
			$contact['customer_name'] = sanitize_text_field((string) $data['customer_name']);
		}
		if ('' !== trim((string) ($data['customer_phone'] ?? ''))) {
			$contact['customer_phone'] = sanitize_text_field((string) $data['customer_phone']);
		}
		// Preserve email answer (even null = "skipped") so we don't re-ask returning customers.
		if (array_key_exists('customer_email', $data)) {
			$contact['customer_email'] = $data['customer_email'];
		}
		if ($contact) {
			$contact['returning_contact_reused'] = 1;
		}
		return $contact;
	}

	private function new_booking_prompt(array $session, bool $contact_reused): string {
		if (!$contact_reused) {
			return $this->say($session, 'new_booking');
		}

		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$name = trim((string) ($data['customer_name'] ?? ''));
		$phone = trim((string) ($data['customer_phone'] ?? ''));
		$contact = trim($name . ('' !== $name && '' !== $phone ? ' / ' : '') . $phone);
		if ('' === $contact) {
			return $this->say($session, 'new_booking');
		}

		return sprintf(
			__('I will keep using %s for this new booking. Where should we pick you up?', 'ridefleet-ai-chatbot'),
			$contact
		);
	}

	private function merge_place_metadata(array $session, array $metadata, string $state_before_capture): array {
		if ('place_autocomplete' !== (string) ($metadata['source'] ?? '') || !is_array($metadata['place'] ?? null)) {
			return $session;
		}

		$place = $metadata['place'];
		$description = sanitize_text_field((string) ($place['description'] ?? ''));
		$kind = in_array($state_before_capture, ['capture_pickup', 'confirm_pickup_city', 'greeting'], true) ? 'pickup' : '';
		if (in_array($state_before_capture, ['capture_dropoff', 'confirm_dropoff_city'], true)) {
			$kind = 'dropoff';
		}
		if ('' === $kind || '' === $description) {
			return $session;
		}

		$data = $session['collected_data'];
		$data[$kind . '_place'] = [
			'place_id' => sanitize_text_field((string) ($place['place_id'] ?? '')),
			'description' => $description,
			'main_text' => sanitize_text_field((string) ($place['main_text'] ?? '')),
			'secondary_text' => sanitize_text_field((string) ($place['secondary_text'] ?? '')),
			'types' => array_values(array_filter(array_map('sanitize_key', (array) ($place['types'] ?? [])))),
		];
		if (isset($place['lat'], $place['lng']) && is_numeric($place['lat']) && is_numeric($place['lng'])) {
			$data[$kind . '_place']['lat'] = (float) $place['lat'];
			$data[$kind . '_place']['lng'] = (float) $place['lng'];
			$data[$kind . '_lat'] = (float) $place['lat'];
			$data[$kind . '_lng'] = (float) $place['lng'];
		}
		if ('' !== $data[$kind . '_place']['place_id']) {
			$data[$kind . '_place_id'] = $data[$kind . '_place']['place_id'];
		}
		if (empty($data[$kind . '_address'])) {
			$data[$kind . '_address'] = $description;
		}
		$session['collected_data'] = $data;
		// Signal to capture_for_state() that this slot was already filled from autocomplete —
		// skip resolve_place_text() and accept the address directly without a geocode round-trip.
		$session['_metadata_applied'] = $kind;
		return $session;
	}

	private function capture_for_state(array $session, string $message, array $turn = []): array {
		$data = $session['collected_data'];
		if ('unknown' !== (string) ($turn['intent'] ?? 'unknown')) {
			unset($data['uncertain_count']);
		}

		$extracted = $this->route_from_turn($turn, (string) $session['state']);
		if (!$extracted['pickup_address'] && !$extracted['dropoff_address']) {
			$extracted = $this->extract_route($message);
		}

		if ($extracted['pickup_address'] && $extracted['dropoff_address'] && in_array($session['state'], ['greeting', 'capture_pickup', 'capture_dropoff'], true)) {
			// Guard: AI sometimes extracts the same address for both fields when the user
			// sends a single autocomplete result. If identical, treat as pickup only and ask
			// for the drop-off separately.
			if (strtolower(trim($extracted['pickup_address'])) === strtolower(trim($extracted['dropoff_address']))) {
				$data['pickup_address'] = $extracted['pickup_address'];
				$session['state'] = 'capture_dropoff';
			} else {
				$data['pickup_address'] = $extracted['pickup_address'];
				$data['dropoff_address'] = $extracted['dropoff_address'];
				$session['state'] = 'quote_requested';
			}
			$session['collected_data'] = $data;
			return $session;
		}

		if ('confirm_pickup_city' === $session['state']) {
			$pickup_cands = array_map('strtolower', (array) ($data['pickup_candidates'] ?? []));
			$is_candidate = $pickup_cands && in_array(strtolower($message), $pickup_cands, true);
			// If drop-off is already collected (e.g. user is editing only pickup), skip straight to re-quote.
			$next_after_pickup = !empty($data['dropoff_address']) ? 'quote_requested' : 'capture_dropoff';
			if ($this->is_affirmative($message) || $this->is_city_level_confirmation($message)) {
				$data['pickup_address'] = sanitize_text_field((string) ($data['pending_pickup_city'] ?? ''));
				unset($data['pending_pickup_city'], $data['pickup_candidates']);
				$session['state'] = $next_after_pickup;
			} elseif ($is_candidate) {
				// User clicked an autocomplete candidate — accept it directly as the pickup address.
				$pickup_addr = sanitize_text_field($message);
				$data['pickup_address'] = $pickup_addr;
				// Geocode so flat-rate zone matching has accurate coordinates.
				$pickup_coords = $this->geocode_address($pickup_addr);
				if ($pickup_coords) {
					$data['pickup_lat'] = $pickup_coords['lat'];
					$data['pickup_lng'] = $pickup_coords['lng'];
				}
				unset($data['pending_pickup_city'], $data['pickup_candidates']);
				$session['state'] = $next_after_pickup;
			} elseif ($this->is_negative($message)) {
				unset($data['pending_pickup_city'], $data['pickup_candidates']);
				$session['state'] = 'capture_pickup';
				$session['validation_error'] = __('No problem. Please send the exact pickup street and house number, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
			} else {
				[$svc_lat, $svc_lng] = $this->service_area_bias();
				$place = $this->resolve_place_text($message, $svc_lat, $svc_lng);
				if ($place) {
					$data['pickup_address'] = $place;
					unset($data['pending_pickup_city'], $data['pickup_candidates']);
					$session['state'] = $next_after_pickup;
				} else {
					$session['state'] = 'confirm_pickup_city';
					$session['validation_error'] = __('Please reply yes to use the city, or send a more exact pickup street, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
				}
			}
		} elseif ('confirm_dropoff_city' === $session['state']) {
			$dropoff_cands = array_map('strtolower', (array) ($data['dropoff_candidates'] ?? []));
			$is_candidate  = $dropoff_cands && in_array(strtolower($message), $dropoff_cands, true);
			if ($this->is_affirmative($message) || $this->is_city_level_confirmation($message)) {
				$data['dropoff_address'] = sanitize_text_field((string) ($data['pending_dropoff_city'] ?? ''));
				unset($data['pending_dropoff_city'], $data['dropoff_candidates']);
				$session['state'] = $this->next_state_after_dropoff($data);
			} elseif ($is_candidate) {
				// User clicked an autocomplete candidate — accept it directly as the drop-off address.
				$dropoff_addr = sanitize_text_field($message);
				$data['dropoff_address'] = $dropoff_addr;
				// Geocode so flat-rate zone matching has accurate coordinates.
				$dropoff_coords = $this->geocode_address($dropoff_addr);
				if ($dropoff_coords) {
					$data['dropoff_lat'] = $dropoff_coords['lat'];
					$data['dropoff_lng'] = $dropoff_coords['lng'];
				}
				unset($data['pending_dropoff_city'], $data['dropoff_candidates']);
				$session['state'] = $this->next_state_after_dropoff($data);
			} elseif ($this->is_negative($message)) {
				unset($data['pending_dropoff_city'], $data['dropoff_candidates']);
				$session['state'] = 'capture_dropoff';
				$session['validation_error'] = __('No problem. Please send the exact drop-off street and house number, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
			} else {
				// Use pickup coords as bias; fall back to service-area centre.
				$conf_drp_lat = is_numeric($data['pickup_lat'] ?? null) ? (float) $data['pickup_lat'] : null;
				$conf_drp_lng = is_numeric($data['pickup_lng'] ?? null) ? (float) $data['pickup_lng'] : null;
				if (null === $conf_drp_lat) {
					[$conf_drp_lat, $conf_drp_lng] = $this->service_area_bias();
				}
				$place = $this->resolve_place_text($message, $conf_drp_lat, $conf_drp_lng);
				if ($place) {
					$data['dropoff_address'] = $place;
					unset($data['pending_dropoff_city'], $data['dropoff_candidates']);
					$session['state'] = $this->next_state_after_dropoff($data);
				} else {
					$session['state'] = 'confirm_dropoff_city';
					$session['validation_error'] = __('Please reply yes to use the city, or send a more exact drop-off street, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
				}
			}
		} elseif ('greeting' === $session['state']) {
			// Autocomplete path: merge_place_metadata already stored the address + coords —
			// no geocode round-trip needed; just advance the state.
			if (($session['_metadata_applied'] ?? '') === 'pickup' && !empty($data['pickup_address'])) {
				unset($session['_metadata_applied']);
				$session['state'] = 'capture_dropoff';
				$session['collected_data'] = $data;
				return $session;
			}
			if ($this->is_broad_location_request($message)) {
				$data['pending_pickup_city'] = $this->broad_location_name($message);
				$session['state'] = 'confirm_pickup_city';
			} else {
			[$svc_lat, $svc_lng] = $this->service_area_bias();
			$place = $this->resolve_place_text($message, $svc_lat, $svc_lng);
			if (!$place) {
				$session['state'] = 'capture_pickup';
					$session['validation_error'] = __('I need a real pickup place. Send a street + house number, a station, an airport, a hotel, or a city name.', 'ridefleet-ai-chatbot');
			} else {
				$data['pickup_address'] = $place;
				$session['state'] = 'capture_dropoff';
			}
			}
		} elseif ('capture_pickup' === $session['state']) {
			// Autocomplete path: coordinates already applied by merge_place_metadata.
			if (($session['_metadata_applied'] ?? '') === 'pickup' && !empty($data['pickup_address'])) {
				unset($session['_metadata_applied']);
				// If dropoff is already collected (e.g. user edited only pickup), skip straight to re-quote.
				$session['state'] = !empty($data['dropoff_address']) ? 'quote_requested' : 'capture_dropoff';
				$session['collected_data'] = $data;
				return $session;
			}
			if ($this->is_broad_location_request($message)) {
				$data['pending_pickup_city'] = $this->broad_location_name($message);
				$session['state'] = 'confirm_pickup_city';
			} else {
			[$svc_lat, $svc_lng] = $this->service_area_bias();
			$place = $this->resolve_place_text($message, $svc_lat, $svc_lng);
			if (!$place) {
				$session['state'] = 'capture_pickup';
					$session['validation_error'] = __('I could not recognize that as a pickup location. Please send a clearer place, address, station, airport, or hotel name.', 'ridefleet-ai-chatbot');
			} else {
				$data['pickup_address'] = $place;
				// If dropoff is already collected (e.g. user edited only pickup), skip straight to re-quote.
				$session['state'] = !empty($data['dropoff_address']) ? 'quote_requested' : 'capture_dropoff';
			}
			}
		} elseif ('capture_dropoff' === $session['state']) {
			// apply_one_shot_fields just set the pickup from this same message text —
			// don't also resolve the identical text as the drop-off. Clear the flag and
			// let next_action() ask the user where they want to go.
			if (!empty($session['_one_shot_pickup'])) {
				unset($session['_one_shot_pickup']);
				$session['collected_data'] = $data;
				return $session;
			}
			// Autocomplete path: coordinates already applied by merge_place_metadata.
			if (($session['_metadata_applied'] ?? '') === 'dropoff' && !empty($data['dropoff_address'])) {
				unset($session['_metadata_applied']);
				$session['state'] = $this->next_state_after_dropoff($data);
				$session['collected_data'] = $data;
				return $session;
			}
			if ($this->is_broad_location_request($message)) {
				$data['pending_dropoff_city'] = $this->broad_location_name($message);
				$session['state'] = 'confirm_dropoff_city';
			} else {
			// Use pickup coords as bias so nearby dropoffs rank first; fall back to service-area centre.
			$drp_bias_lat = is_numeric($data['pickup_lat'] ?? null) ? (float) $data['pickup_lat'] : null;
			$drp_bias_lng = is_numeric($data['pickup_lng'] ?? null) ? (float) $data['pickup_lng'] : null;
			if (null === $drp_bias_lat) {
				[$drp_bias_lat, $drp_bias_lng] = $this->service_area_bias();
			}
			$place = $this->resolve_place_text($message, $drp_bias_lat, $drp_bias_lng);
			if (!$place) {
				$session['state'] = 'capture_dropoff';
					$session['validation_error'] = __('I could not recognize that as a drop-off location. Please send a clearer place, address, station, airport, or hotel name.', 'ridefleet-ai-chatbot');
			} else {
				$data['dropoff_address'] = $place;
				$session['state'] = $this->next_state_after_dropoff($data);
			}
			}
		} elseif ('confirm_long_trip' === $session['state'] && $this->is_affirmative($message)) {
			// The long-trip screen already showed the price and the user said yes — that IS the
			// price confirmation. Skip confirm_price entirely and go straight to extras or contact.
			// long_trip_confirmed prevents quote_trip() re-entering confirm_long_trip if the same
			// long route is re-quoted later (e.g. after extras are added).
			$data['_price_confirmed'] = 1;
			$data['long_trip_confirmed'] = 1;
			$session['state'] = $this->has_available_extras() ? 'capture_extras' : $this->next_contact_state($data);
		} elseif ('confirm_long_trip' === $session['state'] && $this->is_negative($message)) {
			$session['state'] = 'capture_pickup';
			$data = ['language' => $data['language'] ?? 'en']; // reset via $data so line 669 persists the clean slate
			$session['last_quote'] = [];
			$session['validation_error'] = __('No problem. Where would you like to be picked up?', 'ridefleet-ai-chatbot');
		} elseif ('confirm_price' === $session['state'] && $this->is_affirmative($message)) {
			// Guard: if last_quote was cleared (e.g. after a failed submission), fall back
			// to dispatch_pending_quote rather than silently looping through name/phone/time.
			if (empty($session['last_quote']['final_price'])) {
				$session['state'] = 'dispatch_pending_quote';
				$session['collected_data']['requires_manual_dispatch'] = 1;
			} else {
				// Quote TTL guard — re-price if the quote is older than 30 minutes.
				$quoted_at = (int) ($session['last_quote']['quoted_at'] ?? 0);
				if ($quoted_at > 0 && (time() - $quoted_at) > 1800) {
					$session['last_quote'] = [];
					$session['state'] = 'quote_requested';
					$lang = (string) ($data['language'] ?? 'en');
					if ('nl' === $lang) {
						$session['validation_error'] = __('De offerte is verlopen (ouder dan 30 minuten). Ik vraag een nieuwe prijs op.', 'ridefleet-ai-chatbot');
					} elseif ('fr' === $lang) {
						$session['validation_error'] = __('Le devis a expiré (plus de 30 minutes). Je recalcule le prix.', 'ridefleet-ai-chatbot');
					} else {
						$session['validation_error'] = __('That quote has expired (quotes are valid for 30 minutes). Fetching a fresh price now.', 'ridefleet-ai-chatbot');
					}
				} else {
					// Mark that the user approved the quoted price. capture_extras will set state
					// to quote_requested for a re-quote; next_action uses this flag to call
					// quote_trip(continue_booking=true) so we skip the second price-confirmation.
					$data['_price_confirmed'] = 1;
					// Offer extras if any are available; otherwise go straight to contact capture.
					$session['state'] = $this->has_available_extras() ? 'capture_extras' : $this->next_contact_state($data);
				}
			}
		} elseif ('confirm_price' === $session['state'] && $this->is_negative($message)) {
			$session['state'] = 'confirm_price';
		} elseif ('confirm_price' === $session['state'] && $this->is_edit_pickup_only_request($message)) {
			// User tapped "Edit pickup" chip — keep drop-off, just re-ask pickup.
			unset($data['pickup_address'], $data['pickup_lat'], $data['pickup_lng'], $data['pickup_place'], $data['pickup_place_id'], $data['_price_confirmed']);
			$session['last_quote'] = [];
			$session['state'] = 'capture_pickup';
			$lang = (string) ($data['language'] ?? 'en');
			if ('nl' === $lang) {
				$session['validation_error'] = __('Geen probleem. Wat is het nieuwe ophaaladres?', 'ridefleet-ai-chatbot');
			} elseif ('fr' === $lang) {
				$session['validation_error'] = __('Pas de problème. Quelle est la nouvelle adresse de départ ?', 'ridefleet-ai-chatbot');
			} else {
				$session['validation_error'] = __('Sure. What is the new pickup address?', 'ridefleet-ai-chatbot');
			}
		} elseif ('confirm_price' === $session['state'] && $this->is_edit_dropoff_only_request($message)) {
			// User tapped "Edit drop-off" chip — keep pickup, just re-ask drop-off.
			unset($data['dropoff_address'], $data['dropoff_lat'], $data['dropoff_lng'], $data['dropoff_place'], $data['dropoff_place_id'], $data['_price_confirmed']);
			$session['last_quote'] = [];
			$session['state'] = 'capture_dropoff';
			$lang = (string) ($data['language'] ?? 'en');
			if ('nl' === $lang) {
				$session['validation_error'] = __('Geen probleem. Wat is het nieuwe afleveradres?', 'ridefleet-ai-chatbot');
			} elseif ('fr' === $lang) {
				$session['validation_error'] = __('Pas de problème. Quelle est la nouvelle adresse de destination ?', 'ridefleet-ai-chatbot');
			} else {
				$session['validation_error'] = __('Sure. What is the new drop-off address?', 'ridefleet-ai-chatbot');
			}
		} elseif ('confirm_price' === $session['state'] && 1 === preg_match('/^apply\s+promo(\s+code)?$/i', trim($message))) {
			$session['state'] = 'capture_coupon';
		} elseif ('confirm_price' === $session['state'] && $this->asks_to_edit_booking($message)) {
			// User clicked "Change details" from the price confirmation screen.
			// Wipe _price_confirmed and last_quote so the next quote forces a fresh price screen.
			$session['state'] = 'capture_pickup';
			$data = ['language' => $data['language'] ?? 'en']; // reset via $data so line 669 persists the clean slate
			$session['last_quote'] = [];
			$session['validation_error'] = __('No problem. Let\'s update the ride details. Where should we pick you up?', 'ridefleet-ai-chatbot');
		} elseif ('dispatch_pending_quote' === $session['state']) {
			if ($this->is_affirmative($message)) {
				// Skip fields already collected
				$session['state'] = $this->next_contact_state($data);
			} elseif ($this->is_negative($message)) {
				$session['state'] = 'capture_pickup';
				$data = ['language' => $data['language'] ?? 'en']; // reset via $data so line 669 persists the clean slate
				$session['last_quote'] = [];
				$session['validation_error'] = __('No problem. Let\'s try a different route. Where should we pick you up?', 'ridefleet-ai-chatbot');
			}
		} elseif ('capture_name' === $session['state']) {
			if (!$this->valid_customer_name($message)) {
				$session['state'] = 'capture_name';
				$session['validation_error'] = __('Please send the customer name for the booking.', 'ridefleet-ai-chatbot');
			} else {
				$data['customer_name'] = sanitize_text_field($message);
				// Advance to the next field that's still missing
				$session['state'] = $this->next_contact_state($data);
			}
		} elseif ('capture_phone' === $session['state']) {
			if (!$this->valid_phone($message)) {
				$session['state'] = 'capture_phone';
				$lang = (string) ($data['language'] ?? 'en');
				$session['validation_error'] = 'nl' === $lang
					? __('Stuur een echt telefoonnummer waarop dispatch u kan bellen of sms\'en.', 'ridefleet-ai-chatbot')
					: ('fr' === $lang
						? __('Envoyez un vrai numero de telephone que le dispatch peut appeler ou texter.', 'ridefleet-ai-chatbot')
						: __('Please enter a real phone number dispatch can call or text.', 'ridefleet-ai-chatbot'));
			} else {
				$data['customer_phone'] = sanitize_text_field($message);
				$session['phone_note'] = $this->phone_note($message, (string) ($data['language'] ?? 'en'));
				// Advance to the next field that's still missing
				$session['state'] = $this->next_contact_state($data);
			}
		} elseif ('capture_email' === $session['state']) {
			$lang = (string) ($data['language'] ?? 'en');
			$skip_words = 'nl' === $lang ? ['skip', 'sla over', 'overslaan', 'geen', 'nee'] : ('fr' === $lang ? ['skip', 'passer', 'non', 'sans'] : ['skip', 'no', 'none', 'later', 'no thanks']);
			$is_skip = '' === trim($message) || in_array(strtolower(trim($message)), $skip_words, true)
				|| preg_match('/\b(skip|sla\s+over|overslaan|passer|no\s+email|geen\s+email)\b/i', $message);
			if ($is_skip) {
				// Mark as explicitly skipped (null = no email, but don't ask again this session).
				$data['customer_email'] = null;
				$session['state'] = $this->next_contact_state($data);
			} elseif (is_email($message)) {
				$data['customer_email'] = sanitize_email($message);
				$session['state'] = $this->next_contact_state($data);
			} else {
				$session['state'] = 'capture_email';
				$session['validation_error'] = 'nl' === $lang
					? __('Dat ziet er niet uit als een geldig e-mailadres. Probeer opnieuw of tik "Overslaan" om door te gaan.', 'ridefleet-ai-chatbot')
					: ('fr' === $lang
						? __('Cela ne ressemble pas a une adresse e-mail valide. Réessayez ou tapez "Passer".', 'ridefleet-ai-chatbot')
						: __('That doesn\'t look like a valid email. Try again or type "skip" to continue without one.', 'ridefleet-ai-chatbot'));
			}
		} elseif ('capture_flight_number' === $session['state']) {
			$lang = (string) ($data['language'] ?? 'en');
			$skip_words = ['skip', 'unknown', 'no', 'none', 'don\'t know', 'dont know', 'overslaan', 'onbekend', 'geen', 'passer', 'inconnu', 'sans'];
			$normalized = strtolower(trim($message));
			if ('' === $normalized || in_array($normalized, $skip_words, true) || 1 === preg_match('/\b(skip|unknown|overslaan|passer|no\s+flight|geen\s+vlucht|sans\s+vol)\b/i', $message)) {
				// User skipped — mark as explicitly skipped (null means "asked and skipped").
				$data['flight_number'] = null;
			} else {
				// Normalize flight number: uppercase, strip spaces, keep only plausible chars.
				$flight = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $message));
				$data['flight_number'] = substr($flight, 0, 10) ?: null;
			}
			$session['state'] = 'capture_passengers';
		} elseif ('capture_passengers' === $session['state']) {
			$lang = (string) ($data['language'] ?? 'en');
			// Try to extract both passenger count and luggage count from one combined reply
			// e.g. "3 passengers 2 bags", "2 pax no bags", "4 with 0 suitcases"
			$pax = $this->extract_count_from_message($message);
			if ($pax < 1) {
				$session['state'] = 'capture_passengers';
				$session['validation_error'] = 'nl' === $lang
					? __('Stuur het aantal passagiers (en eventueel koffers), bijv. "3 personen 2 koffers".', 'ridefleet-ai-chatbot')
					: ('fr' === $lang
						? __('Indiquez le nombre de passagers (et bagages si besoin), ex. "3 passagers 2 valises".', 'ridefleet-ai-chatbot')
						: __('Please tell me the passenger count (and bags if any), e.g. "3 passengers 2 bags".', 'ridefleet-ai-chatbot'));
			} else {
				$data['passengers'] = min(50, $pax);
				// Try to extract a luggage count from the same message — look for a second
				// distinct number that comes after a bag/suitcase/luggage keyword.
				$luggage = $this->extract_luggage_count($message);
				$data['luggage'] = $luggage !== null ? min(30, $luggage) : 0;
				$session['state'] = 'quote_requested';
			}
		} elseif ('capture_luggage' === $session['state']) {
			// Fallback: user reached this state from a legacy session or re-quote.
			$lang    = (string) ($data['language'] ?? 'en');
			$is_zero = 1 === preg_match('/^\s*(0|none|no|skip|nee|aucun|geen)\s*$/i', trim($message));
			$count   = $is_zero ? 0 : $this->extract_count_from_message($message);
			if (!$is_zero && $count < 0) {
				$session['state'] = 'capture_luggage';
				$session['validation_error'] = 'nl' === $lang
					? __('Stuur het aantal koffers, of zeg 0 als u geen grote bagage heeft.', 'ridefleet-ai-chatbot')
					: ('fr' === $lang
						? __('Envoyez le nombre de bagages ou dites 0 si vous n\'en avez pas.', 'ridefleet-ai-chatbot')
						: __('Send the number of large bags, or say 0 if you have none.', 'ridefleet-ai-chatbot'));
			} else {
				$data['luggage']  = min(30, $count);
				$session['state'] = 'quote_requested';
			}
		} elseif ('capture_coupon' === $session['state']) {
			$lang    = (string) ($data['language'] ?? 'en');
			$is_skip = 1 === preg_match('/^\s*(skip|overslaan|passer|sla\s+over)\s*$/i', trim($message));
			if ($is_skip) {
				$session['state'] = $this->has_available_extras() ? 'capture_extras' : $this->next_contact_state($data);
			} else {
				$coupon = strtoupper(preg_replace('/[^A-Z0-9\-_]/i', '', trim($message)));
				if (strlen($coupon) < 2) {
					$session['state'] = 'capture_coupon';
					$session['validation_error'] = 'nl' === $lang
						? __('Ongeldige code. Probeer opnieuw of zeg overslaan.', 'ridefleet-ai-chatbot')
						: ('fr' === $lang
							? __('Code invalide. Réessayez ou dites passer.', 'ridefleet-ai-chatbot')
							: __('Invalid code. Try again or say skip.', 'ridefleet-ai-chatbot'));
				} else {
					$subtotal   = (float) ($session['last_quote']['final_price'] ?? 0);
					$validation = $this->core->validate_coupon($coupon, $subtotal);
					if (!empty($validation['valid'])) {
						$data['coupon_code']   = $coupon;
						$last_quote            = $session['last_quote'];
						$last_quote['coupon']  = ['code' => $coupon, 'status' => 'applied', 'amount' => (float) ($validation['amount'] ?? 0)];
						$session['last_quote'] = $last_quote;
						$discount_fmt          = sprintf('%s %.2f', (string) ($last_quote['currency'] ?? 'USD'), (float) ($validation['amount'] ?? 0));
						$session['validation_error'] = 'nl' === $lang
							? sprintf(__('Code %1$s toegepast: −%2$s korting. We gaan verder!', 'ridefleet-ai-chatbot'), $coupon, $discount_fmt)
							: ('fr' === $lang
								? sprintf(__('Code %1$s appliqué : −%2$s de réduction. On continue !', 'ridefleet-ai-chatbot'), $coupon, $discount_fmt)
								: sprintf(__('Code %1$s applied: −%2$s off. Continuing!', 'ridefleet-ai-chatbot'), $coupon, $discount_fmt));
						$session['state'] = $this->has_available_extras() ? 'capture_extras' : $this->next_contact_state($data);
					} else {
						$session['state'] = 'capture_coupon';
						$session['validation_error'] = 'nl' === $lang
							? sprintf(__('Code %1$s is niet geldig. %2$s Probeer opnieuw of zeg overslaan.', 'ridefleet-ai-chatbot'), $coupon, (string) ($validation['message'] ?? ''))
							: ('fr' === $lang
								? sprintf(__('Le code %1$s est invalide. %2$s Réessayez ou dites passer.', 'ridefleet-ai-chatbot'), $coupon, (string) ($validation['message'] ?? ''))
								: sprintf(__('Code %1$s is not valid. %2$s Try another or say skip.', 'ridefleet-ai-chatbot'), $coupon, (string) ($validation['message'] ?? '')));
					}
				}
			}
		} elseif ('capture_pickup_time' === $session['state']) {
			// ASAP detection — user wants the next available ride.
			if (1 === preg_match('/^\s*(asap|as\s+soon\s+as\s+possible|now|nu|maintenant|meteen|tout\s+de\s+suite)\s*$/i', trim($message))) {
				$data['pickup_time'] = current_time('mysql');
				$data['pickup_asap'] = 1;
				$session['state']    = 'confirm_booking_details';
			} else {
			$pickup_time = $this->normalize_pickup_time($message);
			if (!$pickup_time) {
				$session['state'] = 'capture_pickup_time';
				$session['validation_error'] = __('Please enter a valid future pickup date and time, for example "tomorrow 09:00" or "2026-05-13 09:00".', 'ridefleet-ai-chatbot');
			} else {
				$data['pickup_time'] = $pickup_time;
				$session['state'] = 'confirm_booking_details';
			}
			}
		} elseif ('capture_extras' === $session['state']) {
			// Extract any named extras from the message FIRST so batch sends like
			// "Child seat, Pet transport, done" work in a single round-trip.
			$available = $this->get_available_extras();
			$selected_extras = (array) ($data['extras'] ?? []);
			$matched = false;
			foreach ($available as $extra) {
				if ('' !== $extra['name'] && false !== mb_stripos($message, $extra['name'])) {
					$already = array_column($selected_extras, 'id');
					if (!in_array($extra['id'], $already, true)) {
						$selected_extras[] = $extra;
					}
					$matched = true;
				}
			}
			if ($matched) {
				$data['extras'] = $selected_extras;
			}

			// "done" / "continue" / negative / explicit skip → re-quote with current extras.
			$is_done = $this->is_negative($message)
				|| preg_match('/\b(done|continue|that\'?s?\s+all|finish|no\s+more|no\s+extras?|skip|none|without|geen|klaar|verder|genoeg|sans|fertig|weiter)\b/i', $message);
			if ($is_done) {
				// Re-quote so last_quote.final_price includes any extras surcharge.
				// _price_confirmed=1 tells next_action to use continue_booking=true → no re-confirm screen.
				$session['state'] = 'quote_requested';
			} elseif ($matched) {
				// Extras added but user hasn't said "done" — stay on extras screen.
				$last = end($selected_extras);
				$added_name = is_array($last) ? (string) ($last['name'] ?? '') : '';
				$lang = (string) ($data['language'] ?? 'en');
				$count = count($selected_extras);
				if ('nl' === $lang) {
					$session['validation_error'] = $count > 1
						? sprintf('%d extras geselecteerd. Kies er nog meer of tik "Klaar" om verder te gaan.', $count)
						: sprintf('"%s" toegevoegd. Wilt u nog een extra toevoegen?', $added_name);
				} elseif ('fr' === $lang) {
					$session['validation_error'] = $count > 1
						? sprintf('%d extras sélectionnés. Ajoutez-en d\'autres ou appuyez sur "Terminer".', $count)
						: sprintf('"%s" ajouté. Voulez-vous ajouter un autre extra ?', $added_name);
				} else {
					$session['validation_error'] = $count > 1
						? sprintf('%d extras selected. Add more, or tap "Done" to continue.', $count)
						: sprintf('"%s" added. Want another extra?', $added_name);
				}
				// validation_error causes next_action to return early (before the capture_extras
				// case sets ui_extras_list). Pre-populate it here so the chips are re-sent.
				$session['ui_extras_list'] = $available;
			} else {
				// No recognisable extra named and not "done" — advance (failsafe).
				$session['state'] = 'quote_requested';
			}
		} elseif ('confirm_booking_details' === $session['state']) {
			if ($this->is_affirmative($message)) {
				$session['state'] = 'booking_requested';
			} elseif ($this->is_edit_pickup_only_request($message)) {
				// User tapped "Edit pickup" — keep dropoff + contact fields, just re-ask pickup.
				unset($data['pickup_address'], $data['pickup_lat'], $data['pickup_lng'], $data['pickup_place'], $data['pickup_place_id'], $data['_price_confirmed']);
				$session['last_quote'] = [];
				$session['state'] = 'capture_pickup';
				$lang = (string) ($data['language'] ?? 'en');
				$session['validation_error'] = 'nl' === $lang
					? __('Geen probleem. Wat is het nieuwe ophaaladres?', 'ridefleet-ai-chatbot')
					: ('fr' === $lang ? __('Pas de problème. Quelle est la nouvelle adresse de départ ?', 'ridefleet-ai-chatbot')
					: __('Sure. What is the new pickup address?', 'ridefleet-ai-chatbot'));
			} elseif ($this->is_edit_dropoff_only_request($message)) {
				// User tapped "Edit drop-off" — keep pickup + contact fields, just re-ask drop-off.
				unset($data['dropoff_address'], $data['dropoff_lat'], $data['dropoff_lng'], $data['dropoff_place'], $data['dropoff_place_id'], $data['_price_confirmed']);
				$session['last_quote'] = [];
				$session['state'] = 'capture_dropoff';
				$lang = (string) ($data['language'] ?? 'en');
				$session['validation_error'] = 'nl' === $lang
					? __('Geen probleem. Wat is het nieuwe afleveradres?', 'ridefleet-ai-chatbot')
					: ('fr' === $lang ? __('Pas de problème. Quelle est la nouvelle adresse de destination ?', 'ridefleet-ai-chatbot')
					: __('Sure. What is the new drop-off address?', 'ridefleet-ai-chatbot'));
			} elseif (1 === preg_match('/^change\s+time$/i', trim($message))) {
				// User tapped "Edit time" — just re-ask pickup time, keep everything else.
				unset($data['pickup_time']);
				$session['state'] = 'capture_pickup_time';
				$lang = (string) ($data['language'] ?? 'en');
				$session['validation_error'] = 'nl' === $lang
					? __('Wanneer wilt u worden opgehaald?', 'ridefleet-ai-chatbot')
					: ('fr' === $lang ? __('À quelle heure souhaitez-vous être pris en charge ?', 'ridefleet-ai-chatbot')
					: __('When would you like to be picked up?', 'ridefleet-ai-chatbot'));
			} elseif ($this->is_negative($message) || $this->asks_to_edit_booking($message) || $this->is_reset($message)) {
				$session['state'] = 'capture_pickup';
				$data = ['language' => $data['language'] ?? 'en']; // reset via $data so line 669 persists the clean slate
				$session['last_quote'] = [];
				$session['validation_error'] = __('No problem. Let us update the ride details. Where should we pick you up?', 'ridefleet-ai-chatbot');
			} else {
				$session['state'] = 'confirm_booking_details';
				$session['validation_error'] = __('Please reply yes to submit this booking request, or say change details to update the ride.', 'ridefleet-ai-chatbot');
			}
		} elseif ('confirm_cancellation' === $session['state']) {
			$lang = (string) ($data['language'] ?? 'en');
			$booking_number = (string) ($data['pending_cancel_number'] ?? '');
			if ($this->is_affirmative($message) && $booking_number) {
				$cancelled = false;
				if (class_exists('\\RideFleetBooking\\Booking\\BookingRepository')) {
					$booking_obj = \RideFleetBooking\Booking\BookingRepository::find_by_booking_number($booking_number);
					if ($booking_obj) {
						$cancellable = in_array((string) ($booking_obj->status ?? ''), ['pending_payment', 'pending_dispatch', 'confirmed'], true);
						if ($cancellable) {
							\RideFleetBooking\Booking\BookingRepository::update_status((int) $booking_obj->id, 'cancelled');
							$cancelled = true;
						}
					}
				}
				unset($data['pending_cancel_number']);
				$session['state'] = 'halted';
				if ($cancelled) {
					if ('nl' === $lang) {
						$session['validation_error'] = sprintf(__('Boeking %s is geannuleerd. Als u een nieuwe rit wilt, zeg dan nieuwe boeking.', 'ridefleet-ai-chatbot'), $booking_number);
					} elseif ('fr' === $lang) {
						$session['validation_error'] = sprintf(__('La réservation %s a bien été annulée. Pour une nouvelle course, dites nouvelle réservation.', 'ridefleet-ai-chatbot'), $booking_number);
					} else {
						$session['validation_error'] = sprintf(__('Booking %s has been cancelled. Say new booking if you need another ride.', 'ridefleet-ai-chatbot'), $booking_number);
					}
				} else {
					// Booking not found or already in a final state.
					if ('nl' === $lang) {
						$session['validation_error'] = sprintf(__('Boeking %s kon niet worden geannuleerd. Neem contact op met dispatch voor hulp.', 'ridefleet-ai-chatbot'), $booking_number);
					} elseif ('fr' === $lang) {
						$session['validation_error'] = sprintf(__('La réservation %s n\'a pas pu être annulée. Contactez le dispatch pour obtenir de l\'aide.', 'ridefleet-ai-chatbot'), $booking_number);
					} else {
						$session['validation_error'] = sprintf(__('Booking %s could not be cancelled — it may already be completed or not found. Please contact dispatch for help.', 'ridefleet-ai-chatbot'), $booking_number);
					}
				}
			} elseif ($this->is_negative($message)) {
				unset($data['pending_cancel_number']);
				$session['state'] = 'complete';
				$session['validation_error'] = 'nl' === $lang
					? __('Geen probleem. Uw boeking blijft actief.', 'ridefleet-ai-chatbot')
					: ('fr' === $lang
						? __('Pas de problème. Votre réservation reste active.', 'ridefleet-ai-chatbot')
						: __('No problem. Your booking remains active.', 'ridefleet-ai-chatbot'));
			} else {
				$session['state'] = 'confirm_cancellation';
				$session['validation_error'] = 'nl' === $lang
					? sprintf(__('Antwoord ja om boeking %s te annuleren of nee om te behouden.', 'ridefleet-ai-chatbot'), $booking_number)
					: ('fr' === $lang
						? sprintf(__('Répondez oui pour annuler la réservation %s ou non pour la conserver.', 'ridefleet-ai-chatbot'), $booking_number)
						: sprintf(__('Reply yes to cancel booking %s or no to keep it.', 'ridefleet-ai-chatbot'), $booking_number));
			}
		} elseif ('capture_change_request' === $session['state']) {
			if ($this->is_vague_change_request($message)) {
				$session['state'] = 'capture_change_request';
				$session['validation_error'] = __('What should the new value be? For example: "change destination to Brussels Airport" or "move pickup time to tomorrow 10:00".', 'ridefleet-ai-chatbot');
				$session['collected_data'] = $data;
				return $session;
			}

			$request_id = $this->sessions->log_change_request((int) $session['id'], $session, $message);
			$data['last_change_request_id'] = $request_id;
			$session['state'] = 'change_pending';
		}

		$session['collected_data'] = $data;
		return $session;
	}

	private function next_action(array $session, string $message): array {
		$data = $session['collected_data'];
		if (!empty($session['validation_error'])) {
			$message = (string) $session['validation_error'];
			unset($session['validation_error']);
			return $this->reply($session, $message);
		}

		switch ($session['state']) {
			case 'capture_pickup': {
				$session['ui_popular_destinations'] = $this->parse_preset_locations();
				return $this->reply($session, $this->say($session, 'ask_pickup'));
			}

			case 'capture_dropoff': {
				$session['ui_popular_destinations'] = $this->parse_preset_locations();
				return $this->reply($session, $this->say($session, 'ask_dropoff'));
			}

			case 'capture_flight_number': {
				$session['ui_skip_button'] = __('Skip', 'ridefleet-ai-chatbot');
				return $this->reply($session, $this->say($session, 'ask_flight_number'));
			}

			case 'capture_passengers': {
				return $this->reply($session, $this->say($session, 'ask_passengers_and_luggage'));
			}

			case 'capture_luggage': {
				// Legacy fallback — reached only from old sessions. Ask the single question.
				$lang = (string) ($data['language'] ?? 'en');
				$pax  = (int) ($data['passengers'] ?? 1);
				if ('nl' === $lang) {
					return $this->reply($session, sprintf(__('Hoeveel koffers of grote bagagestukken neemt u mee (voor %d persoon/personen)?', 'ridefleet-ai-chatbot'), $pax));
				}
				if ('fr' === $lang) {
					return $this->reply($session, sprintf(__('Combien de bagages volumineux y aura-t-il (pour %d passager(s)) ?', 'ridefleet-ai-chatbot'), $pax));
				}
				return $this->reply($session, sprintf(__('How many large bags or suitcases will there be (for %d passenger(s))?', 'ridefleet-ai-chatbot'), $pax));
			}

			case 'capture_coupon': {
				$lang = (string) ($data['language'] ?? 'en');
				if ('nl' === $lang) {
					return $this->reply($session, __('Voer uw promotiecode in, of zeg overslaan om door te gaan.', 'ridefleet-ai-chatbot'));
				}
				if ('fr' === $lang) {
					return $this->reply($session, __('Entrez votre code promo, ou dites passer pour continuer.', 'ridefleet-ai-chatbot'));
				}
				return $this->reply($session, __('Enter your promo code, or say skip to continue without one.', 'ridefleet-ai-chatbot'));
			}

			case 'confirm_pickup_city': {
				$city = (string) ($data['pending_pickup_city'] ?? '');
				// Use service-area center as geographic bias so local cities rank first.
				[$bias_lat, $bias_lng] = $this->service_area_bias();
				$candidates = $this->fetch_location_candidates($city, $bias_lat, $bias_lng);
				if ($candidates) {
					$session['collected_data']['pickup_candidates'] = $candidates;
				}

				return $this->reply($session, $this->city_confirmation_prompt($session, 'pickup', $city ?: __('that city', 'ridefleet-ai-chatbot')));
			}

			case 'confirm_dropoff_city': {
				$city = (string) ($data['pending_dropoff_city'] ?? '');
				// Prefer pickup coords as bias; fall back to service-area center.
				$bias_lat = is_numeric($data['pickup_lat'] ?? null) ? (float) $data['pickup_lat'] : null;
				$bias_lng = is_numeric($data['pickup_lng'] ?? null) ? (float) $data['pickup_lng'] : null;
				if (null === $bias_lat) {
					[$bias_lat, $bias_lng] = $this->service_area_bias();
				}
				$candidates = $this->fetch_location_candidates($city, $bias_lat, $bias_lng);
				if ($candidates) {
					$session['collected_data']['dropoff_candidates'] = $candidates;
				}

				return $this->reply($session, $this->city_confirmation_prompt($session, 'dropoff', $city ?: __('that city', 'ridefleet-ai-chatbot')));
			}

			case 'quote_requested': {
				// If the customer already approved the price (confirmed at confirm_price) and
				// we're re-quoting only to refresh the total with extras, skip the second
				// price-confirmation screen and go straight to contact capture.
				$continue_booking = !empty($session['collected_data']['_price_confirmed']);
				if ($continue_booking) {
					unset($session['collected_data']['_price_confirmed']);
				}
				return $this->quote_trip($session, $continue_booking);
			}

			case 'confirm_long_trip':
				return $this->reply($session, $this->long_trip_confirmation_message($session));

			case 'confirm_price':
				return $this->reply($session, $this->say($session, 'ask_confirm'));

			case 'dispatch_pending_quote':
				return $this->reply($session, $this->dispatch_pending_prompt($session));

			case 'capture_extras': {
				$extras = $this->get_available_extras();
				if (empty($extras)) {
					// No extras configured — skip silently.
					$session['state'] = $this->next_contact_state($session['collected_data']);
					return $this->next_action($session, $message);
				}
				$session['ui_extras_list'] = $extras;
				return $this->reply($session, __('Would you like any extras for your trip? Select from the options below or say "no extras" to continue.', 'ridefleet-ai-chatbot'));
			}

			case 'capture_name':
				return $this->reply($session, $this->say($session, 'ask_name'));

			case 'capture_phone':
				return $this->reply($session, $this->say($session, 'ask_phone'));

			case 'capture_email': {
				$lang = (string) ($session['collected_data']['language'] ?? 'en');
				if ('nl' === $lang) {
					$msg = __('Wat is uw e-mailadres? We sturen u een bevestiging van uw boeking. Typ "Overslaan" als u dat liever niet doet.', 'ridefleet-ai-chatbot');
				} elseif ('fr' === $lang) {
					$msg = __('Quelle est votre adresse e-mail ? Nous vous enverrons une confirmation de réservation. Tapez "Passer" si vous préférez ne pas en fournir.', 'ridefleet-ai-chatbot');
				} else {
					$msg = __('What\'s your email address? We\'ll send you a booking confirmation. Type "skip" if you\'d rather not.', 'ridefleet-ai-chatbot');
				}
				return $this->reply($session, $msg);
			}

			case 'capture_pickup_time':
				$note = (string) ($session['phone_note'] ?? '');
				unset($session['phone_note']);
				return $this->reply($session, trim($note . ' ' . $this->say($session, 'ask_time')));

			case 'confirm_booking_details':
				return $this->reply($session, $this->booking_review_message($session));

			case 'booking_requested':
				return $this->submit_booking($session);

			case 'change_pending':
				return $this->reply($session, __('I sent your change request to dispatch for admin approval. Your original booking is still active until dispatch confirms the change. If the route or time changes, the final price may also change and the taxi team will contact you.', 'ridefleet-ai-chatbot'));

		}

		$session['state'] = 'capture_pickup';

		return $this->reply($session, __('I can help with taxi booking questions. Where should we pick you up?', 'ridefleet-ai-chatbot'));
	}

	private function geocode_address(string $address): ?array {
		$key = trim((string) \RideFleetAIChatbot\Support\Options::get('google_places_key', ''));
		if ('' === $key || '' === $address) {
			return null;
		}
		$cache_key = 'rfac_geocode_' . md5($address);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}
		$url = add_query_arg(['address' => $address, 'key' => $key], 'https://maps.googleapis.com/maps/api/geocode/json');
		$resp = wp_remote_get($url, ['timeout' => 5, 'redirection' => 0]);
		if (is_wp_error($resp)) {
			return null;
		}
		$body = json_decode((string) wp_remote_retrieve_body($resp), true);
		$lat = (float) ($body['results'][0]['geometry']['location']['lat'] ?? 0);
		$lng = (float) ($body['results'][0]['geometry']['location']['lng'] ?? 0);
		if (0.0 === $lat && 0.0 === $lng) {
			return null;
		}
		$result = ['lat' => $lat, 'lng' => $lng];
		set_transient($cache_key, $result, DAY_IN_SECONDS);
		return $result;
	}

	/**
	 * Returns [lat, lng] of the service-area center, or [null, null] if not configured.
	 * Used as geographic bias when no pickup coords are available yet.
	 *
	 * @return array{0: float|null, 1: float|null}
	 */
	private function service_area_bias(): array {
		$lat = (float) \RideFleetAIChatbot\Support\Options::get('service_area_lat', 0);
		$lng = (float) \RideFleetAIChatbot\Support\Options::get('service_area_lng', 0);
		if (0.0 === $lat && 0.0 === $lng) {
			return [null, null];
		}
		return [$lat, $lng];
	}

	private function outside_service_area(string $address): bool {
		// If the booking plugin is co-located, defer to its authoritative service-area check.
		// This avoids duplicate configuration and supports polygon/state zone types, not just radius.
		if (class_exists('\\RideFleetBooking\\Booking\\CoreBookingPricingEngine')) {
			$status = \RideFleetBooking\Booking\CoreBookingPricingEngine::service_area_status_from_request([
				'pickup_address' => $address,
			]);
			// 'pickup_allowed' is only explicitly false when the plugin has the guard enabled
			// AND has determined the address is outside. Missing key = unknown = allow through.
			return isset($status['pickup_allowed']) && false === (bool) $status['pickup_allowed'];
		}

		// Standalone fallback: use the chatbot's own lat/lng/radius settings.
		$centre_lat = (float) \RideFleetAIChatbot\Support\Options::get('service_area_lat', 0);
		$centre_lng = (float) \RideFleetAIChatbot\Support\Options::get('service_area_lng', 0);
		$radius = max(1, (int) \RideFleetAIChatbot\Support\Options::get('service_area_radius_km', 100));
		if (0.0 === $centre_lat && 0.0 === $centre_lng) {
			return false; // guard disabled — no centre configured
		}
		$coords = $this->geocode_address($address);
		if (!$coords) {
			return false; // can't determine — allow through
		}
		$dlat = deg2rad($coords['lat'] - $centre_lat);
		$dlng = deg2rad($coords['lng'] - $centre_lng);
		$a = sin($dlat / 2) ** 2 + cos(deg2rad($centre_lat)) * cos(deg2rad($coords['lat'])) * sin($dlng / 2) ** 2;
		$dist = 6371 * 2 * asin(sqrt($a));
		return $dist > $radius;
	}

	private function service_area_declined_message(array $session): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('fr' === $lang) {
			return __('Désolé, ce trajet est en dehors de notre zone de service. Contactez dispatch directement si vous pensez que c\'est une erreur.', 'ridefleet-ai-chatbot');
		}
		if ('nl' === $lang) {
			return __('Sorry, dit traject valt buiten ons servicegebied. Neem contact op met dispatch als u denkt dat dit een fout is.', 'ridefleet-ai-chatbot');
		}
		return __('Sorry, that route is outside our service area. Please contact dispatch directly if you believe this is an error.', 'ridefleet-ai-chatbot');
	}

	private function quote_trip(array $session, bool $continue_booking = false): array {
		$data = $session['collected_data'];
		if (empty($data['pickup_address']) || empty($data['dropoff_address'])) {
			$session['state'] = empty($data['pickup_address']) ? 'capture_pickup' : 'capture_dropoff';
			return $this->next_action($session, '');
		}

		// Safety net: pickup and drop-off must be different locations.
		// If they are identical the classifier echoed one place into both slots — ask for drop-off again.
		if (strtolower(trim((string) $data['pickup_address'])) === strtolower(trim((string) $data['dropoff_address']))) {
			$lang = (string) ($data['language'] ?? 'en');
			unset($session['collected_data']['dropoff_address'], $session['collected_data']['dropoff_lat'], $session['collected_data']['dropoff_lng'], $session['collected_data']['dropoff_place']);
			$session['state'] = 'capture_dropoff';
			if ('nl' === $lang) {
				$session['validation_error'] = __('Uw ophaal- en bestemmingslocatie lijken hetzelfde te zijn. Waar moet u naartoe?', 'ridefleet-ai-chatbot');
			} elseif ('fr' === $lang) {
				$session['validation_error'] = __('Votre point de prise en charge et votre destination semblent identiques. Où souhaitez-vous aller ?', 'ridefleet-ai-chatbot');
			} else {
				$session['validation_error'] = __('Your pickup and drop-off appear to be the same location. Where would you like to be dropped off?', 'ridefleet-ai-chatbot');
			}
			return $this->next_action($session, '');
		}

		if ($this->outside_service_area((string) $data['pickup_address']) || $this->outside_service_area((string) $data['dropoff_address'])) {
			$session['state'] = 'halted';
			return $this->reply($session, $this->service_area_declined_message($session));
		}

		$quote_details = array_merge($data, [
			'_diagnostic_session_id' => (int) ($session['id'] ?? 0),
			'_diagnostic_session_key' => (string) ($session['session_key'] ?? ''),
		]);
		$quote = $this->core->get_core_trip_price((string) $data['pickup_address'], (string) $data['dropoff_address'], $quote_details);
		if (empty($quote['success']) || !empty($quote['error'])) {
			$raw_msg = (string) ($quote['message'] ?? '');
			// Detect developer/internal error messages that shouldn't be shown to users
			$is_internal = '' === $raw_msg
				|| preg_match('/\b(lat|lng|latitude|longitude|pickup_lat|dropoff_lat|raw\b)/i', $raw_msg)
				|| str_word_count($raw_msg) > 25; // very long technical messages
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			if ($is_internal) {
				// Can't auto-price — proceed to manual dispatch booking
				$session['state'] = 'dispatch_pending_quote';
				$session['collected_data'] = array_merge($data, ['requires_manual_dispatch' => 1]);
				$session['last_quote'] = [];
				if ('nl' === $lang) {
					$user_message = __('Ik kon voor dit traject geen exacte prijs ophalen. Ons dispatchteam zal de prijs bevestigen vóór de rit. Wil je dat ik de boeking toch indient?', 'ridefleet-ai-chatbot');
				} elseif ('fr' === $lang) {
					$user_message = __("Je n'ai pas pu obtenir un tarif exact pour ce trajet. Notre équipe dispatch confirmera le prix avant la course. Voulez-vous que je soumette quand même la réservation ?", 'ridefleet-ai-chatbot');
				} else {
					$user_message = __("I couldn't get an exact fare for this route. Our dispatch team will confirm the price before pickup. Would you like me to submit the booking request anyway?", 'ridefleet-ai-chatbot');
				}
			} else {
				$session['state'] = 'halted';
				$user_message = sanitize_textarea_field($raw_msg);
			}
			return $this->reply($session, $user_message);
		}

		$price = (float) ($quote['final_price'] ?? $quote['price'] ?? $quote['total'] ?? 0);
		$currency = sanitize_text_field((string) ($quote['currency'] ?? 'USD'));
		$zone = sanitize_text_field((string) ($quote['zone_name'] ?? $quote['zoneClassificationName'] ?? $quote['zone'] ?? ''));

		if ($price <= 0) {
			$session['state'] = 'halted';
			return $this->reply($session, __('I could not verify a valid fare for that trip. Please contact dispatch directly.', 'ridefleet-ai-chatbot'));
		}

		$requires_approval = !empty($quote['requires_approval']) || !empty($quote['approval_required']);
		$service_status = is_array($quote['service_area_status'] ?? null) ? $quote['service_area_status'] : [];
		$pickup_allowed = !isset($service_status['pickup_allowed']) || !empty($service_status['pickup_allowed']);
		$dropoff_allowed = !isset($service_status['dropoff_allowed']) || !empty($service_status['dropoff_allowed']);

		$pricing_source = sanitize_key((string) ($quote['pricing_source'] ?? 'standard'));
		$is_flat_rate = str_contains($pricing_source, 'flat_rate');
		$distance_km = round((float) ($quote['distance_km'] ?? 0), 1);
		$duration_min = max(0, (int) ($quote['duration_minutes'] ?? 0));

		$session['last_quote'] = [
			'final_price' => round($price, 2),
			'base_price' => round((float) ($quote['base_price'] ?? $price), 2),
			'currency' => $currency,
			'zone_name' => $zone,
			'addons' => $quote['addons'] ?? [],
			'pricing_type' => $is_flat_rate ? 'flat_rate' : 'metered',
			'distance_km' => $distance_km,
			'duration_minutes' => $duration_min,
			'requires_approval' => $requires_approval,
			'quoted_at' => time(), // unix timestamp; used to enforce a 30-min quote TTL
			'service_area' => [
				'status' => sanitize_key((string) ($service_status['status'] ?? ($requires_approval ? 'approval_required' : 'inside'))),
				'pickup_allowed' => $pickup_allowed,
				'dropoff_allowed' => $dropoff_allowed,
				'message' => sanitize_text_field((string) ($quote['approval_message'] ?? $service_status['message'] ?? '')),
			],
			'raw' => $quote,
		];
		// A trip > 3 h shows the long-trip confirmation screen — but only once.
		// long_trip_confirmed is set when the user said yes on that screen; after that
		// re-quotes (extras surcharge, TTL refresh) bypass the confirmation entirely.
		$already_confirmed_long = !empty($session['collected_data']['long_trip_confirmed']);
		$long_trip = !$continue_booking && !$already_confirmed_long && $duration_min > 180;
		$session['state'] = $continue_booking
			? $this->next_contact_state($session['collected_data'])
			: ($long_trip ? 'confirm_long_trip' : 'confirm_price');

		$zone_text = $zone ? sprintf(__(' (%s)', 'ridefleet-ai-chatbot'), $zone) : '';
		if ($continue_booking) {
			return $this->reply($session, $this->quote_updated_message($session, $currency, $price, $zone_text));
		}

		$message = $this->quote_message($session, (string) $data['pickup_address'], (string) $data['dropoff_address'], $currency, $price, $zone_text);
		if ($requires_approval || !$pickup_allowed || !$dropoff_allowed) {
			$message .= "\n\n" . $this->service_area_warning($session, $pickup_allowed, $dropoff_allowed);
		}

		return $this->reply($session, $message);
	}

	private function service_area_warning(array $session, bool $pickup_allowed, bool $dropoff_allowed): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		$which = '';
		if (!$pickup_allowed && !$dropoff_allowed) {
			$which = 'both';
		} elseif (!$pickup_allowed) {
			$which = 'pickup';
		} elseif (!$dropoff_allowed) {
			$which = 'dropoff';
		}

		if ('nl' === $lang) {
			return __('⚠️ Let op: deze rit valt deels buiten ons standaard serviceaanbod en moet door dispatch worden goedgekeurd voor de boeking definitief is.', 'ridefleet-ai-chatbot');
		}
		if ('fr' === $lang) {
			return __('⚠️ Note : cette course sort de notre zone de service standard. Le dispatch doit l\'approuver manuellement avant la confirmation définitive.', 'ridefleet-ai-chatbot');
		}
		switch ($which) {
			case 'pickup':
				return __('⚠️ Heads up: the pickup is outside our standard service area, so dispatch will manually approve this booking before it\'s confirmed.', 'ridefleet-ai-chatbot');
			case 'dropoff':
				return __('⚠️ Heads up: the drop-off is outside our standard service area, so dispatch will manually approve this booking before it\'s confirmed.', 'ridefleet-ai-chatbot');
			case 'both':
				return __('⚠️ Heads up: both the pickup and drop-off are outside our standard service area, so dispatch will manually approve this booking before it\'s confirmed.', 'ridefleet-ai-chatbot');
		}
		return __('⚠️ Heads up: this route needs dispatch approval before it\'s confirmed.', 'ridefleet-ai-chatbot');
	}

	private function submit_booking(array $session): array {
		$data = $session['collected_data'];
		$quote = $session['last_quote'];
		$required = ['pickup_address', 'dropoff_address', 'customer_name', 'customer_phone', 'pickup_time'];

		foreach ($required as $key) {
			if (empty($data[$key])) {
				$session['state'] = match ($key) {
					'pickup_address' => 'capture_pickup',
					'dropoff_address' => 'capture_dropoff',
					'customer_name' => 'capture_name',
					'customer_phone' => 'capture_phone',
					'pickup_time' => 'capture_pickup_time',
					default => 'capture_pickup',
				};
				return $this->reply($session, __('I am missing one booking detail. Please send it again.', 'ridefleet-ai-chatbot'));
			}
		}

		$payload = [
			'pickup_address' => (string) $data['pickup_address'],
			'dropoff_address' => (string) $data['dropoff_address'],
			'customer_name' => (string) $data['customer_name'],
			'customer_phone' => (string) $data['customer_phone'],
			'customerEmail' => is_string($data['customer_email'] ?? null) ? sanitize_email((string) $data['customer_email']) : '',
			'pickup_time' => (string) $data['pickup_time'],
			'final_price' => (float) ($quote['final_price'] ?? 0),
			'currency' => (string) ($quote['currency'] ?? 'USD'),
			'passengers' => max(1, absint($data['passengers'] ?? 1)),
			'luggage' => max(0, absint($data['luggage'] ?? 0)),
			'vehicle_id' => 0,
			'vehicle_name' => '',
			'requires_manual_dispatch' => !empty($data['requires_manual_dispatch']) ? 1 : 0,
			'via_stop' => !empty($data['via_stop']) ? sanitize_text_field((string) $data['via_stop']) : '',
			'extras' => is_array($data['extras'] ?? null) ? $data['extras'] : [],
			'idempotency_key' => hash('sha256', implode('|', [
				(string) ($session['session_key'] ?? ''),
				(string) $data['pickup_address'],
				(string) $data['dropoff_address'],
				(string) $data['customer_phone'],
				(string) $data['pickup_time'],
				(string) (!empty($data['requires_manual_dispatch']) ? 'manual' : ($quote['final_price'] ?? 0)),
			])),
			'diagnostic_session_id' => (int) ($session['id'] ?? 0),
			'diagnostic_session_key' => (string) ($session['session_key'] ?? ''),
		];
		foreach (['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $coord_key) {
			if (isset($data[$coord_key]) && is_numeric($data[$coord_key])) {
				$payload[$coord_key] = (float) $data[$coord_key];
			}
		}

		if (!empty($data['coupon_code'])) {
			$payload['coupon_code'] = sanitize_text_field((string) $data['coupon_code']);
		}

		// Pass flight number to the booking plugin (null means "not provided / skipped").
		if (array_key_exists('flight_number', $data)) {
			$payload['flight_number'] = is_string($data['flight_number']) ? sanitize_text_field($data['flight_number']) : null;
		}

		$result = $this->core->submit_core_booking($payload);
		$this->sessions->log_booking((int) $session['id'], $payload, $result);

		if (empty($result['success']) || !empty($result['error'])) {
			$raw_msg = (string) ($result['message'] ?? '');
			$lang    = (string) ($session['collected_data']['language'] ?? 'en');
			// Detect internal/technical API errors that should never reach the customer.
			$is_internal = '' === $raw_msg
				|| preg_match('/\b(lat|lng|latitude|longitude|pickup_lat|dropoff_lat|raw\b)/i', $raw_msg)
				|| str_word_count($raw_msg) > 25;
			if ($is_internal) {
				$session['state'] = 'halted';
				$phone = trim((string) \RideFleetAIChatbot\Support\Options::get('dispatch_contact_number', ''));
				if ('nl' === $lang) {
					$user_message = 'Het is ons niet gelukt de boeking in te dienen.' . ($phone ? ' Neem contact op met dispatch via ' . $phone . '.' : ' Neem contact op met dispatch.');
				} elseif ('fr' === $lang) {
					$user_message = "Nous n'avons pas pu soumettre la réservation." . ($phone ? ' Veuillez contacter le dispatch au ' . $phone . '.' : ' Veuillez contacter le dispatch directement.');
				} else {
					$user_message = 'We could not submit the booking.' . ($phone ? ' Please contact dispatch at ' . $phone . '.' : ' Please contact dispatch directly.');
				}
				return $this->reply($session, $user_message);
			}
			if (false !== stripos($raw_msg, 'pickup time')) {
				$session['state'] = 'capture_pickup_time';
			} else {
				$session['state'] = 'confirm_price';
			}
			return $this->reply($session, sanitize_textarea_field($raw_msg ?: __('Dispatch could not create the booking. Please try again.', 'ridefleet-ai-chatbot')));
		}

		$booking_id = sanitize_text_field((string) ($result['booking_id'] ?? $result['bookingId'] ?? $result['id'] ?? ''));
		$booking_number_from_result = sanitize_text_field((string) ($result['bookingNumber'] ?? $result['booking_number'] ?? ''));
		$data['last_booking_id'] = $booking_id;
		if ($booking_number_from_result) {
			$data['last_booking_number'] = $booking_number_from_result;
		}
		$session['collected_data'] = $data;
		$session['state'] = 'complete';

		// Customer confirmation email — best-effort, never blocks the booking flow.
		try {
			\RideFleetAIChatbot\Services\CustomerMailer::send_booking_confirmation(
				$session,
				$booking_number_from_result ?: $booking_id
			);
		} catch (\Throwable $e) {
			// Intentionally swallowed.
		}

		// Optional: generate Stripe payment link
		$payment_url = null;
		if (!empty(\RideFleetAIChatbot\Support\Options::get('stripe_payment_link_enabled'))) {
			$amount_major = (float) ($quote['final_price'] ?? 0);
			$currency_code = strtolower((string) ($quote['currency'] ?? 'usd'));
			// Convert to minor units (cents) — most currencies ×100, JPY/KRW ×1
			$no_decimal = in_array($currency_code, ['jpy','krw','vnd','clp','gnf','mga','pyg','rwf','ugx','xaf','xof'], true);
			$amount_cents = $no_decimal ? (int) $amount_major : (int) round($amount_major * 100);
			$desc = sprintf('Taxi booking %s', $booking_id ?: 'pending');
			$payment_url = \RideFleetAIChatbot\Services\StripeClient::create_payment_url(
				(float) $amount_cents, $currency_code, $booking_id ?: '', $desc
			);
		}
		$data['payment_url'] = $payment_url ?? '';
		$session['collected_data'] = $data;

		return $this->reply($session, $this->booking_confirmed_message($session, $booking_id ?: __('pending', 'ridefleet-ai-chatbot')));
	}

	private function reply(array $session, string $message): array {
		return [
			'session' => $session,
			'message' => $message,
		];
	}

	private function format_response(array $response): array {
		return [
			'session_id' => $response['session']['session_key'],
			'state' => $response['session']['state'],
			'message' => $response['message'],
			'data' => $this->public_data($response['session']),
		];
	}

	private function commit_response(array $response): array {
		$state_before_localize = (string) ($response['session']['state'] ?? '');
		$lang = (string) ($response['session']['collected_data']['language'] ?? 'en');
		$response['message'] = $this->ai->localize_reply((string) $response['message'], $lang, $response['session']);
		// format_response() reads ui_extras_list / ui_popular_destinations from the session,
		// so call it BEFORE we unset those transient fields and persist to the DB.
		$formatted = $this->format_response($response);
		// Per-response ui_ fields — don't persist in DB
		unset($response['session']['ui_popular_destinations'], $response['session']['ui_extras_list'], $response['session']['ui_skip_button']);
		$this->sessions->update($response['session']);
		$this->sessions->add_message((int) $response['session']['id'], 'assistant', $response['message'], $this->admin_summary($response['message'], $lang, 'assistant', $this->current_turn), $this->message_meta($this->current_turn, $lang));
		DiagnosticLogger::log((int) $response['session']['id'], (string) $response['session']['session_key'], 'assistant_response', 'conversation', 'Assistant response committed.', [
			'state_after' => $state_before_localize,
			'language' => $lang,
			'message' => (string) $response['message'],
			'collected_data' => $response['session']['collected_data'] ?? [],
			'last_quote' => $response['session']['last_quote'] ?? [],
		]);
		return $formatted;
	}

	/**
	 * Runs the AI field extractor on the current message and pre-fills every
	 * collected_data slot it finds, then advances the session state past any
	 * states whose data is now complete.
	 */
	private function apply_one_shot_fields(array $session, string $message): array {
		$active_states = ['greeting', 'capture_pickup', 'capture_dropoff', 'capture_extras', 'capture_name', 'capture_phone', 'capture_pickup_time'];
		if (!in_array((string) ($session['state'] ?? ''), $active_states, true)) {
			return $session;
		}

		// Skip AI extraction when the local classifier is already certain about what the
		// message contains. A phone number, name, or time string has nothing more to
		// extract — running the AI here wastes 300–800 ms for zero benefit.
		$turn = $this->current_turn;
		$single_field_intents = ['name', 'phone', 'time', 'confirmation', 'cancel', 'smalltalk', 'edit_request'];
		if (!empty($turn['intent']) && in_array($turn['intent'], $single_field_intents, true) && ($turn['confidence'] ?? 0) >= 0.85) {
			return $session;
		}

		// OPTIMISATION: the AI classifier already extracted booking fields in the same API call.
		// Re-use them directly instead of making a second round-trip to extract_booking_fields().
		// Only fall back to the dedicated extractor when the current turn came from the local
		// heuristic classifier (source = 'local'), which never extracts location / time / contact.
		if ('local' !== ($turn['source'] ?? 'local') && !empty($turn['fields'])) {
			$raw = $turn['fields'];
			$fields = [];
			// Carry across location + time fields (same key names in both classifier and extractor).
			foreach (['pickup', 'dropoff', 'pickup_time'] as $f) {
				if (!empty($raw[$f])) {
					$fields[$f] = (string) $raw[$f];
				}
			}
			// Classifier uses 'name'/'phone'; extractor uses 'customer_name'/'customer_phone'.
			if (!empty($raw['name'])) {
				$fields['customer_name'] = (string) $raw['name'];
			}
			if (!empty($raw['phone'])) {
				$fields['customer_phone'] = (string) $raw['phone'];
			}
			// Numeric counters are already normalised in normalize_classification().
			foreach (['passenger_count', 'luggage_count'] as $counter) {
				if (isset($raw[$counter]) && is_numeric($raw[$counter]) && (int) $raw[$counter] >= 0) {
					$fields[$counter] = (int) $raw[$counter];
				}
			}
			if (empty($fields)) {
				return $session; // Nothing useful to apply.
			}
		} else {
			$extracted = $this->ai->extract_booking_fields($message, $session);
			if (empty($extracted['success']) || empty($extracted['fields'])) {
				return $session;
			}
			$fields = $extracted['fields'];
		}
		$data = $session['collected_data'];

		// Remember whether pickup was empty BEFORE this extractor ran.
		// Used below to guard against also treating the same message text as the drop-off.
		$pickup_was_empty = empty($data['pickup_address']);

		// Pickup — validate before storing (use service-area center as geographic bias)
		[$svc_lat, $svc_lng] = $this->service_area_bias();
		if (!empty($fields['pickup']) && empty($data['pickup_address'])) {
			if ($this->looks_like_real_place($fields['pickup'])) {
				$resolved = $this->resolve_place_text($fields['pickup'], $svc_lat, $svc_lng);
				if ($resolved) {
					$data['pickup_address'] = $resolved;
				} elseif ($this->is_broad_location_request($fields['pickup'])) {
					$data['pending_pickup_city'] = $this->broad_location_name($fields['pickup']);
				}
			}
		}

		// Dropoff — validate before storing (prefer pickup coords as bias, then service-area center).
		// Guard: if the extracted dropoff text is the same as (or contained in) the just-resolved
		// pickup address, the classifier echoed one location into both slots — discard the dropoff.
		$dropoff_candidate = (string) ($fields['dropoff'] ?? '');
		$pickup_resolved   = (string) ($data['pickup_address'] ?? '');
		$same_location     = '' !== $dropoff_candidate
			&& '' !== $pickup_resolved
			&& (
				stripos($pickup_resolved, $dropoff_candidate) !== false
				|| stripos($dropoff_candidate, $pickup_resolved) !== false
				|| strtolower(trim($dropoff_candidate)) === strtolower(trim($pickup_resolved))
			);
		if (!empty($fields['dropoff']) && empty($data['dropoff_address']) && !$same_location) {
			if ($this->looks_like_real_place($fields['dropoff'])) {
				$drp_lat = is_numeric($data['pickup_lat'] ?? null) ? (float) $data['pickup_lat'] : $svc_lat;
				$drp_lng = is_numeric($data['pickup_lng'] ?? null) ? (float) $data['pickup_lng'] : $svc_lng;
				$resolved = $this->resolve_place_text($fields['dropoff'], $drp_lat, $drp_lng);
				if ($resolved) {
					$data['dropoff_address'] = $resolved;
				} elseif ($this->is_broad_location_request($fields['dropoff'])) {
					$data['pending_dropoff_city'] = $this->broad_location_name($fields['dropoff']);
				}
			}
		}

		// Contact fields
		if (!empty($fields['customer_name']) && empty($data['customer_name']) && $this->valid_customer_name($fields['customer_name'])) {
			$data['customer_name'] = sanitize_text_field($fields['customer_name']);
		}

		if (!empty($fields['customer_phone']) && empty($data['customer_phone']) && $this->valid_phone($fields['customer_phone'])) {
			$data['customer_phone'] = sanitize_text_field($fields['customer_phone']);
		}

		// Time
		if (!empty($fields['pickup_time']) && empty($data['pickup_time'])) {
			$normalized = $this->normalize_pickup_time($fields['pickup_time']);
			if ($normalized) {
				$data['pickup_time'] = $normalized;
			}
		}

		// Passengers & luggage — allow greedy extraction from the opener so capture_passengers
		// can be skipped when the user already included pax count in the same message.
		if (isset($fields['passenger_count']) && !isset($data['passengers'])) {
			$pax = (int) $fields['passenger_count'];
			if ($pax >= 1 && $pax <= 20) {
				$data['passengers'] = $pax;
			}
		}
		if (isset($fields['luggage_count']) && !isset($data['luggage'])) {
			$bags = (int) $fields['luggage_count'];
			if ($bags >= 0 && $bags <= 20) {
				$data['luggage'] = $bags;
			}
		}

		$session['collected_data'] = $data;

		// Advance state based on what we now have.
		// Use next_state_after_dropoff() so the flight-number and passenger states
		// are properly respected instead of unconditionally jumping to quote_requested.
		$state = (string) ($session['state'] ?? 'greeting');
		if (in_array($state, ['greeting', 'capture_pickup'], true) && !empty($data['pickup_address']) && !empty($data['dropoff_address'])) {
			$session['state'] = $this->next_state_after_dropoff($data);
		} elseif (in_array($state, ['greeting', 'capture_pickup'], true) && !empty($data['pickup_address'])) {
			$session['state'] = 'capture_dropoff';
			// The message that just set the pickup IS the pickup address text.
			// Flag capture_for_state to skip treating the same message as a drop-off.
			if ($pickup_was_empty) {
				$session['_one_shot_pickup'] = true;
			}
		}

		return $session;
	}

	private function classify_turn(string $message, array $session): array {
		$local = $this->local_classify_turn($message, $session);

		// Skip the AI round-trip when the local classifier is highly confident.
		// name / phone / time / confirmation / cancel / new_booking are deterministic
		// patterns — there is nothing the AI can add that justifies 300–800 ms of latency.
		$deterministic_intents = ['name', 'phone', 'time', 'confirmation', 'cancel', 'new_booking', 'edit_request', 'question', 'smalltalk'];
		if ('unknown' !== $local['intent'] && $local['confidence'] >= 0.85 && in_array($local['intent'], $deterministic_intents, true)) {
			return $local;
		}

		$remote = $this->ai->classify_turn($message, $session);
		if (!empty($remote['success']) && is_array($remote['data'] ?? null)) {
			$remote_data = $this->normalize_turn($remote['data']);
			if (($remote_data['confidence'] ?? 0) >= 0.72 || 'unknown' === $local['intent']) {
				return $this->merge_turns($local, $remote_data);
			}
		}

		return $local;
	}

	private function local_classify_turn(string $message, array $session): array {
		$fields = [];
		$intent = 'unknown';
		$confidence = 0.55;
		$state = (string) ($session['state'] ?? '');

		if ($this->asks_for_new_booking($message)) {
			$intent = 'new_booking';
			$confidence = 0.98;
		} elseif ('capture_name' === $state && $this->valid_customer_name($message)) {
			$intent = 'name';
			$fields['name'] = $message;
			$confidence = 0.88;
		} elseif ('capture_phone' === $state) {
			if ($this->valid_phone($message)) {
				$intent = 'phone';
				$fields['phone'] = $message;
				$confidence = 0.92;
			} else {
				$intent = 'unknown';
				$confidence = 0.78;
			}
		} elseif ('capture_pickup_time' === $state && $this->normalize_pickup_time($message)) {
			$intent = 'time';
			$fields['pickup_time'] = $message;
			$confidence = 0.9;
		} elseif ($this->asks_language_switch($message)) {
			$intent = 'question';
			$fields['language_target'] = $this->requested_language($message);
			$confidence = 0.98;
		} elseif ($this->asks_request_status($message)) {
			$intent = 'question';
			$fields['question_type'] = 'request_status';
			$confidence = 0.92;
		} elseif ($this->asks_to_close_chat($message) || $this->is_cancel($message)) {
			$intent = 'cancel';
			$confidence = 0.96;
		} elseif ($this->external_booking_edit_request($message) || $this->asks_to_edit_booking($message)) {
			$intent = 'edit_request';
			$fields['booking_id'] = $this->external_booking_edit_request($message) ?: null;
			$confidence = 0.86;
		} elseif ($this->normalize_pickup_time($message)) {
			$intent = 'time';
			$fields['pickup_time'] = $message;
			$confidence = 0.88;
		} elseif ($this->valid_phone($message)) {
			$intent = 'phone';
			$fields['phone'] = $message;
			$confidence = 0.92;
		} elseif ($this->is_affirmative($message) || $this->is_negative($message) || $this->is_city_level_confirmation($message)) {
			$intent = 'confirmation';
			$confidence = 0.9;
		} elseif ($this->asks_language_support($message) || $this->asks_bot_name($message) || $this->asks_why_location_needed($message) || $this->asks_if_human($message) || $this->asks_purpose($message)) {
			$intent = 'question';
			$confidence = 0.86;
		} elseif ($this->is_smalltalk($message)) {
			$intent = 'smalltalk';
			$confidence = 0.84;
		} elseif ($this->extract_route_without_geocoding($message)['pickup_address'] || $this->is_broad_location_request($message) || ($this->has_location_signal($message) && !$this->is_non_location_reply($message))) {
			$intent = 'location';
			$route = $this->extract_route_without_geocoding($message);
			$fields = array_filter(
				[
					'pickup' => $route['pickup_address'] ?: null,
					'dropoff' => $route['dropoff_address'] ?: null,
				]
			);
			if (!$fields) {
				$fields['pickup'] = $this->extract_location_candidate($message);
			}
			$confidence = $this->has_specific_location_detail($message) || $this->is_broad_location_request($message) ? 0.82 : 0.68;
		} elseif ($this->looks_like_name_for_state($message, (string) ($session['state'] ?? ''))) {
			$intent = 'name';
			$fields['name'] = $message;
			$confidence = 0.68;
		}

		$lang = $this->detect_language($message, $session);
		return [
			'intent' => $intent,
			'confidence' => $confidence,
			'language' => $lang,
			'fields' => array_filter($fields, static fn($value): bool => null !== $value && '' !== $value),
			'reply_tone' => $this->is_smalltalk($message) ? 'playful' : 'neutral',
			'admin_summary_en' => '',
			'source' => 'local',
		];
	}

	private function normalize_turn(array $turn): array {
		$allowed = ['smalltalk', 'location', 'confirmation', 'question', 'edit_request', 'cancel', 'new_booking', 'time', 'name', 'phone', 'unknown'];
		$intent = sanitize_key((string) ($turn['intent'] ?? 'unknown'));
		if (!in_array($intent, $allowed, true)) {
			$intent = 'unknown';
		}

		$fields = is_array($turn['fields'] ?? null) ? $turn['fields'] : [];
		return [
			'intent' => $intent,
			'confidence' => max(0, min(1, (float) ($turn['confidence'] ?? 0))),
			'language' => in_array(($turn['language'] ?? ''), ['en', 'fr', 'nl'], true) ? (string) $turn['language'] : 'unknown',
			'fields' => array_filter($fields, static fn($value): bool => null !== $value && '' !== $value),
			'reply_tone' => sanitize_key((string) ($turn['reply_tone'] ?? 'neutral')),
			'admin_summary_en' => sanitize_textarea_field((string) ($turn['admin_summary_en'] ?? '')),
			'source' => (string) ($turn['source'] ?? 'openrouter'),
		];
	}

	private function merge_turns(array $local, array $remote): array {
		if ('new_booking' === $local['intent']) {
			return $local;
		}

		if ('location' === $remote['intent'] && 'location' !== $local['intent']) {
			return $local;
		}

		$remote['fields'] = array_filter(array_merge($local['fields'] ?? [], $remote['fields'] ?? []), static fn($value): bool => null !== $value && '' !== $value);
		return $remote;
	}

	private function state_scoped_turn(array $turn, string $state): array {
		$fields = is_array($turn['fields'] ?? null) ? $turn['fields'] : [];
		$allowed = match ($state) {
			'capture_pickup', 'confirm_pickup_city', 'greeting' => ['pickup', 'dropoff', 'language_target', 'booking_id', 'question_type'],
			'capture_dropoff', 'confirm_dropoff_city' => ['dropoff', 'language_target', 'booking_id', 'question_type'],
			'confirm_price', 'confirm_long_trip' => ['proposed_price', 'language_target', 'booking_id', 'question_type'],
			'dispatch_pending_quote' => ['language_target', 'question_type'],
			'capture_name' => ['name', 'language_target', 'question_type'],
			'capture_phone' => ['phone', 'language_target', 'question_type'],
			'capture_pickup_time' => ['pickup_time', 'language_target', 'question_type'],
			'confirm_booking_details' => ['language_target', 'question_type'],
			'capture_extras' => ['extras', 'language_target', 'question_type'],
			default => ['pickup', 'dropoff', 'proposed_price', 'pickup_time', 'name', 'phone', 'passenger_count', 'luggage_count', 'extras', 'vehicle_choice', 'language_target', 'booking_id', 'question_type'],
		};
		$turn['fields'] = array_intersect_key($fields, array_flip($allowed));
		return $turn;
	}

	private function message_meta(array $turn, string $language): array {
		return [
			'detected_language' => $language,
			'intent' => (string) ($turn['intent'] ?? ''),
			'extracted_fields' => is_array($turn['fields'] ?? null) ? $turn['fields'] : [],
		];
	}

	private function locked_language(string $message, array $session, array $turn): string {
		// If the admin has pinned a language, that always takes priority — no per-user switching.
		$admin_lang = trim((string) \RideFleetAIChatbot\Support\Options::get('chatbot_language', ''));
		if ('' !== $admin_lang && 'auto' !== $admin_lang) {
			return $admin_lang;
		}

		$existing = (string) ($session['collected_data']['language'] ?? '');
		$remote = (string) ($turn['language'] ?? '');
		if ($this->asks_language_switch($message)) {
			return $this->requested_language($message) ?: ($existing ?: 'en');
		}

		if ($existing && !$this->asks_language_support($message)) {
			// In complete state, allow the language to follow the customer's new message
			// so a Dutch-completed session doesn't keep replying in Dutch to a fresh English user.
			$state = (string) ($session['state'] ?? '');
			if ('complete' === $state
				&& in_array($remote, ['en', 'fr', 'nl'], true)
				&& 'unknown' !== $remote
				&& $remote !== $existing
			) {
				return $remote;
			}

			return $existing;
		}

		if (in_array($remote, ['en', 'fr', 'nl'], true) && 'unknown' !== $remote) {
			return $remote;
		}

		return $this->detect_language($message, $session);
	}

	private function handle_interruption(array $session, string $message): ?array {
		// Global: contact/phone/email query works from any state
		if (preg_match('/\b(contact|reach|call|email|phone number|who do i call|speak to someone|human|dispatch number|your number|get in touch)\b/i', $message)
			&& !preg_match('/\b(pickup|drop|station|airport|hotel|address)\b/i', $message)) {
			$phone = trim((string) \RideFleetAIChatbot\Support\Options::get('dispatch_contact_number', ''));
			$email = trim((string) \RideFleetAIChatbot\Support\Options::get('notification_email', get_option('admin_email', '')));
			$parts = [];
			if ($phone) {
				$parts[] = sprintf(__('Phone: %s', 'ridefleet-ai-chatbot'), $phone);
			}

			if ($email) {
				$parts[] = sprintf(__('Email: %s', 'ridefleet-ai-chatbot'), $email);
			}

			$contact_text = $parts ? implode(' · ', $parts) : __('Please check the company website for contact details.', 'ridefleet-ai-chatbot');
			return $this->reply_with_next_step($session, sprintf(__('You can reach us at: %s', 'ridefleet-ai-chatbot'), $contact_text));
		}

		// Global: admin FAQ answers
		$faq_answer = $this->match_faq($message);
		if (null !== $faq_answer) {
			return $this->reply_with_next_step($session, $faq_answer);
		}

		if ($this->extract_route($message)['pickup_address']) {
			return null;
		}

		if ($this->is_pause($message)) {
			return $this->reply_with_next_step($session, __('No rush. I will keep your booking flow right here.', 'ridefleet-ai-chatbot'));
		}

		if ($this->asks_for_image($message)) {
			return $this->reply_with_next_step($session, __('I can only help with taxi booking by text, so I cannot create images. I can keep helping with your ride reservation.', 'ridefleet-ai-chatbot'));
		}

		if ($this->asks_admin_config($message)) {
			return $this->reply_with_next_step($session, __('I cannot create zones, change prices, or edit RideFleet settings. An admin has to do that inside the RideFleet dashboard.', 'ridefleet-ai-chatbot'));
		}

		if ($this->asks_whatsapp($message)) {
			return $this->reply_with_next_step($session, __('If dispatch uses WhatsApp, they can contact you on the phone number you provide for the ride. I will collect that during booking.', 'ridefleet-ai-chatbot'));
		}

		if ($this->asks_language_support($message)) {
			return $this->reply_with_next_step($session, $this->language_support_reply($session));
		}

		if ($this->asks_bot_name($message)) {
			return $this->reply_with_next_step($session, $this->bot_name_reply($session));
		}

		if ($this->asks_why_location_needed($message)) {
			return $this->reply_with_next_step($session, __('I need pickup and drop-off details so RideFleet can verify the route, service area, and fare before anything is booked.', 'ridefleet-ai-chatbot'));
		}

		if ($this->asks_current_source($message, $session)) {
			$pickup = (string) ($session['collected_data']['pickup_address'] ?? $session['collected_data']['pending_pickup_city'] ?? '');
			$message_text = $pickup ? sprintf(__('Your current pickup is %s. You can change it by saying change route.', 'ridefleet-ai-chatbot'), $pickup) : __('I do not have a pickup location yet. Send a pickup address, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
			return $this->reply_with_next_step($session, $message_text);
		}

		if ($this->asks_if_human($message)) {
			return $this->reply_with_next_step($session, __('I’m an automated booking assistant, not a human dispatcher. I can help collect ride details and pass approved requests to the taxi team.', 'ridefleet-ai-chatbot'));
		}

		if ($this->asks_purpose($message)) {
			return $this->reply_with_next_step($session, __('I help customers get a verified taxi fare and create a booking with dispatch. I can answer basic company and ride questions, but I cannot change prices or manage RideFleet settings.', 'ridefleet-ai-chatbot'));
		}

		if ('confirm_price' === $session['state'] && $this->mentions_price_or_discount($message)) {
			$quote = $session['last_quote'];
			$price = isset($quote['final_price']) ? sprintf('%s %.2f', (string) ($quote['currency'] ?? 'USD'), (float) $quote['final_price']) : __('the verified fare', 'ridefleet-ai-chatbot');
			return $this->reply($session, sprintf(__('The fare is set at %s by dispatch and cannot be changed here. Reply yes to book at this price, or say change route to check a different trip.', 'ridefleet-ai-chatbot'), $price));
		}

		if ('confirm_price' === $session['state'] && $this->is_confused($message)) {
			$quote = $session['last_quote'];
			$price = isset($quote['final_price']) ? sprintf('%s %.2f', (string) ($quote['currency'] ?? 'USD'), (float) $quote['final_price']) : __('the verified fare', 'ridefleet-ai-chatbot');
			return $this->reply($session, sprintf(__('That is the verified fare from RideFleet: %s. I cannot edit it here. Reply yes to book it, or say change route to check another trip.', 'ridefleet-ai-chatbot'), $price));
		}

		if ('confirm_price' === $session['state'] && $this->is_negative($message)) {
			return $this->reply($session, __('No worries. I will keep this quote here. Say yes to book it, change route to price a different trip, or cancel to end this booking.', 'ridefleet-ai-chatbot'));
		}

		if ('vehicle_unavailable' === $session['state']) {
			if (preg_match('/\b(multiple|multi|2|two|several|meer|plusieurs|meerdere|mehrere)\b.*\b(taxi|cab|vehicle|car|ride|rit|voiture)\b|\b(request|book|send|aanvragen|demande)\b.*\b(multiple|multi|meerdere|plusieurs)\b/i', $message)) {
				$data = $session['collected_data'];
				$request_text = sprintf(
					'Multi-vehicle request: %d passengers, %d luggage. Pickup: %s → Dropoff: %s.',
					(int) ($data['passengers'] ?? 1),
					(int) ($data['luggage'] ?? 0),
					(string) ($data['pickup_address'] ?? ''),
					(string) ($data['dropoff_address'] ?? '')
				);
				$this->sessions->log_change_request((int) $session['id'], $session, $request_text);
				$session['state'] = 'change_pending';
				return $this->reply($session, __('Done. I sent a multi-vehicle request to dispatch. They will review your group size and arrange the right vehicles. You will hear back once confirmed.', 'ridefleet-ai-chatbot'));
			}

			if (preg_match('/\b(contact|reach|call|email|phone|who|person|dispatch|human|someone|speak|number|address|details)\b/i', $message)) {
				$phone = trim((string) \RideFleetAIChatbot\Support\Options::get('dispatch_contact_number', ''));
				$email = trim((string) \RideFleetAIChatbot\Support\Options::get('notification_email', get_option('admin_email', '')));
				$parts = [];
				if ($phone) {
					$parts[] = sprintf(__('Phone: %s', 'ridefleet-ai-chatbot'), $phone);
				}

				if ($email) {
					$parts[] = sprintf(__('Email: %s', 'ridefleet-ai-chatbot'), $email);
				}

				$contact_text = $parts ? implode(' · ', $parts) : __('Please contact the company directly via the website.', 'ridefleet-ai-chatbot');
				return $this->reply($session, sprintf(__('Here is how to reach dispatch: %s. They can arrange a vehicle suited for your group size.', 'ridefleet-ai-chatbot'), $contact_text));
			}

			if ($this->is_gratitude_or_goodbye($message) || $this->is_acknowledgement($message) || $this->asks_to_close_chat($message)) {
				return $this->reply($session, __('No problem. Feel free to reach out to dispatch directly, or reopen this chat any time.', 'ridefleet-ai-chatbot'));
			}

			return $this->reply($session, $this->vehicle_unavailable_message($session));
		}

		if ($this->is_smalltalk($message)) {
			return $this->reply_with_next_step($session, $this->smalltalk_reply($message, $session));
		}

		if ('confirm_price' === $session['state']) {
			// Mid-flow passenger/luggage correction
			$new_pax = null;
			$new_lug = null;
			if (preg_match('/\b(\d{1,2})\s+(?:passenger|people|person|pax|travell?er|adult|kid|child)\b/i', $message, $m)) {
				$new_pax = (int) $m[1];
			} elseif (preg_match('/\b(?:passenger|people|person|pax|travell?er)\b.*?\b(\d{1,2})\b/i', $message, $m)) {
				$new_pax = (int) $m[1];
			}

			if (preg_match('/\b(\d{1,2})\s+(?:bag|luggage|suitcase|piece)\b/i', $message, $m)) {
				$new_lug = (int) $m[1];
			} elseif (preg_match('/\b(?:bag|luggage|suitcase)\b.*?\b(\d{1,2})\b/i', $message, $m)) {
				$new_lug = (int) $m[1];
			}

			if (null !== $new_pax || null !== $new_lug) {
				$data = $session['collected_data'];
				if (null !== $new_pax && $new_pax >= 1 && $new_pax <= 16) {
					$data['passengers'] = $new_pax;
				}

				if (null !== $new_lug && $new_lug >= 0 && $new_lug <= 30) {
					$data['luggage'] = $new_lug;
				}

				$session['collected_data'] = $data;
				$session['state'] = 'quote_requested';
				$lang = (string) ($data['language'] ?? 'en');
				$note = 'fr' === $lang
					? __('Bien sur. Je recalcule le prix avec les nouvelles informations.', 'ridefleet-ai-chatbot')
					: ('nl' === $lang
						? __('Geen probleem. Ik bereken de prijs opnieuw met de nieuwe gegevens.', 'ridefleet-ai-chatbot')
						: __('No problem. Let me recalculate with the updated details.', 'ridefleet-ai-chatbot'));
				$recalc = $this->quote_trip($session);
				$recalc['message'] = $note . ' ' . $recalc['message'];
				return $recalc;
			}
		}

		if ('confirm_price' === $session['state']
			&& !$this->is_affirmative($message)
			&& !$this->is_negative($message)
			&& !$this->asks_to_edit_booking($message)
			&& !$this->is_edit_pickup_only_request($message)
			&& !$this->is_edit_dropoff_only_request($message)
			&& 0 === preg_match('/^apply\s+promo(\s+code)?$/i', trim($message))
		) {
			return $this->reply($session, __('I still have your quote ready. Say yes to reserve it, ask me a taxi-related question, or say change route to price a different trip.', 'ridefleet-ai-chatbot'));
		}

		return null;
	}

	private function reply_with_next_step(array $session, string $answer): array {
		$next = $this->next_prompt_for($session);
		return $this->reply($session, trim($answer . ' ' . $next));
	}

	private function next_prompt_for(array $session): string {
		if ('confirm_price' === $session['state']) {
			return $this->say($session, 'ask_confirm');
		}

		if ('capture_dropoff' === $session['state']) {
			return $this->say($session, 'ask_dropoff');
		}

		if ('capture_name' === $session['state']) {
			return $this->say($session, 'ask_name');
		}

		if ('capture_phone' === $session['state']) {
			return $this->say($session, 'ask_phone');
		}

		if ('capture_pickup_time' === $session['state']) {
			return $this->say($session, 'ask_time');
		}

		return $this->say($session, 'ask_pickup');
	}

	private function extract_route(string $message): array {
		if (!$this->turn_allows_location_lookup()) {
			return ['pickup_address' => '', 'dropoff_address' => ''];
		}

		$result = $this->extract_route_without_geocoding($message);

		if ($result['pickup_address']) {
			$result['pickup_address'] = $this->resolve_place_text($result['pickup_address']) ?: $result['pickup_address'];
		}

		if ($result['dropoff_address']) {
			$result['dropoff_address'] = $this->resolve_place_text($result['dropoff_address']) ?: $result['dropoff_address'];
		}

		return $result;
	}

	private function route_from_turn(array $turn, string $state = ''): array {
		if ('location' !== (string) ($turn['intent'] ?? '')) {
			return ['pickup_address' => '', 'dropoff_address' => ''];
		}

		$fields = is_array($turn['fields'] ?? null) ? $turn['fields'] : [];
		$pickup = sanitize_text_field((string) ($fields['pickup'] ?? ''));
		$dropoff = sanitize_text_field((string) ($fields['dropoff'] ?? ''));
		if ('capture_dropoff' === $state || 'confirm_dropoff_city' === $state) {
			$pickup = '';
		}
		if ('capture_pickup' === $state || 'confirm_pickup_city' === $state || 'greeting' === $state) {
			$dropoff = $pickup && $dropoff ? $dropoff : '';
		}
		if ($pickup && !$this->is_broad_location_request($pickup)) {
			$pickup = $this->resolve_place_text($pickup) ?: $pickup;
		}

		if ($dropoff && !$this->is_broad_location_request($dropoff)) {
			$dropoff = $this->resolve_place_text($dropoff) ?: $dropoff;
		}

		return [
			'pickup_address' => $pickup,
			'dropoff_address' => $dropoff,
		];
	}

	private function extract_route_without_geocoding(string $message): array {
		$result = ['pickup_address' => '', 'dropoff_address' => ''];
		if (preg_match('/\bfrom\s+(.+?)\s+\b(?:to|towards?|going to|drop(?:off)?(?: at)?)\s+(.+)$/i', $message, $matches)) {
			$result['pickup_address'] = trim($matches[1]);
			$result['dropoff_address'] = trim($matches[2]);
		} elseif (preg_match('/\b(?:going|go|ride|taxi|cab|trip).*?\bfrom\s+(.+?)\s+\b(?:to|towards?)\s+(.+)$/i', $message, $matches)) {
			$result['pickup_address'] = trim($matches[1]);
			$result['dropoff_address'] = trim($matches[2]);
		} elseif (preg_match('/\bpick(?:up)?(?: me)?(?: at| from)?\s+(.+?)\s+\b(?:and\s+)?drop(?: me)?(?: off)?(?: at)?\s+(.+)$/i', $message, $matches)) {
			$result['pickup_address'] = trim($matches[1]);
			$result['dropoff_address'] = trim($matches[2]);
		} elseif (preg_match('/\bto\s+(.+?)\s+\bfrom\s+(.+)$/i', $message, $matches)) {
			$result['pickup_address'] = trim($matches[2]);
			$result['dropoff_address'] = trim($matches[1]);
		}

		$result['pickup_address'] = $this->clean_extracted_place($result['pickup_address']);
		$result['dropoff_address'] = $this->clean_extracted_place($result['dropoff_address']);

		return $result;
	}

	private function is_affirmative(string $message): bool {
		return 1 === preg_match('/^\s*(yes|yeah|yep|yup|confirm|book|reserve|ok|okay|sure|lets do|let\'s do|do it|that one|proceed|go ahead|oui|ja|zeker|daccord|d\'accord)(?:[,\s]+(?:yes|yeah|yep|yup|ok|okay|sure|oui|ja|zeker|daccord|d\'accord|confirm|please|go|proceed|book|reserve))*\s*[\.\?!]*\s*$/i', $message);
	}

	private function extract_count(string $message): int {
		$value = strtolower(trim($message));
		$words = [
			'zero' => 0,
			'none' => 0,
			'no' => 0,
			'one' => 1,
			'two' => 2,
			'three' => 3,
			'four' => 4,
			'five' => 5,
			'six' => 6,
			'seven' => 7,
			'eight' => 8,
			'nine' => 9,
			'ten' => 10,
		];
		foreach ($words as $word => $count) {
			if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $value)) {
				return $count;
			}
		}
		if (preg_match('/\b(\d{1,2})\b/', $value, $matches)) {
			return (int) $matches[1];
		}
		return -1;
	}

	private function select_vehicle(string $message, array $vehicles): ?array {
		$message = trim($message);
		if (!$vehicles) {
			return null;
		}
		if (preg_match('/\b(\d{1,2})\b/', $message, $matches)) {
			$index = (int) $matches[1] - 1;
			if (isset($vehicles[$index])) {
				return $vehicles[$index];
			}
		}
		foreach ($vehicles as $vehicle) {
			$name = (string) ($vehicle['name'] ?? '');
			if ($name && false !== stripos($message, $name)) {
				return $vehicle;
			}
		}
		return count($vehicles) === 1 && $this->is_affirmative($message) ? $vehicles[0] : null;
	}

	private function select_extras(string $message, array $available): array {
		if (!$available || preg_match('/\b(no|none|nothing|skip|zonder|geen|aucun|sans)\b/i', $message)) {
			return [];
		}
		$selected = [];
		foreach ($available as $index => $extra) {
			$id = absint($extra['id'] ?? 0);
			$name = (string) ($extra['name'] ?? '');
			if (!$id) {
				continue;
			}
			$number = $index + 1;
			if (preg_match('/(?:^|[,\s])' . preg_quote((string) $number, '/') . '(?:$|[,\s])/i', $message) || ($name && false !== stripos($message, $name))) {
				$selected[] = ['id' => $id, 'quantity' => 1, 'name' => sanitize_text_field($name)];
			}
		}
		return $selected;
	}

	private function vehicle_prompt(array $session): string {
		$vehicles = is_array($session['collected_data']['available_vehicles'] ?? null) ? $session['collected_data']['available_vehicles'] : [];
		if (!$vehicles) {
			return $this->say($session, 'ask_name');
		}
		$lines = [];
		foreach ($vehicles as $index => $vehicle) {
			$adjustment = (float) ($vehicle['priceAdjustment'] ?? 0);
			$price = $adjustment > 0 ? sprintf(' (+%s %.2f)', (string) (($session['last_quote']['currency'] ?? 'USD')), $adjustment) : '';
			$lines[] = sprintf('%d. %s - %d passengers, %d bags%s', $index + 1, (string) ($vehicle['name'] ?? 'Vehicle'), (int) ($vehicle['passengers'] ?? 0), (int) ($vehicle['luggage'] ?? 0), $price);
		}
		return $this->say($session, 'ask_vehicle') . ' ' . implode(' ', $lines);
	}

	private function via_stop_prompt(array $session): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('fr' === $lang) {
			return __('Des arrêts en chemin ? Envoyez l\'adresse ou dites non pour aller directement.', 'ridefleet-ai-chatbot');
		}
		if ('nl' === $lang) {
			return __('Tussenstops onderweg? Stuur het adres of zeg nee voor een rechtstreekse rit.', 'ridefleet-ai-chatbot');
		}
		return __('Any stops along the way? Send the address or say no to go direct.', 'ridefleet-ai-chatbot');
	}

	private function dispatch_pending_prompt(array $session): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		$pickup  = (string) ($session['collected_data']['pickup_address'] ?? '—');
		$dropoff = (string) ($session['collected_data']['dropoff_address'] ?? '—');
		if ('nl' === $lang) {
			return sprintf(
				__("Ik kon geen prijs ophalen voor:\n📍 %s → 🏁 %s\n\nDispatch bevestigt de prijs vóór de rit. Wil je de boeking toch indienen? (ja / nee)", 'ridefleet-ai-chatbot'),
				$pickup, $dropoff
			);
		}
		if ('fr' === $lang) {
			return sprintf(
				__("Je n'ai pas pu obtenir de tarif pour :\n📍 %s → 🏁 %s\n\nDispatch confirmera le prix avant la course. Souhaitez-vous quand même soumettre la réservation ? (oui / non)", 'ridefleet-ai-chatbot'),
				$pickup, $dropoff
			);
		}
		return sprintf(
			__("I couldn't price this route:\n📍 %s → 🏁 %s\n\nDispatch will confirm the fare before pickup. Submit the booking anyway? (yes / no)", 'ridefleet-ai-chatbot'),
			$pickup, $dropoff
		);
	}

	private function extras_prompt(array $session): string {
		$extras = is_array($session['collected_data']['available_extras'] ?? null) ? $session['collected_data']['available_extras'] : [];
		if (!$extras) {
			return __('No optional extras are configured for online booking right now, so I will continue without extras.', 'ridefleet-ai-chatbot');
		}
		$currency = (string) ($session['last_quote']['currency'] ?? 'USD');
		$lines = [];
		foreach ($extras as $index => $extra) {
			$lines[] = sprintf('%d. %s (%s %.2f)', $index + 1, (string) ($extra['name'] ?? 'Extra'), $currency, (float) ($extra['price'] ?? 0));
		}
		return $this->say($session, 'ask_extras') . ' ' . implode(' ', $lines);
	}

	private function vehicle_unavailable_message(array $session): string {
		$data = $session['collected_data'];
		$passengers = max(1, (int) ($data['passengers'] ?? 1));
		$luggage = max(0, (int) ($data['luggage'] ?? 0));
		$phone = trim((string) \RideFleetAIChatbot\Support\Options::get('dispatch_contact_number', ''));
		$contact = $phone
			? sprintf(__(' Call dispatch at %s to arrange a suitable vehicle.', 'ridefleet-ai-chatbot'), $phone)
			: __(' Contact dispatch/admin to arrange a suitable vehicle.', 'ridefleet-ai-chatbot');

		$base = sprintf(
			__('No configured vehicle can carry %1$d passenger(s) and %2$d luggage item(s).', 'ridefleet-ai-chatbot'),
			$passengers, $luggage
		);

		// Offer multi-vehicle dispatch for large groups
		if ($passengers >= 5) {
			$lang = (string) ($data['language'] ?? 'en');
			if ('fr' === $lang) {
				return $base . ' ' . __('Pour un grand groupe, je peux envoyer une demande multi-vehicule a dispatch. Repondez "demande multi-vehicule" pour que dispatch organise plusieurs taxis, ou contactez-les directement.', 'ridefleet-ai-chatbot') . $contact;
			}
			if ('nl' === $lang) {
				return $base . ' ' . __('Voor een grote groep kan ik een meervoudig-voertuigaanvraag naar dispatch sturen. Antwoord "meerdere taxis aanvragen" zodat dispatch meerdere voertuigen regelt, of neem rechtstreeks contact op.', 'ridefleet-ai-chatbot') . $contact;
			}
			return $base . ' ' . __('For a large group, I can send a multi-vehicle request to dispatch — reply "request multiple taxis" and they will arrange it. Or contact dispatch directly.', 'ridefleet-ai-chatbot') . $contact;
		}

		return $base . $contact;
	}

	private function quote_message(array $session, string $pickup, string $dropoff, string $currency, float $price, string $zone_text): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		$quote = $session['last_quote'];
		$is_flat = (string) ($quote['pricing_type'] ?? 'metered') === 'flat_rate';
		$duration = (int) ($quote['duration_minutes'] ?? 0);
		$distance = (float) ($quote['distance_km'] ?? 0);

		$price_label = $is_flat ? '🔒 ' : '~ ';
		$price_type = $is_flat
			? __('fixed-price route', 'ridefleet-ai-chatbot')
			: __('estimated fare', 'ridefleet-ai-chatbot');

		$travel = '';
		if ($duration > 0 && $distance > 0) {
			$h = intdiv($duration, 60);
			$m = $duration % 60;
			$dur_text = $h > 0 ? sprintf('%dh %02dmin', $h, $m) : sprintf('%dmin', $m);
			$travel = sprintf(' · %s · %.0f km', $dur_text, $distance);
		}

		if ('fr' === $lang) {
			return sprintf(
				__('Trajet note : %1$s → %2$s%3$s. %4$sPrix (%5$s) : %6$s %7$.2f%8$s. Ce tarif n\'est pas definitif tant que les options et le dispatch ne confirment pas. Voulez-vous continuer ?', 'ridefleet-ai-chatbot'),
				$pickup, $dropoff, $travel, $price_label, $price_type, $currency, $price, $zone_text
			);
		}
		if ('nl' === $lang) {
			return sprintf(
				__('Rit genoteerd : %1$s → %2$s%3$s. %4$sPrijs (%5$s) : %6$s %7$.2f%8$s. Pas definitief na opties en dispatchbevestiging. Wilt u doorgaan ?', 'ridefleet-ai-chatbot'),
				$pickup, $dropoff, $travel, $price_label, $price_type, $currency, $price, $zone_text
			);
		}
		return sprintf(
			__('Got it: %1$s → %2$s%3$s. %4$sYour %5$s is %6$s %7$.2f%8$s. Not final until ride options and dispatch confirm. Would you like to continue?', 'ridefleet-ai-chatbot'),
			$pickup, $dropoff, $travel, $price_label, $price_type, $currency, $price, $zone_text
		);
	}

	private function quote_updated_message(array $session, string $currency, float $price, string $zone_text): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		$prefix = !empty($session['collected_data']['no_extras_available']) ? __('No optional extras are configured for online booking right now. ', 'ridefleet-ai-chatbot') : '';
		if ('fr' === $lang) {
			return $prefix . sprintf(__('Total mis a jour avec vos options : %1$s %2$.2f%3$s. Tres bien. Quel nom devons-nous mettre sur la reservation ?', 'ridefleet-ai-chatbot'), $currency, $price, $zone_text);
		}
		if ('nl' === $lang) {
			return $prefix . sprintf(__('Totaal bijgewerkt met uw opties: %1$s %2$.2f%3$s. Prima. Op welke naam mogen we de boeking zetten?', 'ridefleet-ai-chatbot'), $currency, $price, $zone_text);
		}
		return $prefix . sprintf(__('Updated total with your ride options: %1$s %2$.2f%3$s. Great. What name should we put on the booking?', 'ridefleet-ai-chatbot'), $currency, $price, $zone_text);
	}

	private function long_trip_confirmation_message(array $session): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		$quote = $session['last_quote'];
		$duration = (int) ($quote['duration_minutes'] ?? 0);
		$distance_km = (float) ($quote['distance_km'] ?? 0);
		$h = intdiv($duration, 60);
		$m = $duration % 60;
		$dur_text = $h > 0 ? sprintf('%dh %02dmin', $h, $m) : sprintf('%dmin', $m);
		$currency = (string) ($quote['currency'] ?? 'USD');
		$price = (float) ($quote['final_price'] ?? 0);

		// If the trip spans an improbably large distance (>200 km), add a strong sanity-check
		// warning. A local taxi service is unlikely to serve cross-country routes and the user
		// may have selected the wrong location from autocomplete (e.g. "Williston ND" instead
		// of the nearby "Williston VT").
		$sanity_warning = '';
		if ($distance_km > 200) {
			if ('nl' === $lang) {
				$sanity_warning = sprintf(
					__(' Let op: dit is een rit van %d km. Controleer of u het juiste afleveradres heeft geselecteerd.', 'ridefleet-ai-chatbot'),
					(int) $distance_km
				);
			} elseif ('fr' === $lang) {
				$sanity_warning = sprintf(
					__(' Attention : ce trajet fait %d km. Verifiez que vous avez selectionne la bonne destination.', 'ridefleet-ai-chatbot'),
					(int) $distance_km
				);
			} else {
				$sanity_warning = sprintf(
					__(' Warning: this is a %d km route. Please verify you selected the correct destination — it may not match a nearby location with a similar name.', 'ridefleet-ai-chatbot'),
					(int) $distance_km
				);
			}
		}

		if ('fr' === $lang) {
			return sprintf(__('Ce trajet dure environ %s. Le tarif est %s %.2f.%s Confirmez-vous que vous souhaitez un taxi pour ce long trajet ?', 'ridefleet-ai-chatbot'), $dur_text, $currency, $price, $sanity_warning);
		}
		if ('nl' === $lang) {
			return sprintf(__('Deze rit duurt ongeveer %s. De prijs is %s %.2f.%s Weet u zeker dat u een taxi wilt voor dit lange traject?', 'ridefleet-ai-chatbot'), $dur_text, $currency, $price, $sanity_warning);
		}
		return sprintf(__('Heads up: this is a long trip (~%s). The verified fare is %s %.2f.%s Reply yes to continue, or say change route to check a different trip.', 'ridefleet-ai-chatbot'), $dur_text, $currency, $price, $sanity_warning);
	}

	private function booking_review_message(array $session): string {
		$data = $session['collected_data'];
		$lang = (string) ($data['language'] ?? 'en');
		$pickup = (string) ($data['pickup_address'] ?? '');
		$dropoff = (string) ($data['dropoff_address'] ?? '');
		$name = (string) ($data['customer_name'] ?? '');
		$phone = (string) ($data['customer_phone'] ?? '');
		$time = $this->format_pickup_time((string) ($data['pickup_time'] ?? ''), $lang);
		$manual = !empty($data['requires_manual_dispatch']);
		$quote = is_array($session['last_quote'] ?? null) ? $session['last_quote'] : [];
		$price = isset($quote['final_price']) && !$manual ? sprintf('%s %.2f', (string) ($quote['currency'] ?? 'USD'), (float) $quote['final_price']) : '';
		$terms_url = (string) \RideFleetAIChatbot\Support\Options::get('terms_url', '');

		if ('nl' === $lang) {
			$fare = $manual ? __('Dispatch bevestigt de prijs voor de rit.', 'ridefleet-ai-chatbot') : sprintf(__('Prijs: %s.', 'ridefleet-ai-chatbot'), $price);
			$msg = sprintf(__("Controleer even: %1\$s naar %2\$s voor %3\$s, telefoon %4\$s, ophaaltijd %5\$s. %6\$s Zal ik deze aanvraag indienen?", 'ridefleet-ai-chatbot'), $pickup, $dropoff, $name, $phone, $time, $fare);
			if ($terms_url) {
				$msg .= "\n\n" . sprintf(__('Door te bevestigen gaat u akkoord met onze algemene voorwaarden: %s', 'ridefleet-ai-chatbot'), esc_url($terms_url));
			}
			return $msg;
		}

		if ('fr' === $lang) {
			$fare = $manual ? __('Le dispatch confirmera le prix avant la course.', 'ridefleet-ai-chatbot') : sprintf(__('Prix : %s.', 'ridefleet-ai-chatbot'), $price);
			$msg = sprintf(__("Verification rapide : %1\$s vers %2\$s pour %3\$s, telephone %4\$s, prise en charge %5\$s. %6\$s Dois-je envoyer cette demande ?", 'ridefleet-ai-chatbot'), $pickup, $dropoff, $name, $phone, $time, $fare);
			if ($terms_url) {
				$msg .= "\n\n" . sprintf(__('En confirmant, vous acceptez nos conditions générales : %s', 'ridefleet-ai-chatbot'), esc_url($terms_url));
			}
			return $msg;
		}

		$fare = $manual ? __('Dispatch will confirm the fare before pickup.', 'ridefleet-ai-chatbot') : sprintf(__('Fare: %s.', 'ridefleet-ai-chatbot'), $price);
		$msg = sprintf(__('Quick check: %1$s to %2$s for %3$s, phone %4$s, pickup on %5$s. %6$s Submit this request?', 'ridefleet-ai-chatbot'), $pickup, $dropoff, $name, $phone, $time, $fare);
		if ($terms_url) {
			$msg .= "\n\n" . sprintf(__('By confirming you accept our Terms & Conditions: %s', 'ridefleet-ai-chatbot'), esc_url($terms_url));
		}
		return $msg;
	}

	/**
	 * Formats a stored "Y-m-d H:i:s" pickup time into a human-readable string.
	 * Falls back to the raw value if it can't be parsed.
	 */
	private function format_pickup_time(string $raw, string $lang = 'en'): string {
		if ('' === $raw) {
			return '';
		}
		$ts = strtotime($raw);
		if (!$ts) {
			return $raw;
		}
		// Use WP date helpers so the site timezone is respected.
		if ('nl' === $lang) {
			$days_nl = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];
			$months_nl = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
			$dow = (int) wp_date('w', $ts);
			$moy = (int) wp_date('n', $ts) - 1;
			return $days_nl[$dow] . ' ' . wp_date('j', $ts) . ' ' . $months_nl[$moy] . ' ' . wp_date('Y', $ts) . ' om ' . wp_date('H:i', $ts);
		}
		if ('fr' === $lang) {
			$days_fr = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
			$months_fr = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
			$dow = (int) wp_date('w', $ts);
			$moy = (int) wp_date('n', $ts) - 1;
			return $days_fr[$dow] . ' ' . wp_date('j', $ts) . ' ' . $months_fr[$moy] . ' ' . wp_date('Y', $ts) . ' à ' . wp_date('H:i', $ts);
		}
		// English
		return wp_date('l, F j Y \a\t g:i A', $ts);
	}

	private function booking_confirmed_message(array $session, string $booking_id): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		$eta = max(1, (int) \RideFleetAIChatbot\Support\Options::get('dispatch_response_minutes', 15));
		$eta_mins = $eta === 1 ? __('1 minute', 'ridefleet-ai-chatbot') : sprintf(__('%d minutes', 'ridefleet-ai-chatbot'), $eta);
		if ('fr' === $lang) {
			$eta_mins_fr = $eta === 1 ? '1 minute' : ($eta . ' minutes');
			$message = sprintf(__('Votre demande de course est reçue. Numéro de réservation : %1$s. Le prix reste un devis jusqu\'à confirmation du dispatch. Dispatch vous contactera dans environ %2$s. Besoin d\'une modification ? Demandez un changement et dispatch le vérifiera.', 'ridefleet-ai-chatbot'), $booking_id, $eta_mins_fr);
		} elseif ('nl' === $lang) {
			$eta_mins_nl = $eta === 1 ? '1 minuut' : ($eta . ' minuten');
			$message = sprintf(__('Uw ritaanvraag is ontvangen. Boekingsnummer : %1$s. De prijs blijft een offerte tot dispatch bevestigt. Dispatch neemt contact op binnen ongeveer %2$s. Later iets wijzigen? Vraag een wijziging aan en dispatch controleert die.', 'ridefleet-ai-chatbot'), $booking_id, $eta_mins_nl);
		} else {
			$message = sprintf(__('Your ride request is received. Booking ID: %1$s. The fare is still a quote until dispatch confirms it. Dispatch will be in touch within ~%2$s. Need a change later? Ask for an edit and dispatch will review it.', 'ridefleet-ai-chatbot'), $booking_id, $eta_mins);
		}

		if (!empty($session['collected_data']['payment_url'])) {
			$pay_url = esc_url((string) $session['collected_data']['payment_url']);
			if ('nl' === $lang) {
				$message .= "\n\n💳 " . sprintf(__('Betaal nu online: %s', 'ridefleet-ai-chatbot'), $pay_url);
			} elseif ('fr' === $lang) {
				$message .= "\n\n💳 " . sprintf(__('Payez en ligne maintenant : %s', 'ridefleet-ai-chatbot'), $pay_url);
			} else {
				$message .= "\n\n💳 " . sprintf(__('Pay now online: %s', 'ridefleet-ai-chatbot'), $pay_url);
			}
		}

		return $message;
	}

	private function is_city_level_confirmation(string $message): bool {
		return 1 === preg_match('/^\s*(city|stad|centrum|center|centre|city center|city centre|general|generally)\s*[\.\?!]*\s*$/i', $message);
	}

	private function is_negative(string $message): bool {
		return 1 === preg_match('/\b(no|nope|cancel)\b/i', $message);
	}

	private function detect_coupon_code(string $message): string {
		if (preg_match('/\b(?:coupon|promo|promo\s*code|discount\s*code|code|voucher|gutschein|kortingscode|kortingsbon|bon\s*de\s*reduction|code\s*promo)\s*[:\-]?\s*["\']?([A-Z0-9][A-Z0-9_\-]{2,19})["\']?/iu', $message, $matches)) {
			$candidate = strtoupper(sanitize_text_field($matches[1]));
			// Reject bare keyword fragments that the regex picks up when no real code is present.
			// e.g. "apply promo code" → candidate "CODE", "add coupon" → candidate "COUPON".
			$noise = ['CODE', 'PROMO', 'COUPON', 'DISCOUNT', 'VOUCHER', 'PRICE', 'BOOKING', 'APPLY', 'ADD', 'USE', 'ENTER', 'REDEEM'];
			if (in_array($candidate, $noise, true)) {
				return '';
			}
			return $candidate;
		}
		return '';
	}

	private function is_reset(string $message): bool {
		return 1 === preg_match('/\b(start over|restart|reset|new ride|new booking|change route|different route)\b/i', $message);
	}

	private function asks_for_new_booking(string $message): bool {
		return 1 === preg_match('/\b(book|create|start|make)\b.*\b(new|another)\b.*\b(one|ride|booking|trip)?\b|\bnew one\b/i', $message);
	}

	private function asks_return_trip(string $message): bool {
		return 1 === preg_match('/\b(return|back|reverse|round\s*trip|retour|terugrit|terug|aller[\s\-]retour)\b/i', $message);
	}

	private function is_cancel(string $message): bool {
		return 1 === preg_match('/\b(cancel|stop|forget it|never mind|nevermind)\b/i', $message);
	}

	private function is_smalltalk(string $message): bool {
		return 1 === preg_match('/\b(hi|hello|hey|yo|how are you|how you doing|how is it going|how.*going|are you doing well|doing well|thanks|thank you|lol|haha|joke|funny|good morning|good afternoon|good evening|alles goed|hoe gaat|gaat het goed)\b/i', $message)
			&& 0 === preg_match('/\b(from|to|pickup|pick up|drop|airport|station|hotel|address|book|reserve|taxi|cab|ride)\b/i', $message);
	}

	private function smalltalk_reply(string $message, array $session = []): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if (preg_match('/\b(alles goed|hoe gaat|gaat het goed)\b/i', $message)) {
			return __('Alles goed, dank je. Ik help je graag met een taxi reserveren.', 'ridefleet-ai-chatbot');
		}

		if (preg_match('/\b(goed nederlands|spreekt goed|haha)\b/i', $message)) {
			return __('Dank je. Ik doe mijn best, zolang we netjes richting je taxi blijven rijden.', 'ridefleet-ai-chatbot');
		}

		if (preg_match('/\b(thanks|thank you)\b/i', $message)) {
			return __('You are welcome. I am here with the meter running only metaphorically.', 'ridefleet-ai-chatbot');
		}

		if (preg_match('/\b(joke|funny|lol|haha)\b/i', $message)) {
			return __('I can take a joke. I just have to keep the fare serious because dispatch verifies it.', 'ridefleet-ai-chatbot');
		}

		if (preg_match('/\b(how are you|how you doing|how is it going)\b/i', $message)) {
			if ('fr' === $lang) {
				return __('Je vais bien, merci. Pret a vous aider a reserver un taxi.', 'ridefleet-ai-chatbot');
			}
			if ('nl' === $lang) {
				return __('Met mij gaat het goed, dank je. Ik help je graag met je taxi.', 'ridefleet-ai-chatbot');
			}
			return __('I am doing well, thanks for asking. Ready to help you get a taxi booked.', 'ridefleet-ai-chatbot');
		}

		if ('fr' === $lang) {
			return __('Bonjour. Je peux vous aider a verifier un prix et reserver un taxi.', 'ridefleet-ai-chatbot');
		}
		if ('nl' === $lang) {
			return __('Hallo. Ik kan u helpen een ritprijs te controleren en een taxi te boeken.', 'ridefleet-ai-chatbot');
		}

		return __('Hey. I can help you check a fare and book a taxi.', 'ridefleet-ai-chatbot');
	}

	private function asks_purpose(string $message): bool {
		return 1 === preg_match('/\b(what.*purpose|what.*do you do|who are you|help me with|what can you do)\b/i', $message);
	}

	private function asks_why_location_needed(string $message): bool {
		return 1 === preg_match('/\bwhy\b.*\b(need|want|ask|location|address|pickup|drop|it)\b|\bwhy do you need it\b/i', $message);
	}

	private function asks_current_source(string $message, array $session): bool {
		return 1 === preg_match('/\b(what|where)\b.*\b(source|pickup|pick up|origin)\b/i', $message);
	}

	private function asks_if_human(string $message): bool {
		return 1 === preg_match('/\b(are you human|real person|human dispatcher|are you a bot|robot)\b/i', $message);
	}

	private function is_pause(string $message): bool {
		return 1 === preg_match('/\b(hold on|wait|one sec|one second|give me a second|hang on)\b/i', $message);
	}

	private function asks_for_image(string $message): bool {
		return 1 === preg_match('/\b(image|picture|drawing|draw|generate.*photo|generate.*image|design)\b/i', $message);
	}

	private function asks_whatsapp(string $message): bool {
		return 1 === preg_match('/\bwhats\s*app|whatsapp\b/i', $message);
	}

	private function asks_language_support(string $message): bool {
		if (1 === preg_match('/\b(what|which)\s+language\b/i', $message)) {
			return true;
		}

		return 1 === preg_match('/\b(spreek|praat|begrijp|kan jij|kun jij|parlez|speak|understand)\b.*\b(nederlands|dutch|frans|french|english|engels)\b/i', $message);
	}

	private function asks_language_switch(string $message): bool {
		return 1 === preg_match('/\b(en francais|in french|french please|francais svp|nederlands graag|in dutch|dutch please|switch to english|in english|english please|engels graag)\b/i', $message);
	}

	private function requested_language(string $message): string {
		if (preg_match('/\b(en francais|in french|french please|francais svp)\b/i', $message)) {
			return 'fr';
		}

		if (preg_match('/\b(nederlands graag|in dutch|dutch please)\b/i', $message)) {
			return 'nl';
		}

		if (preg_match('/\b(switch to english|in english|english please|engels graag)\b/i', $message)) {
			return 'en';
		}

		return '';
	}

	private function language_switched_message(string $target): string {
		if ('fr' === $target) {
			return __('Bien sur. Je continue en francais.', 'ridefleet-ai-chatbot');
		}

		if ('nl' === $target) {
			return __('Zeker. Ik ga verder in het Nederlands.', 'ridefleet-ai-chatbot');
		}

		return __('Of course. I will continue in English.', 'ridefleet-ai-chatbot');
	}

	private function asks_request_status(string $message): bool {
		return 1 === preg_match('/\b(status|approved|approval|pending|request|what happened|any update|update on my request|counteroffer)\b/i', $message);
	}

	private function request_status_message(array $session, string $message): string {
		$request = [];
		if (preg_match('/\b(RFB-\d{8}-[A-Z0-9]+)\b/i', $message, $matches)) {
			$request = $this->sessions->latest_request_by_booking(strtoupper($matches[1]));
		}

		if (!$request) {
			$request = $this->sessions->latest_request_for_session((int) ($session['id'] ?? 0));
		}

		if (!$request) {
			return __('I do not see a pending change or fare approval request for this chat yet.', 'ridefleet-ai-chatbot');
		}

		$status = sanitize_key((string) ($request['status'] ?? 'pending'));
		$type = sanitize_key((string) ($request['request_type'] ?? 'booking_change'));
		$note = trim((string) ($request['customer_response'] ?? ''));
		$counter = (float) ($request['counteroffer_price'] ?? 0);
		$currency = (string) ($request['currency'] ?? 'USD');
		if ('approved' === $status) {
			return trim(sprintf(__('Dispatch approved your %s request.%s', 'ridefleet-ai-chatbot'), str_replace('_', ' ', $type), $note ? ' ' . $note : ''));
		}

		if ('rejected' === $status) {
			return trim(sprintf(__('Dispatch rejected your %s request.%s', 'ridefleet-ai-chatbot'), str_replace('_', ' ', $type), $note ? ' ' . $note : ''));
		}

		if ('counteroffer' === $status) {
			$counter_text = $counter > 0 ? sprintf(__(' Counteroffer: %s %.2f.', 'ridefleet-ai-chatbot'), $currency, $counter) : '';
			return trim(sprintf(__('Dispatch reviewed your %s request and sent a counteroffer.%s%s', 'ridefleet-ai-chatbot'), str_replace('_', ' ', $type), $counter_text, $note ? ' ' . $note : ''));
		}

		return __('Your latest request is still pending dispatch review.', 'ridefleet-ai-chatbot');
	}

	private function asks_bot_name(string $message): bool {
		return 1 === preg_match('/\b(hoe heet jij|wat is je naam|what is your name|who are you called)\b/i', $message);
	}

	private function bot_name_reply(array $session): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('nl' === $lang) {
			return __('Ik ben de RideFleet boekingsassistent.', 'ridefleet-ai-chatbot');
		}

		if ('fr' === $lang) {
			return __('Je suis l assistant de reservation RideFleet.', 'ridefleet-ai-chatbot');
		}

		return __('I am the RideFleet booking assistant.', 'ridefleet-ai-chatbot');
	}

	private function language_support_reply(array $session): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('nl' === $lang) {
			return __('Ja, ik kan Nederlands gebruiken voor deze boeking.', 'ridefleet-ai-chatbot');
		}

		if ('fr' === $lang) {
			return __('Oui, je peux continuer en francais pour cette reservation.', 'ridefleet-ai-chatbot');
		}

		return __('Yes, I can adapt the chat language while keeping the booking focused on taxi service.', 'ridefleet-ai-chatbot');
	}

	private function asks_admin_config(string $message): bool {
		return 1 === preg_match('/\b(create|add|edit|delete|change|update|configure|make)\b.*\b(zone|geofence|flat rate|price rule|setting|admin|dashboard)\b/i', $message);
	}

	private function asks_to_edit_booking(string $message): bool {
		return 1 === preg_match('/\b(edit|change|modify|update|move|reschedule|wrong|mistake)\b/i', $message);
	}

	/**
	 * Matches the "edit pickup only" chip message sent from the price confirmation screen.
	 * Kept narrow to avoid false positives from natural language.
	 */
	private function is_edit_pickup_only_request(string $message): bool {
		return 1 === preg_match('/^change\s+pickup(\s+address)?$/i', trim($message));
	}

	/**
	 * Matches the "edit drop-off only" chip message sent from the price confirmation screen.
	 */
	private function is_edit_dropoff_only_request(string $message): bool {
		return 1 === preg_match('/^change\s+drop-?off(\s+address)?$/i', trim($message));
	}

	/**
	 * Detects cancellation intent. Matches natural phrasing like
	 * "cancel my booking", "cancel booking RFB-0042", "I want to cancel", etc.
	 */
	private function is_cancel_booking_request(string $message): bool {
		return 1 === preg_match('/\b(cancel|annul|annuleer|annuler|storneer|stornieren)\b.*(booking|reservation|réservation|boeking|rit|ride)?|^(cancel|annuleer|annuler)\s*$/i', trim($message));
	}

	/**
	 * Extracts a booking reference like "RFB-0042" from a message string.
	 * Returns the matched number in uppercase, or empty string if none found.
	 */
	private function extract_booking_number(string $message): string {
		if (1 === preg_match('/\b(RFB-\d+)\b/i', $message, $m)) {
			return strtoupper($m[1]);
		}
		return '';
	}

	private function asks_to_close_chat(string $message): bool {
		return 1 === preg_match('/\b(close|hide|minimi[sz]e|dismiss|end|finish)\b.*\b(chat|window|bot|conversation)\b|^\s*(end|finish|bye|goodbye)\s*[\.\?!]*$/i', $message);
	}

	private function is_vague_change_request(string $message): bool {
		return 1 === preg_match('/^\s*(destination|dropoff|drop-off|pickup|source|origin|time|date|phone|name|address)\s*[\.\?!]*$/i', $message);
	}

	private function is_acknowledgement(string $message): bool {
		return 1 === preg_match('/^\s*(ok|okay|yes|yep|yup|oui|ja|alright|all right|got it|sounds good|cool|fine|great|done|finished|thanks|thank you|merci|dank je|dankjewel)\s*[\.\?!]*\s*$/i', $message);
	}

	private function is_gratitude_or_goodbye(string $message): bool {
		return 1 === preg_match('/\b(thank you|thanks|merci|dank je|dankjewel|have a nice day|bye|goodbye|see you)\b/i', $message);
	}

	private function external_booking_edit_request(string $message): string {
		if (!$this->asks_to_edit_booking($message)) {
			return '';
		}

		if (preg_match('/\b(RFB-\d{8}-[A-Z0-9]+)\b/i', $message, $matches)) {
			return strtoupper($matches[1]);
		}

		return '';
	}

	private function is_confused(string $message): bool {
		return 1 === preg_match('/^\s*(\?|what+|huh|why|really|seriously|wait|what do you mean)\s*\??\s*$/i', $message);
	}

	private function mentions_price_or_discount(string $message): bool {
		return 1 === preg_match('/\b(less|cheaper|discount|lower|too much|expensive|negotiate|deal|counteroffer|bucks?|dollars?|propose|lower fare|fare|price|cost|\$\d|\d+\s*(?:bucks?|dollars?|eur|euro))\b/i', $message);
	}

	/**
	 * Checks the message against admin-configured FAQ pairs.
	 * Returns the answer string or null if no match.
	 */
	private function match_faq(string $message): ?string {
		$raw = \RideFleetAIChatbot\Support\Options::get('faq_items', []);
		if (!is_array($raw) || empty($raw)) {
			return null;
		}

		$message_lower = strtolower(trim($message));
		foreach ($raw as $item) {
			$question = strtolower(trim((string) ($item['question'] ?? '')));
			$answer = trim((string) ($item['answer'] ?? ''));
			if ('' === $question || '' === $answer) {
				continue;
			}

			// Simple keyword overlap: split question into words and check how many appear in the message
			$words = array_filter(preg_split('/\s+/', $question), fn($w) => strlen($w) > 3);
			if (empty($words)) {
				continue;
			}

			$hits = 0;
			foreach ($words as $word) {
				if (false !== strpos($message_lower, $word)) {
					$hits++;
				}
			}

			// Match if ≥60% of significant words present, or all words if ≤3 words
			$threshold = count($words) <= 3 ? count($words) : (int) ceil(count($words) * 0.6);
			if ($hits >= $threshold) {
				return $answer;
			}
		}

		return null;
	}

	private function valid_phone(string $message): bool {
		$digits = preg_replace('/\D+/', '', $message);
		if (!is_string($digits)) {
			return false;
		}
		$len = strlen($digits);
		// ITU-T E.164: subscriber numbers are 7–15 digits (country code + national number).
		return $len >= 7 && $len <= 15;
	}

	private function valid_customer_name(string $message): bool {
		$value = trim($message);
		if (strlen($value) < 2 || strlen($value) > 80) {
			return false;
		}

		return 0 === preg_match('/^(yes|no|sure|ok|okay|yep|yeah|book|reserve|confirm|maakt niet uit|maak niet uit|does not matter|whatever|geen idee)$/i', $value);
	}

	private function phone_note(string $message, string $language = 'en'): string {
		$digits = preg_replace('/\D+/', '', $message);
		if (!is_string($digits) || strlen($digits) < 7) {
			return '';
		}
		if ('nl' === $language) {
			return __('Telefoonnummer genoteerd. Dispatch gebruikt dit alleen voor deze rit.', 'ridefleet-ai-chatbot');
		}
		if ('fr' === $language) {
			return __('Numero de telephone note. Le dispatch utilisera ce numero uniquement pour cette course.', 'ridefleet-ai-chatbot');
		}
		return __('Phone number noted. Dispatch will use it only for this ride.', 'ridefleet-ai-chatbot');
	}

	private function normalize_pickup_time(string $message): string {
		// "ASAP", "now", "immediately", "right now", "as soon as possible"
		if (preg_match('/^\s*(asap|now|immediately|right\s*now|as\s+soon\s+as\s+possible|straight\s*away|tout\s+de\s+suite|maintenant|nu\s*meteen|zo\s+snel\s+mogelijk)\s*[\.\?!]*\s*$/i', trim($message))) {
			return wp_date('Y-m-d H:i:s', current_time('timestamp') + 15 * MINUTE_IN_SECONDS);
		}

		$value = trim(str_replace('"', ':', $message));
		if (preg_match('/\b24:[0-5]\d\b/', $value)) {
			return '';
		}

		$value = preg_replace('/\bmorgen\b/i', 'tomorrow', (string) $value);
		$value = preg_replace('/\bdemain\b/i', 'tomorrow', (string) $value);
		$value = preg_replace('/\bvandaag\b/i', 'today', (string) $value);
		$value = preg_replace('/\baujourd\'?hui\b/i', 'today', (string) $value);
		$value = preg_replace('/\bovermorgen\b/i', '+2 days', (string) $value);
		$value = preg_replace('/\bapres[-\s]?demain\b/i', '+2 days', (string) $value);
		// Relative-hour expressions: "in 2 hours", "in 30 minutes"
		if (preg_match('/\bin\s+(\d+(?:\.\d+)?)\s+(hour|hr|hours|hrs)\b/i', (string) $value, $hm)) {
			$ts = current_time('timestamp') + (int) round((float) $hm[1] * 3600);
			return wp_date('Y-m-d H:i:s', $ts);
		}

		if (preg_match('/\bin\s+(\d+)\s+(?:minute|min|minutes|mins)\b/i', (string) $value, $hm)) {
			$ts = current_time('timestamp') + (int) $hm[1] * 60;
			if ($ts <= current_time('timestamp') + 4 * 60) {
				return '';
			}

			return wp_date('Y-m-d H:i:s', $ts);
		}

		// "tonight", "this morning", "this afternoon", "this evening" + optional time
		if (preg_match('/\b(tonight|this\s+evening)\b(?:\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?(?:\s*([ap]m?))?)?/i', (string) $value, $hm)) {
			$hour = isset($hm[2]) && '' !== $hm[2] ? (int) $hm[2] : 20;
			$min = isset($hm[3]) && '' !== $hm[3] ? (int) $hm[3] : 0;
			if (isset($hm[4]) && preg_match('/^pm?$/i', $hm[4]) && $hour < 12) {
				$hour += 12;
			}

			$ts = strtotime(wp_date('Y-m-d') . sprintf(' %02d:%02d', $hour, $min));
			if ($ts && $ts > current_time('timestamp')) {
				return wp_date('Y-m-d H:i:s', $ts);
			}
		}

		if (preg_match('/\bthis\s+(morning)\b(?:\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?)?/i', (string) $value, $hm)) {
			$hour = isset($hm[2]) && '' !== $hm[2] ? (int) $hm[2] : 9;
			$min = isset($hm[3]) && '' !== $hm[3] ? (int) $hm[3] : 0;
			$ts = strtotime(wp_date('Y-m-d') . sprintf(' %02d:%02d', $hour, $min));
			if ($ts && $ts > current_time('timestamp')) {
				return wp_date('Y-m-d H:i:s', $ts);
			}
		}

		if (preg_match('/\bthis\s+(afternoon)\b(?:\s+(?:at\s+)?(\d{1,2})(?::(\d{2}))?)?/i', (string) $value, $hm)) {
			$hour = isset($hm[2]) && '' !== $hm[2] ? (int) $hm[2] : 14;
			$min = isset($hm[3]) && '' !== $hm[3] ? (int) $hm[3] : 0;
			$ts = strtotime(wp_date('Y-m-d') . sprintf(' %02d:%02d', $hour, $min));
			if ($ts && $ts > current_time('timestamp')) {
				return wp_date('Y-m-d H:i:s', $ts);
			}
		}

		// "around 6", "at 6pm" without a date — assume today if in future, else tomorrow
		if (preg_match('/\b(?:around|at)?\s*(\d{1,2})(?::(\d{2}))?\s*([ap]m?)?\b/i', (string) $value, $hm)
			&& !preg_match('/\d{4}|\btoday\b|\btomorrow\b|\bnext\b|\bmon\b|\btue\b|\bwed\b|\bthu\b|\bfri\b|\bsat\b|\bsun\b/i', (string) $value)) {
			$hour = (int) $hm[1];
			$min = isset($hm[2]) && '' !== $hm[2] ? (int) $hm[2] : 0;
			if (isset($hm[3]) && preg_match('/^pm?$/i', $hm[3]) && $hour < 12) {
				$hour += 12;
			}

			if (isset($hm[3]) && preg_match('/^am?$/i', $hm[3]) && $hour === 12) {
				$hour = 0;
			}

			$ts = strtotime(wp_date('Y-m-d') . sprintf(' %02d:%02d', $hour, $min));
			if ($ts && $ts > current_time('timestamp')) {
				return wp_date('Y-m-d H:i:s', $ts);
			}

			if ($ts) {
				return wp_date('Y-m-d H:i:s', strtotime('+1 day', $ts));
			}
		}

		$value = preg_replace('/\bom\s+(\d{1,2}:\d{2})\b/i', '$1', (string) $value);
		$value = preg_replace('/\ba\s+(\d{1,2}:\d{2})\b/i', '$1', (string) $value);
		$value = preg_replace('/\b(morning|afternoon|evening|night)\s+(?=\d{1,2}:\d{2}\b)/i', '', (string) $value);
		$value = preg_replace('/\bat\s+(\d{1,2}:\d{2})\b/i', '$1', (string) $value);
		$value = preg_replace('/\b(morning|afternoon|evening|night)\s+(?=\d{1,2}:\d{2}\b)/i', '', (string) $value);
		if (preg_match('/^(?:at\s+)?([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches)) {
			$today = wp_date('Y-m-d');
			$timestamp = strtotime($today . ' ' . $matches[1] . ':' . $matches[2]);
			if ($timestamp && $timestamp <= current_time('timestamp')) {
				$timestamp = strtotime('+1 day', $timestamp);
			}

			return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : '';
		}

		if (!preg_match('/\d{4}|\btoday\b|\btomorrow\b|\bnext\b|\bmon(day)?\b|\btue(sday)?\b|\bwed(nesday)?\b|\bthu(rsday)?\b|\bfri(day)?\b|\bsat(urday)?\b|\bsun(day)?\b/i', $value)) {
			return '';
		}

		$timestamp = strtotime($value);
		if (!$timestamp || $timestamp <= current_time('timestamp')) {
			return '';
		}

		return wp_date('Y-m-d H:i:s', $timestamp);
	}

	private function clean_extracted_place(string $place): string {
		$place = trim($place);
		$place = preg_replace('/^(i\s+will\s+be\s+going|i\s+am\s+going|i\'m\s+going|i\s+need\s+(?:a\s+)?(?:taxi|cab|ride)|please|can\s+you)\s+/i', '', $place);
		$place = preg_replace('/\s+(please|thanks|thank you)$/i', '', (string) $place);

		return trim((string) $place, " \t\n\r\0\x0B.,");
	}

	/**
	 * Returns top 3 distinct place descriptions for a broad location name.
	 * Used to populate disambiguation chips in the widget.
	 */
	private function fetch_location_candidates(string $city, ?float $bias_lat = null, ?float $bias_lng = null): array {
		if ('' === $city) {
			return [];
		}

		$result = $this->core->search_core_places($city, '', $bias_lat, $bias_lng);
		$predictions = is_array($result['predictions'] ?? null) ? $result['predictions'] : [];
		$candidates = [];
		foreach (array_slice($predictions, 0, 4) as $p) {
			$desc = sanitize_text_field((string) ($p['description'] ?? ''));
			if ('' !== $desc && $this->looks_like_real_place($desc)) {
				$candidates[] = $desc;
			}
		}

		return array_values(array_unique($candidates));
	}

	private function resolve_place_text(string $text, ?float $bias_lat = null, ?float $bias_lng = null): string {
		$text = $this->extract_location_candidate($text);
		if (!$this->turn_allows_location_lookup()) {
			return '';
		}

		if ($this->is_broad_location_request($text)) {
			return '';
		}

		if (!$this->looks_like_location($text)) {
			return '';
		}

		if (!$this->looks_like_real_place($text)) {
			return '';
		}

		$result = $this->core->search_core_places($text, '', $bias_lat, $bias_lng);
		$predictions = is_array($result['predictions'] ?? null) ? $result['predictions'] : [];
		if ($this->is_broad_prediction($text, $predictions[0] ?? [])) {
			return '';
		}

		if (!empty($predictions[0]['description'])) {
			return sanitize_text_field((string) $predictions[0]['description']);
		}

		return $this->has_location_signal($text) ? sanitize_text_field($text) : '';
	}

	private function turn_allows_location_lookup(): bool {
		return 'location' === (string) ($this->current_turn['intent'] ?? '') && (float) ($this->current_turn['confidence'] ?? 0) >= 0.62;
	}

	private function looks_like_location(string $text): bool {
		if (strlen($text) < 3 || strlen($text) > 220) {
			return false;
		}

		if ($this->is_non_location_reply($text)) {
			return false;
		}

		if (preg_match('/[?!]{2,}|^(what|why|how|haha|lol|no|yes|maybe|cant|can\'t|joke|thanks?|hello|hey)\b/i', $text)) {
			return $this->has_location_signal($text);
		}

		return true;
	}

	private function has_location_signal(string $text): bool {
		if (1 === preg_match('/\b(station|airport|straat|street|st\.?|avenue|ave\.?|boulevard|blvd\.?|laan|road|rd\.?|drive|dr\.?|lane|ln\.?|hotel|terminal|central|centraal|square|place|plaza|plein|gare|park|mall|tower|airport|harbour|harbor|port|university|hospital|cathedral|museum|stadium|arena|bridge|\d{1,5})\b/i', $text)) {
			return true;
		}

		// Any 2+ capitalized words → likely a proper place name (city, district, landmark).
		if (preg_match_all('/\b[A-Z][a-z]{2,}\b/', $text) >= 2) {
			return true;
		}

		return false;
	}

	private function is_non_location_reply(string $text): bool {
		return 1 === preg_match('/\b(geen idee|weet ik niet|maakt niet uit|maak niet uit|hoe heet jij|spreek jij|nederlands|waarom|why|what|joke|haha|lol|wow)\b/i', $text);
	}

	private function extract_location_candidate(string $text): string {
		$text = trim($text);
		$patterns = [
			'/\bwould\s+(?:love|like)\s+to\s+be\s+picked\s+(?:up\s+)?(?:at|from|in|on)\s+(.+)$/i',
			'/\b(?:pick(?:\s*me)?\s*up|pickup|collect\s+me)\s+(?:at|from|in|on)\s+(.+)$/i',
			'/\b(?:picked\s+up|be\s+picked\s+up|be\s+picked)\s+(?:at|from|in|on)\s+(.+)$/i',
			'/\b(?:want\s+to\s+be\s+picked|want\s+to\s+be\s+picked\s+up)\s+(?:at|from|in|on)\s+(.+)$/i',
			'/\b(?:you\s+can\s+pick\s+me\s+up|can\s+pick\s+me\s+up)\s+(?:at|from|in|on)\s+(.+)$/i',
			'/\b(?:i\s+am|i\'m|im)\s+(?:at|in)\s+(.+)$/i',
			'/\b(?:drop\s*(?:me)?\s*off|destination\s+is|going\s+to|take\s+me\s+to|drive\s+me\s+to)\s+(?:at|in)?\s*(.+)$/i',
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $text, $matches)) {
				$text = (string) $matches[1];
				break;
			}
		}

		$text = preg_replace('/^(hey|hello|hi|yo|please|ok|okay|so|well)[,\s]+/i', '', (string) $text);
		$text = preg_replace('/^(i\s+actually\s+want\s+to\s+be\s+picked\s+(?:up\s+)?(?:at|from|in)|i\s+want\s+to\s+be\s+picked\s+(?:up\s+)?(?:at|from|in)|be\s+picked\s+(?:up\s+)?(?:at|from|in))\s+/i', '', (string) $text);
		$text = preg_replace('/\s+(please|thanks|thank you|haha|lol)$/i', '', (string) $text);

		return trim((string) $text, " \t\n\r\0\x0B.,?!");
	}

	private function is_broad_location_request(string $text): bool {
		return '' !== $this->broad_location_name($text);
	}

	private function broad_location_name(string $text): string {
		$candidate = strtolower($this->extract_location_candidate($text));
		$candidate = preg_replace('/\s+(city|stad|gemeente|centrum|center|centre)$/i', '', (string) $candidate);
		$candidate = trim((string) $candidate);

		if ('' === $candidate) {
			return '';
		}

		// Localized shortcuts for common service-area cities.
		$map = [
			'antwerp' => 'Antwerp',
			'antwerpen' => 'Antwerp',
			'brussels' => 'Brussels',
			'brussel' => 'Brussels',
			'zaventem' => 'Zaventem',
			'mechelen' => 'Mechelen',
			'gent' => 'Gent',
			'ghent' => 'Gent',
			'bruges' => 'Bruges',
			'brugge' => 'Bruges',
			'leuven' => 'Leuven',
		];

		if (isset($map[$candidate])) {
			return $map[$candidate];
		}

		// Heuristic fallback: short, letters-only phrase with no street keyword or number → treat as a broad city/region.
		if (preg_match('/\d/', $candidate)) {
			return '';
		}
		if (preg_match('/\b(street|st|avenue|ave|boulevard|blvd|road|rd|lane|ln|drive|dr|way|straat|laan|weg|station|airport|hotel|terminal|square|place|plein|gare)\b/i', $candidate)) {
			return '';
		}
		if (!preg_match('/^[a-z][a-z\s\-\']{1,49}$/i', $candidate)) {
			return '';
		}
		$word_count = count(preg_split('/\s+/', $candidate));
		if ($word_count < 1 || $word_count > 4) {
			return '';
		}

		return ucwords($candidate);
	}

	/**
	 * Returns false when an AI-extracted string is clearly NOT a place name —
	 * e.g. a sentence fragment, question, or verb phrase.
	 */
	private function looks_like_real_place(string $text): bool {
		if (strlen($text) < 2 || strlen($text) > 200) {
			return false;
		}

		// Contains common verbs/question words that would never appear in a real address
		if (preg_match('/\b(talk|speak|want|would|could|should|did|does|done|are|were|have|has|had|can|will|shall|may|might|must|need|tell|ask|give|get|go|come|call|help|contact|reach|find|know|think|feel|mean|said|say|before|after|please|sorry|thanks|thank|hello|hi|hey)\b/i', $text)) {
			// Forgive "before" / "after" only if there is a clear location signal too
			if (!$this->has_location_signal($text)) {
				return false;
			}
		}

		// Starts with a question word
		if (preg_match('/^\s*(what|where|who|why|how|which|when|is|are|do|does|did|can|could|would|should|may|might)\b/i', $text)) {
			return false;
		}

		return true;
	}

	private function is_broad_prediction(string $text, mixed $prediction): bool {
		if (!is_array($prediction)) {
			return $this->is_broad_location_request($text);
		}

		$types = array_map('strval', (array) ($prediction['types'] ?? []));
		if (array_intersect($types, ['locality', 'political', 'administrative_area_level_1', 'country']) && !$this->has_specific_location_detail($text)) {
			return true;
		}

		return false;
	}

	private function has_specific_location_detail(string $text): bool {
		return 1 === preg_match('/\d{1,5}|\b(station|airport|hotel|terminal|straat|street|laan|avenue|road|square|place|centraal|central)\b/i', $text);
	}

	private function detect_language(string $message, array $session): string {
		$existing = (string) ($session['collected_data']['language'] ?? '');
		if ($existing && $this->is_short_operational_reply($message)) {
			return $existing;
		}

		if ($existing && !preg_match('/\b(parlez|bonjour|merci|francais|frans|nederlands|dutch|english|engels|spreek|speak)\b/i', $message)) {
			return $existing;
		}

		if (preg_match('/\b(oui|merci|bonjour|daccord|d\'accord|s\'il vous plait|svp)\b/i', $message)) {
			return 'fr';
		}

		if (preg_match('/\b(ja|dank je|dankjewel|alsjeblieft|graag|zeker|goedemorgen|goedenavond|alles goed|spreek jij nederlands|nederlands|hoe gaat|morgen|maak niet uit|geen idee|hoe heet jij)\b/i', $message)) {
			return 'nl';
		}

		return $existing ?: 'en';
	}

	private function is_short_operational_reply(string $message): bool {
		return 1 === preg_match('/^\s*(yes|yeah|yep|yup|oui|ja|ok|okay|sure|done|finished|new booking|tomorrow|today|morgen|vandaag|[\+\d\s\-\(\)]{5,})\s*[\.\?!]*\s*$/i', $message);
	}

	private function looks_like_name_for_state(string $message, string $state): bool {
		if ('capture_name' !== $state) {
			return false;
		}

		return $this->valid_customer_name($message) && 0 === preg_match('/\d|@|https?:\/\//i', $message);
	}

	private function should_repair(array $session, array $turn): bool {
		$state = (string) ($session['state'] ?? '');
		if (!in_array($state, ['capture_pickup', 'capture_dropoff', 'greeting'], true)) {
			return false;
		}

		if ('unknown' !== ($turn['intent'] ?? 'unknown')) {
			return false;
		}

		return (int) ($session['collected_data']['uncertain_count'] ?? 0) >= 1;
	}

	private function increment_uncertainty(array $session): array {
		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$data['uncertain_count'] = (int) ($data['uncertain_count'] ?? 0) + 1;
		$session['collected_data'] = $data;
		return $session;
	}

	private function clear_uncertainty(array $session): array {
		if (isset($session['collected_data']['uncertain_count'])) {
			unset($session['collected_data']['uncertain_count']);
		}

		return $session;
	}

	private function repair_message(array $session): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('nl' === $lang) {
			return __('Ik begrijp je misschien verkeerd. Geef je een ophaalplaats, stel je een vraag, of wil je de boeking wijzigen?', 'ridefleet-ai-chatbot');
		}

		if ('fr' === $lang) {
			return __('Je vous comprends peut-etre mal. Donnez-vous un lieu de prise en charge, posez-vous une question, ou voulez-vous modifier la reservation ?', 'ridefleet-ai-chatbot');
		}

		return __('I may be misunderstanding. Are you giving me a pickup place, asking a question, or changing the booking?', 'ridefleet-ai-chatbot');
	}

	private function say(array $session, string $key): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		$lines = [
			'en' => [
				'ask_pickup'               => 'Where should we pick you up? Type your address and pick from the suggestions for an exact match.',
				'ask_dropoff'              => 'Thanks. Where should we drop you off? Type and pick from the suggestions for the best result.',
				'ask_confirm'              => 'Would you like me to reserve this ride at the quoted price?',
				'ask_passengers'           => 'How many passengers will ride?',
				'ask_passengers_and_luggage' => 'How many passengers (including yourself), and any large bags? E.g. "2 passengers, 1 bag".',
				'ask_luggage'              => 'How many luggage items should we plan for? Enter 0 if none.',
				'ask_flight_number'        => 'What\'s your flight number? We use it so your driver can track the flight and wait if it\'s delayed. Type "skip" if you don\'t have it yet.',
				'ask_vehicle'              => 'Which vehicle would you like?',
				'ask_extras'               => 'Would you like to add any extras? Reply with numbers/names, or say none.',
				'ask_name'                 => 'Great. What name should we put on the booking?',
				'ask_phone'                => 'What phone number should dispatch use for this ride?',
				'ask_time'                 => 'What pickup date and time would you like?',
				'new_booking'              => 'Absolutely. Let us book a new ride. Where should we pick you up?',
				'cancelled'                => 'No problem, I cancelled this booking flow. Start a new chat whenever you want to check another ride.',
				'done_confirmed'           => 'Yes, you are done. Your booking is confirmed. I can close the chat, start a new booking, or send an edit request to dispatch.',
				'done_options'             => 'You are all set for the last ride. I can close the chat, start a new booking, or help request an edit.',
				'end_graceful'             => 'You are welcome. Have a nice day too. Your booking is confirmed, and I can close the chat now if you like.',
			],
			'fr' => [
				'ask_pickup'               => 'Ou devons-nous venir vous chercher ? Tapez votre adresse et choisissez dans les suggestions pour un resultat precis.',
				'ask_dropoff'              => 'Merci. Ou devons-nous vous deposer ? Tapez et choisissez dans les suggestions pour le meilleur resultat.',
				'ask_confirm'              => 'Voulez-vous reserver cette course au prix indique ?',
				'ask_passengers'           => 'Combien de passagers participeront a la course ?',
				'ask_passengers_and_luggage' => 'Combien de passagers (vous inclus) et avez-vous des bagages encombrants ? Ex. : "2 passagers, 1 valise".',
				'ask_luggage'              => 'Combien de bagages devons-nous prevoir ? Entrez 0 si aucun.',
				'ask_flight_number'        => 'Quel est votre numero de vol ? Cela permet a votre chauffeur de suivre le vol et d\'attendre en cas de retard. Tapez "passer" si vous ne l\'avez pas encore.',
				'ask_vehicle'              => 'Quel vehicule souhaitez-vous ?',
				'ask_extras'               => 'Voulez-vous ajouter des extras ? Repondez avec les numeros/noms, ou dites aucun.',
				'ask_name'                 => 'Tres bien. Quel nom devons-nous mettre sur la reservation ?',
				'ask_phone'                => 'Quel numero de telephone le dispatch peut-il utiliser ?',
				'ask_time'                 => 'Quelle date et heure de prise en charge souhaitez-vous ?',
				'new_booking'              => 'Bien sur. Commencons une nouvelle reservation. Ou devons-nous venir vous chercher ?',
				'cancelled'                => 'Pas de probleme, j ai annule ce flux de reservation.',
				'done_confirmed'           => 'Oui, c est termine. Votre reservation est confirmee. Je peux fermer le chat, commencer une nouvelle reservation, ou envoyer une demande de modification.',
				'done_options'             => 'Votre derniere course est confirmee. Je peux fermer le chat, commencer une nouvelle reservation, ou demander une modification.',
				'end_graceful'             => 'Avec plaisir. Bonne journee. Votre reservation est confirmee, et je peux fermer le chat si vous le souhaitez.',
			],
			'nl' => [
				'ask_pickup'               => 'Waar mogen we u ophalen? Typ uw adres en kies uit de suggesties voor een exacte locatie.',
				'ask_dropoff'              => 'Dank u. Waar mogen we u afzetten? Typ en selecteer uit de suggesties voor het beste resultaat.',
				'ask_confirm'              => 'Wilt u deze rit reserveren voor de opgegeven prijs?',
				'ask_passengers'           => 'Met hoeveel passagiers reist u?',
				'ask_passengers_and_luggage' => 'Met hoeveel passagiers reist u (uzelf inbegrepen), en heeft u grote bagage? Bijv. "2 passagiers, 1 koffer".',
				'ask_luggage'              => 'Hoeveel bagage moeten we voorzien? Vul 0 in als er geen bagage is.',
				'ask_flight_number'        => 'Wat is uw vluchtnummer? Zo kan uw chauffeur de vlucht volgen en wachten bij vertraging. Typ "skip" als u het nog niet weet.',
				'ask_vehicle'              => 'Welk voertuig wilt u?',
				'ask_extras'               => 'Wilt u extra opties toevoegen? Antwoord met nummers/namen, of zeg geen.',
				'ask_name'                 => 'Prima. Op welke naam mogen we de boeking zetten?',
				'ask_phone'                => 'Welk telefoonnummer mag dispatch gebruiken voor deze rit?',
				'ask_time'                 => 'Welke ophaaldatum en tijd wilt u?',
				'new_booking'              => 'Zeker. Laten we een nieuwe rit boeken. Waar mogen we u ophalen?',
				'cancelled'                => 'Geen probleem, ik heb deze boekingsflow geannuleerd.',
				'done_confirmed'           => 'Ja, u bent klaar. Uw boeking is bevestigd. Ik kan de chat sluiten, een nieuwe boeking starten, of een wijziging aanvragen.',
				'done_options'             => 'Uw laatste rit is bevestigd. Ik kan de chat sluiten, een nieuwe boeking starten, of een wijziging aanvragen.',
				'end_graceful'             => 'Graag gedaan. Nog een fijne dag. Uw boeking is bevestigd, en ik kan de chat nu sluiten als u wilt.',
			],
		];

		return $lines[$lang][$key] ?? $lines['en'][$key] ?? '';
	}

	private function city_confirmation_prompt(array $session, string $kind, string $city): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('nl' === $lang) {
			$label = 'pickup' === $kind ? 'ophalen in' : 'afzetten in';
			return sprintf(__('Wil je algemeen %1$s %2$s stad gebruiken? Antwoord ja, of stuur een exact adres, station, hotel of herkenningspunt.', 'ridefleet-ai-chatbot'), $label, $city);
		}

		if ('fr' === $lang) {
			$label = 'pickup' === $kind ? 'prise en charge a' : 'depose a';
			return sprintf(__('Voulez-vous utiliser %1$s %2$s en general ? Repondez oui, ou envoyez une adresse exacte, une gare, un hotel ou un point de repere.', 'ridefleet-ai-chatbot'), $label, $city);
		}

		$label = 'pickup' === $kind ? 'pickup in' : 'drop-off in';
		return sprintf(__('Do you want %1$s %2$s city generally? Reply yes to use the city, or send the exact street and house number / station / landmark.', 'ridefleet-ai-chatbot'), $label, $city);
	}

	private function admin_summary(string $message, string $language, string $role = 'customer', array $turn = []): string {
		if ('assistant' === $role && !empty($turn['admin_summary_en'])) {
			return (string) $turn['admin_summary_en'];
		}

		if ('customer' === $role && !empty($turn['intent'])) {
			$fields = !empty($turn['fields']) ? ' Fields: ' . wp_json_encode($turn['fields']) : '';
			return sprintf('Customer intent: %s. Language: %s.%s Original: %s', (string) $turn['intent'], strtoupper($language), $fields, $message);
		}

		if ('en' === $language) {
			return $message;
		}

		if ('assistant' === $role) {
			return sprintf('Assistant replied in %s: %s', strtoupper($language), $message);
		}

		if ($this->is_affirmative($message)) {
			return sprintf('Customer confirmed yes. Original %s: %s', strtoupper($language), $message);
		}

		if ($this->is_gratitude_or_goodbye($message)) {
			return sprintf('Customer thanked or ended the chat. Original %s: %s', strtoupper($language), $message);
		}

		return sprintf('Customer message in %s: %s', strtoupper($language), $message);
	}

	private function public_data(array $session): array {
		return [
			'collected' => $session['collected_data'],
			'quote' => [
				'final_price' => $session['last_quote']['final_price'] ?? null,
				'base_price' => $session['last_quote']['base_price'] ?? null,
				'currency' => $session['last_quote']['currency'] ?? null,
				'zone_name' => $session['last_quote']['zone_name'] ?? null,
				'addons' => $session['last_quote']['addons'] ?? [],
				'requires_approval' => !empty($session['last_quote']['requires_approval']),
				'service_area' => $session['last_quote']['service_area'] ?? null,
				'coupon' => $session['last_quote']['coupon'] ?? null,
				'pricing_type' => $session['last_quote']['pricing_type'] ?? null,
				'distance_km' => $session['last_quote']['distance_km'] ?? null,
				'duration_minutes' => $session['last_quote']['duration_minutes'] ?? null,
			],
			'booking' => [
				'id' => $session['collected_data']['last_booking_id'] ?? null,
				'number' => $session['collected_data']['last_booking_number'] ?? null,
				'changeRequestId' => $session['collected_data']['last_change_request_id'] ?? null,
				'via_stop' => $session['collected_data']['via_stop'] ?? null,
			],
			'payment_url' => (string) ($session['collected_data']['payment_url'] ?? ''),
			'ui_action' => $session['ui_action'] ?? null,
			'location_candidates' => array_values(array_unique(array_merge(
				(array) ($session['collected_data']['pickup_candidates'] ?? []),
				(array) ($session['collected_data']['dropoff_candidates'] ?? [])
			))),
			'popular_destinations' => in_array((string) ($session['state'] ?? ''), ['capture_pickup', 'capture_dropoff'], true) ? (array) ($session['ui_popular_destinations'] ?? []) : [],
			'extras_list' => 'capture_extras' === (string) ($session['state'] ?? '') ? (array) ($session['ui_extras_list'] ?? []) : [],
			'skip_button' => 'capture_flight_number' === (string) ($session['state'] ?? '') ? (string) ($session['ui_skip_button'] ?? '') : '',
			'cancellation_page_url' => 'complete' === (string) ($session['state'] ?? '') ? $this->cancellation_page_url() : '',
			'terms_url'             => (string) \RideFleetAIChatbot\Support\Options::get('terms_url', ''),
			'availability'          => $this->calendar_availability_data($session),
		];
	}

	/**
	 * Returns availability settings for the chatbot calendar.
	 * Reads exclusively from the booking plugin's AvailabilityService — no duplicate config.
	 * Only populated when state = capture_pickup_time.
	 */
	private function calendar_availability_data(array $session): array {
		if ('capture_pickup_time' !== (string) ($session['state'] ?? '')) {
			return [];
		}
		if (class_exists('\\RideFleetBooking\\Booking\\AvailabilityService')) {
			$settings = \RideFleetBooking\Booking\AvailabilityService::get_settings();
			$blocked  = \RideFleetBooking\Booking\AvailabilityService::blocked_date_ranges();
			return array_merge($settings, ['blocked_ranges' => $blocked]);
		}
		// Standalone fallback — no constraints, calendar shows everything.
		return [
			'min_advance_hours'      => 2,
			'max_booking_days'       => 90,
			'business_hours_enabled' => false,
			'business_hours'         => [],
			'blocked_ranges'         => [],
		];
	}

	/**
	 * Returns the cancellation policy page URL if the booking plugin is co-located and policy is enabled.
	 */
	private function cancellation_page_url(): string {
		if (class_exists('\\RideFleetBooking\\Admin\\CancellationPageGenerator')) {
			return \RideFleetBooking\Admin\CancellationPageGenerator::page_url();
		}

		return '';
	}

	/**
	 * Returns all published rfb_extra items, keyed by id.
	 */
	private function get_available_extras(): array {
		if (!post_type_exists('rfb_extra')) {
			return [];
		}

		$query = new \WP_Query([
			'post_type'      => 'rfb_extra',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		]);

		$list = [];
		foreach ($query->posts as $post) {
			$price = round((float) get_post_meta($post->ID, 'rfb_price', true), 2);
			$list[] = [
				'id'    => $post->ID,
				'name'  => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
				'price' => $price,
			];
		}
		return $list;
	}

	private function has_available_extras(): bool {
		if (!post_type_exists('rfb_extra')) {
			return false;
		}
		$q = new \WP_Query([
			'post_type'      => 'rfb_extra',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => false,
			'fields'         => 'ids',
		]);
		return $q->found_posts > 0;
	}
}
