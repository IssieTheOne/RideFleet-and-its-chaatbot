<?php
/**
 * Deterministic booking funnel with AI-assisted wording.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

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

	public function handle(string $session_key, string $message, string $client_locale = ''): array {
		$session = $this->sessions->get_or_create($session_key);
		$message = trim(wp_strip_all_tags($message));

		if ('' === (string) ($session['collected_data']['language'] ?? '') && '' !== $client_locale) {
			$seed = strtolower(substr($client_locale, 0, 2));
			if (in_array($seed, ['en', 'fr', 'nl'], true)) {
				$session['collected_data']['language'] = $seed;
			}
		}

		$turn = $this->classify_turn($message, $session);
		$turn = $this->state_scoped_turn($turn, (string) ($session['state'] ?? 'greeting'));
		$this->current_turn = $turn;
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

		if ('change_pending' === $session['state'] && $this->is_acknowledgement($message)) {
			$response = $this->reply($session, __('Yes. Your change request is pending admin approval, and the original booking stays active until dispatch confirms the change.', 'ridefleet-ai-chatbot'));
			return $this->commit_response($response);
		}

		if ('price_negotiation_pending' === $session['state'] && $this->is_acknowledgement($message)) {
			$response = $this->reply($session, __('Yes. Your proposed fare is waiting for dispatch approval. This is not a confirmed booking yet; the taxi team must approve it and contact you first.', 'ridefleet-ai-chatbot'));
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

		if ('complete' === $session['state'] && $this->asks_for_new_booking($message)) {
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			$session['state'] = 'capture_pickup';
			$session['collected_data'] = ['language' => $lang];
			$session['last_quote'] = [];
			$response = $this->reply($session, $this->say($session, 'new_booking'));
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

		if ($this->is_reset($message) || $this->asks_for_new_booking($message)) {
			$lang = (string) ($session['collected_data']['language'] ?? 'en');
			$session['state'] = 'capture_pickup';
			$session['collected_data'] = ['language' => $lang];
			$session['last_quote'] = [];
			$response = $this->reply($session, $this->say($session, 'new_booking'));
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

		$session = $this->capture_for_state($session, $message, $turn);
		if (!empty($session['validation_error']) && 'unknown' === (string) ($turn['intent'] ?? 'unknown')) {
			$session = $this->increment_uncertainty($session);
		}
		$response = $this->next_action($session, $message);

		return $this->commit_response($response);
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
			$data['pickup_address'] = $extracted['pickup_address'];
			$data['dropoff_address'] = $extracted['dropoff_address'];
			$session['state'] = 'quote_requested';
			$session['collected_data'] = $data;
			return $session;
		}

		if ('confirm_pickup_city' === $session['state']) {
			if ($this->is_affirmative($message) || $this->is_city_level_confirmation($message)) {
				$data['pickup_address'] = sanitize_text_field((string) ($data['pending_pickup_city'] ?? ''));
				unset($data['pending_pickup_city']);
				$session['state'] = 'capture_dropoff';
			} elseif ($this->is_negative($message)) {
				unset($data['pending_pickup_city']);
				$session['state'] = 'capture_pickup';
				$session['validation_error'] = __('No problem. Please send the exact pickup street and house number, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
			} else {
				$place = $this->resolve_place_text($message);
				if ($place) {
					$data['pickup_address'] = $place;
					unset($data['pending_pickup_city']);
					$session['state'] = 'capture_dropoff';
				} else {
					$session['state'] = 'confirm_pickup_city';
					$session['validation_error'] = __('Please reply yes to use the city, or send a more exact pickup street, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
				}
			}
		} elseif ('confirm_dropoff_city' === $session['state']) {
			if ($this->is_affirmative($message) || $this->is_city_level_confirmation($message)) {
				$data['dropoff_address'] = sanitize_text_field((string) ($data['pending_dropoff_city'] ?? ''));
				unset($data['pending_dropoff_city']);
				$session['state'] = 'quote_requested';
			} elseif ($this->is_negative($message)) {
				unset($data['pending_dropoff_city']);
				$session['state'] = 'capture_dropoff';
				$session['validation_error'] = __('No problem. Please send the exact drop-off street and house number, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
			} else {
				$place = $this->resolve_place_text($message);
				if ($place) {
					$data['dropoff_address'] = $place;
					unset($data['pending_dropoff_city']);
					$session['state'] = 'quote_requested';
				} else {
					$session['state'] = 'confirm_dropoff_city';
					$session['validation_error'] = __('Please reply yes to use the city, or send a more exact drop-off street, station, hotel, or landmark.', 'ridefleet-ai-chatbot');
				}
			}
		} elseif ('greeting' === $session['state']) {
			if ($this->is_broad_location_request($message)) {
				$data['pending_pickup_city'] = $this->broad_location_name($message);
				$session['state'] = 'confirm_pickup_city';
			} else {
			$place = $this->resolve_place_text($message);
			if (!$place) {
				$session['state'] = 'capture_pickup';
					$session['validation_error'] = __('I need a real pickup place. Send a street + house number, a station, an airport, a hotel, or a city name.', 'ridefleet-ai-chatbot');
			} else {
				$data['pickup_address'] = $place;
				$session['state'] = 'capture_dropoff';
			}
			}
		} elseif ('capture_pickup' === $session['state']) {
			if ($this->is_broad_location_request($message)) {
				$data['pending_pickup_city'] = $this->broad_location_name($message);
				$session['state'] = 'confirm_pickup_city';
			} else {
			$place = $this->resolve_place_text($message);
			if (!$place) {
				$session['state'] = 'capture_pickup';
					$session['validation_error'] = __('I could not recognize that as a pickup location. Please send a clearer place, address, station, airport, or hotel name.', 'ridefleet-ai-chatbot');
			} else {
				$data['pickup_address'] = $place;
				$session['state'] = 'capture_dropoff';
			}
			}
		} elseif ('capture_dropoff' === $session['state']) {
			if ($this->is_broad_location_request($message)) {
				$data['pending_dropoff_city'] = $this->broad_location_name($message);
				$session['state'] = 'confirm_dropoff_city';
			} else {
			$place = $this->resolve_place_text($message);
			if (!$place) {
				$session['state'] = 'capture_dropoff';
					$session['validation_error'] = __('I could not recognize that as a drop-off location. Please send a clearer place, address, station, airport, or hotel name.', 'ridefleet-ai-chatbot');
			} else {
				$data['dropoff_address'] = $place;
				$session['state'] = 'quote_requested';
			}
			}
		} elseif ('confirm_price' === $session['state'] && $this->is_affirmative($message)) {
			$session['state'] = 'capture_passengers';
		} elseif ('confirm_price' === $session['state'] && $this->is_negative($message)) {
			$session['state'] = 'confirm_price';
		} elseif ('capture_price_offer' === $session['state']) {
			$offer = $this->extract_price_offer($message);
			$offer_check = $this->validate_price_offer($session, $offer);
			if (!$offer_check['accepted']) {
				$session['state'] = 'confirm_price';
				$session['validation_error'] = $offer_check['message'];
			} else {
				$data['proposed_price'] = $offer;
				$session['state'] = 'capture_negotiation_name';
			}
		} elseif ('capture_negotiation_name' === $session['state']) {
			if (!$this->valid_customer_name($message)) {
				$session['state'] = 'capture_negotiation_name';
				$session['validation_error'] = __('Please send the customer name so dispatch knows who made the fare request.', 'ridefleet-ai-chatbot');
			} else {
				$data['customer_name'] = sanitize_text_field($message);
				$session['state'] = 'capture_negotiation_phone';
			}
		} elseif ('capture_negotiation_phone' === $session['state']) {
			if (!$this->valid_phone($message)) {
				$session['state'] = 'capture_negotiation_phone';
				$session['validation_error'] = __('Please enter a real phone number dispatch can call or text about this fare request.', 'ridefleet-ai-chatbot');
			} else {
				$data['customer_phone'] = sanitize_text_field($message);
				$session['phone_note'] = $this->phone_note($message);
				$session['state'] = 'capture_negotiation_time';
			}
		} elseif ('capture_negotiation_time' === $session['state']) {
			$pickup_time = $this->normalize_pickup_time($message);
			if (!$pickup_time) {
				$session['state'] = 'capture_negotiation_time';
				$session['validation_error'] = __('Please enter a valid future pickup date and time, for example "tomorrow 09:00" or "2026-05-13 09:00".', 'ridefleet-ai-chatbot');
			} else {
				$data['pickup_time'] = $pickup_time;
				$request_id = $this->sessions->log_price_negotiation_request((int) $session['id'], ['collected_data' => $data, 'last_quote' => $session['last_quote']]);
				$data['last_change_request_id'] = $request_id;
				$session['state'] = 'price_negotiation_pending';
			}
		} elseif ('capture_passengers' === $session['state']) {
			$count = $this->extract_count($message);
			if ($count < 1 || $count > 16) {
				$session['state'] = 'capture_passengers';
				$session['validation_error'] = __('How many passengers will ride? Please enter a number from 1 to 16.', 'ridefleet-ai-chatbot');
			} else {
				$data['passengers'] = $count;
				$session['state'] = 'capture_luggage';
			}
		} elseif ('capture_luggage' === $session['state']) {
			$count = $this->extract_count($message);
			if ($count < 0 || $count > 30) {
				$session['state'] = 'capture_luggage';
				$session['validation_error'] = __('How many luggage items should we plan for? Enter 0 if there are none.', 'ridefleet-ai-chatbot');
			} else {
				$data['luggage'] = $count;
				$vehicles = $this->core->get_vehicles((int) ($data['passengers'] ?? 1), $count);
				$data['available_vehicles'] = $vehicles['vehicles'] ?? [];
				$session['state'] = empty($data['available_vehicles']) ? 'vehicle_unavailable' : 'capture_vehicle';
				if (empty($data['available_vehicles'])) {
					$data['vehicle_id'] = 0;
					$data['vehicle_name'] = '';
				}
			}
		} elseif ('capture_vehicle' === $session['state']) {
			$vehicle = $this->select_vehicle($message, $data['available_vehicles'] ?? []);
			if (!$vehicle) {
				$session['state'] = 'capture_vehicle';
				$session['validation_error'] = __('Please choose one of the listed vehicles by number or name.', 'ridefleet-ai-chatbot');
			} else {
				$data['vehicle_id'] = (int) ($vehicle['id'] ?? 0);
				$data['vehicle_name'] = sanitize_text_field((string) ($vehicle['name'] ?? ''));
				$extras = $this->core->get_extras();
				$data['available_extras'] = $extras['extras'] ?? [];
				if (empty($data['available_extras'])) {
					$data['extras'] = [];
					$data['no_extras_available'] = true;
					$session['state'] = 'quote_refresh_requested';
				} else {
					unset($data['no_extras_available']);
					$session['state'] = 'capture_extras';
				}
			}
		} elseif ('capture_extras' === $session['state']) {
			$data['extras'] = $this->select_extras($message, $data['available_extras'] ?? []);
			$session['state'] = 'quote_refresh_requested';
		} elseif ('capture_name' === $session['state']) {
			if (!$this->valid_customer_name($message)) {
				$session['state'] = 'capture_name';
				$session['validation_error'] = __('Please send the customer name for the booking.', 'ridefleet-ai-chatbot');
			} else {
				$data['customer_name'] = sanitize_text_field($message);
				$session['state'] = 'capture_phone';
			}
		} elseif ('capture_phone' === $session['state']) {
			if (!$this->valid_phone($message)) {
				$session['state'] = 'capture_phone';
				$session['validation_error'] = __('Please enter a real phone number dispatch can call or text.', 'ridefleet-ai-chatbot');
			} else {
				$data['customer_phone'] = sanitize_text_field($message);
				$session['phone_note'] = $this->phone_note($message);
				$session['state'] = 'capture_pickup_time';
			}
		} elseif ('capture_pickup_time' === $session['state']) {
			$pickup_time = $this->normalize_pickup_time($message);
			if (!$pickup_time) {
				$session['state'] = 'capture_pickup_time';
				$session['validation_error'] = __('Please enter a valid future pickup date and time, for example "tomorrow 09:00" or "2026-05-13 09:00".', 'ridefleet-ai-chatbot');
			} else {
				$data['pickup_time'] = $pickup_time;
				$session['state'] = 'booking_requested';
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
			case 'capture_pickup':
				return $this->reply($session, $this->say($session, 'ask_pickup'));

			case 'capture_dropoff':
				return $this->reply($session, $this->say($session, 'ask_dropoff'));

			case 'confirm_pickup_city':
				return $this->reply($session, $this->city_confirmation_prompt($session, 'pickup', (string) ($data['pending_pickup_city'] ?? __('that city', 'ridefleet-ai-chatbot'))));

			case 'confirm_dropoff_city':
				return $this->reply($session, $this->city_confirmation_prompt($session, 'dropoff', (string) ($data['pending_dropoff_city'] ?? __('that city', 'ridefleet-ai-chatbot'))));

			case 'quote_requested':
				return $this->quote_trip($session);

			case 'confirm_price':
				return $this->reply($session, $this->say($session, 'ask_confirm'));

			case 'capture_passengers':
				return $this->reply($session, $this->say($session, 'ask_passengers'));

			case 'capture_luggage':
				return $this->reply($session, $this->say($session, 'ask_luggage'));

			case 'capture_vehicle':
				return $this->reply($session, $this->vehicle_prompt($session));

			case 'vehicle_unavailable':
				return $this->reply($session, $this->vehicle_unavailable_message($session));

			case 'capture_extras':
				return $this->reply($session, $this->extras_prompt($session));

			case 'quote_refresh_requested':
				return $this->quote_trip($session, true);

			case 'capture_price_offer':
				return $this->reply($session, __('What fare would you like to propose? I can send a reasonable offer to dispatch for human approval, but I cannot change the verified fare myself.', 'ridefleet-ai-chatbot'));

			case 'capture_negotiation_name':
				return $this->reply($session, __('I can send that reasonable fare request to dispatch. What name should dispatch use for this request?', 'ridefleet-ai-chatbot'));

			case 'capture_negotiation_phone':
				return $this->reply($session, __('What phone number should dispatch use if they approve or reject the proposed fare?', 'ridefleet-ai-chatbot'));

			case 'capture_negotiation_time':
				$note = (string) ($session['phone_note'] ?? '');
				unset($session['phone_note']);
				return $this->reply($session, trim($note . ' ' . __('What pickup date and time should dispatch review for this proposed fare?', 'ridefleet-ai-chatbot')));

			case 'capture_name':
				return $this->reply($session, $this->say($session, 'ask_name'));

			case 'capture_phone':
				return $this->reply($session, $this->say($session, 'ask_phone'));

			case 'capture_pickup_time':
				$note = (string) ($session['phone_note'] ?? '');
				unset($session['phone_note']);
				return $this->reply($session, trim($note . ' ' . $this->say($session, 'ask_time')));

			case 'booking_requested':
				return $this->submit_booking($session);

			case 'change_pending':
				return $this->reply($session, __('I sent your change request to dispatch for admin approval. Your original booking is still active until dispatch confirms the change. If the route or time changes, the final price may also change and the taxi team will contact you.', 'ridefleet-ai-chatbot'));

			case 'price_negotiation_pending':
				return $this->reply($session, $this->price_negotiation_pending_message($session));
		}

		$session['state'] = 'capture_pickup';

		return $this->reply($session, __('I can help with taxi booking questions. Where should we pick you up?', 'ridefleet-ai-chatbot'));
	}

	private function quote_trip(array $session, bool $continue_booking = false): array {
		$data = $session['collected_data'];
		if (empty($data['pickup_address']) || empty($data['dropoff_address'])) {
			$session['state'] = empty($data['pickup_address']) ? 'capture_pickup' : 'capture_dropoff';
			return $this->next_action($session, '');
		}

		$quote = $this->core->get_core_trip_price((string) $data['pickup_address'], (string) $data['dropoff_address'], $data);
		if (empty($quote['success']) || !empty($quote['error'])) {
			$session['state'] = 'halted';
			$message = sanitize_textarea_field((string) ($quote['message'] ?? __('This trip is outside our service area.', 'ridefleet-ai-chatbot')));
			return $this->reply($session, $message);
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

		$session['last_quote'] = [
			'final_price' => round($price, 2),
			'base_price' => round((float) ($quote['base_price'] ?? $price), 2),
			'currency' => $currency,
			'zone_name' => $zone,
			'addons' => $quote['addons'] ?? [],
			'requires_approval' => $requires_approval,
			'service_area' => [
				'status' => sanitize_key((string) ($service_status['status'] ?? ($requires_approval ? 'approval_required' : 'inside'))),
				'pickup_allowed' => $pickup_allowed,
				'dropoff_allowed' => $dropoff_allowed,
				'message' => sanitize_text_field((string) ($quote['approval_message'] ?? $service_status['message'] ?? '')),
			],
			'raw' => $quote,
		];
		$session['state'] = $continue_booking ? 'capture_name' : 'confirm_price';

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
				$session['state'] = 'capture_' . str_replace('customer_', '', $key);
				return $this->reply($session, __('I am missing one booking detail. Please send it again.', 'ridefleet-ai-chatbot'));
			}
		}

		$payload = [
			'pickup_address' => (string) $data['pickup_address'],
			'dropoff_address' => (string) $data['dropoff_address'],
			'customer_name' => (string) $data['customer_name'],
			'customer_phone' => (string) $data['customer_phone'],
			'pickup_time' => (string) $data['pickup_time'],
			'final_price' => (float) ($quote['final_price'] ?? 0),
			'currency' => (string) ($quote['currency'] ?? 'USD'),
			'passengers' => max(1, absint($data['passengers'] ?? 1)),
			'luggage' => max(0, absint($data['luggage'] ?? 0)),
			'vehicle_id' => absint($data['vehicle_id'] ?? 0),
			'vehicle_name' => (string) ($data['vehicle_name'] ?? ''),
			'extras' => is_array($data['extras'] ?? null) ? $data['extras'] : [],
		];

		if (!empty($data['coupon_code'])) {
			$payload['coupon_code'] = sanitize_text_field((string) $data['coupon_code']);
		}

		$result = $this->core->submit_core_booking($payload);
		$this->sessions->log_booking((int) $session['id'], $payload, $result);

		if (empty($result['success']) || !empty($result['error'])) {
			$session['state'] = false !== stripos((string) ($result['message'] ?? ''), 'pickup time') ? 'capture_pickup_time' : 'confirm_price';
			return $this->reply($session, sanitize_textarea_field((string) ($result['message'] ?? __('Dispatch could not create the booking. Please try again.', 'ridefleet-ai-chatbot'))));
		}

		$booking_id = sanitize_text_field((string) ($result['booking_id'] ?? $result['bookingId'] ?? $result['id'] ?? ''));
		$data['last_booking_id'] = $booking_id;
		$session['collected_data'] = $data;
		$session['state'] = 'complete';

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
		$lang = (string) ($response['session']['collected_data']['language'] ?? 'en');
		$response['message'] = $this->ai->localize_reply((string) $response['message'], $lang, $response['session']);
		$this->sessions->update($response['session']);
		$this->sessions->add_message((int) $response['session']['id'], 'assistant', $response['message'], $this->admin_summary($response['message'], $lang, 'assistant', $this->current_turn), $this->message_meta($this->current_turn, $lang));
		return $this->format_response($response);
	}

	private function classify_turn(string $message, array $session): array {
		$local = $this->local_classify_turn($message, $session);
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

		if ($this->asks_language_switch($message)) {
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
		} elseif ($this->asks_price_negotiation($message)) {
			$intent = 'price_negotiation';
			$fields['proposed_price'] = $this->extract_price_offer($message) ?: null;
			$confidence = 0.9;
		} elseif ($this->normalize_pickup_time($message)) {
			$intent = 'time';
			$fields['pickup_time'] = $message;
			$confidence = 0.88;
		} elseif ($this->valid_phone($message)) {
			$intent = 'phone';
			$fields['phone'] = $message;
			$confidence = 0.92;
		} elseif ('capture_passengers' === (string) ($session['state'] ?? '') && $this->extract_count($message) > 0) {
			$intent = 'passenger_count';
			$fields['passenger_count'] = $this->extract_count($message);
			$confidence = 0.9;
		} elseif ('capture_luggage' === (string) ($session['state'] ?? '') && $this->extract_count($message) >= 0) {
			$intent = 'luggage_count';
			$fields['luggage_count'] = $this->extract_count($message);
			$confidence = 0.9;
		} elseif ('capture_extras' === (string) ($session['state'] ?? '')) {
			$intent = 'extras';
			$fields['extras'] = $message;
			$confidence = 0.84;
		} elseif ('capture_vehicle' === (string) ($session['state'] ?? '')) {
			$intent = 'vehicle_choice';
			$fields['vehicle_choice'] = $message;
			$confidence = 0.84;
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
		$allowed = ['smalltalk', 'location', 'confirmation', 'question', 'edit_request', 'cancel', 'price_negotiation', 'time', 'name', 'phone', 'passenger_count', 'luggage_count', 'extras', 'vehicle_choice', 'unknown'];
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
			'confirm_price', 'capture_price_offer' => ['proposed_price', 'language_target', 'booking_id', 'question_type'],
			'capture_passengers' => ['passenger_count', 'language_target', 'question_type'],
			'capture_luggage' => ['luggage_count', 'language_target', 'question_type'],
			'capture_vehicle' => ['vehicle_choice', 'language_target', 'question_type'],
			'capture_extras' => ['extras', 'language_target', 'question_type'],
			'capture_name', 'capture_negotiation_name' => ['name', 'language_target', 'question_type'],
			'capture_phone', 'capture_negotiation_phone' => ['phone', 'language_target', 'question_type'],
			'capture_pickup_time', 'capture_negotiation_time' => ['pickup_time', 'language_target', 'question_type'],
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
		$existing = (string) ($session['collected_data']['language'] ?? '');
		$remote = (string) ($turn['language'] ?? '');
		if ($this->asks_language_switch($message)) {
			return $this->requested_language($message) ?: ($existing ?: 'en');
		}

		if ($existing && !$this->asks_language_support($message)) {
			return $existing;
		}

		if (in_array($remote, ['en', 'fr', 'nl'], true) && 'unknown' !== $remote) {
			return $remote;
		}

		return $this->detect_language($message, $session);
	}

	private function handle_interruption(array $session, string $message): ?array {
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

		if ('confirm_price' === $session['state'] && $this->asks_price_negotiation($message)) {
			$offer = $this->extract_price_offer($message);
			if ($offer <= 0) {
				$session['state'] = 'capture_price_offer';
				return $this->reply($session, __('I can send a reasonable counteroffer to dispatch for human approval. What fare would you like to propose?', 'ridefleet-ai-chatbot'));
			}

			$offer_check = $this->validate_price_offer($session, $offer);
			if (!$offer_check['accepted']) {
				return $this->reply($session, $offer_check['message']);
			}

			$data = $session['collected_data'];
			$data['proposed_price'] = $offer;
			$session['collected_data'] = $data;
			$session['state'] = 'capture_negotiation_name';
			return $this->reply($session, $this->price_offer_intro_message($session));
		}

		if ('confirm_price' === $session['state'] && $this->is_confused($message)) {
			$quote = $session['last_quote'];
			$price = isset($quote['final_price']) ? sprintf('%s %.2f', (string) ($quote['currency'] ?? 'USD'), (float) $quote['final_price']) : __('the verified fare', 'ridefleet-ai-chatbot');
			return $this->reply($session, sprintf(__('That is the verified fare from RideFleet: %s. I cannot edit it here. Reply yes to book it, or say change route to check another trip.', 'ridefleet-ai-chatbot'), $price));
		}

		if ('confirm_price' === $session['state'] && $this->is_negative($message)) {
			return $this->reply($session, __('No worries. I will keep this quote here. Say yes to book it, change route to price a different trip, or cancel to end this booking.', 'ridefleet-ai-chatbot'));
		}

		if ($this->is_smalltalk($message)) {
			return $this->reply_with_next_step($session, $this->smalltalk_reply($message, $session));
		}

		if ('confirm_price' === $session['state'] && !$this->is_affirmative($message) && !$this->is_negative($message)) {
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

		if ('capture_passengers' === $session['state']) {
			return $this->say($session, 'ask_passengers');
		}

		if ('capture_luggage' === $session['state']) {
			return $this->say($session, 'ask_luggage');
		}

		if ('capture_vehicle' === $session['state']) {
			return $this->vehicle_prompt($session);
		}

		if ('capture_extras' === $session['state']) {
			return $this->extras_prompt($session);
		}

		if ('capture_phone' === $session['state']) {
			return $this->say($session, 'ask_phone');
		}

		if ('capture_pickup_time' === $session['state']) {
			return $this->say($session, 'ask_time');
		}

		if ('capture_price_offer' === $session['state']) {
			return __('What fare would you like to propose?', 'ridefleet-ai-chatbot');
		}

		if ('capture_negotiation_name' === $session['state']) {
			return __('What name should dispatch use for the fare request?', 'ridefleet-ai-chatbot');
		}

		if ('capture_negotiation_phone' === $session['state']) {
			return __('What phone number should dispatch use for the fare request?', 'ridefleet-ai-chatbot');
		}

		if ('capture_negotiation_time' === $session['state']) {
			return __('What pickup date and time should dispatch review?', 'ridefleet-ai-chatbot');
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
		return 1 === preg_match('/^\s*(yes|yeah|yep|yup|confirm|book|reserve|ok|okay|sure|lets do|let\'s do|do it|that one|proceed|go ahead|oui|ja|zeker|daccord|d\'accord)(?:\s+(?:yes|yeah|yep|yup|ok|okay|sure|oui|ja|zeker|daccord|d\'accord))*\s*[\.\?!]*\s*$/i', $message);
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
		$phone = trim((string) \RideFleetAIChatbot\Support\Options::get('dispatch_contact_number', ''));
		$contact = $phone ? sprintf(__(' Please contact dispatch at %s so a human can arrange a suitable vehicle.', 'ridefleet-ai-chatbot'), $phone) : __(' Please contact dispatch/admin so a human can arrange a suitable vehicle.', 'ridefleet-ai-chatbot');
		return sprintf(
			__('I can quote the ride, but I cannot finalize it online because no configured vehicle can carry %1$d passenger(s) and %2$d luggage item(s).%3$s', 'ridefleet-ai-chatbot'),
			max(1, (int) ($data['passengers'] ?? 1)),
			max(0, (int) ($data['luggage'] ?? 0)),
			$contact
		);
	}

	private function quote_message(array $session, string $pickup, string $dropoff, string $currency, float $price, string $zone_text): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('fr' === $lang) {
			return sprintf(__('C est note : %1$s vers %2$s. Le prix indicatif verifie est %3$s %4$.2f%5$s. Ce n est pas final tant que les options et le dispatch ne confirment pas. Voulez-vous continuer ?', 'ridefleet-ai-chatbot'), $pickup, $dropoff, $currency, $price, $zone_text);
		}
		if ('nl' === $lang) {
			return sprintf(__('Genoteerd: %1$s naar %2$s. De gecontroleerde richtprijs is %3$s %4$.2f%5$s. Dit is pas definitief na opties en dispatchbevestiging. Wilt u doorgaan?', 'ridefleet-ai-chatbot'), $pickup, $dropoff, $currency, $price, $zone_text);
		}
		return sprintf(__('Got it: %1$s to %2$s. Your verified quote is %3$s %4$.2f%5$s. This is not final until ride options and dispatch confirmation are complete. Would you like to continue?', 'ridefleet-ai-chatbot'), $pickup, $dropoff, $currency, $price, $zone_text);
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

	private function booking_confirmed_message(array $session, string $booking_id): string {
		$lang = (string) ($session['collected_data']['language'] ?? 'en');
		if ('fr' === $lang) {
			return sprintf(__('Votre demande de course est recue. Numero de reservation : %s. Le prix reste un devis jusqu a la confirmation du dispatch. Besoin d une modification plus tard ? Demandez une modification et le dispatch la verifiera.', 'ridefleet-ai-chatbot'), $booking_id);
		}
		if ('nl' === $lang) {
			return sprintf(__('Uw ritaanvraag is ontvangen. Boekingsnummer: %s. De prijs blijft een offerte tot dispatch bevestigt. Later iets wijzigen? Vraag een wijziging aan en dispatch controleert die.', 'ridefleet-ai-chatbot'), $booking_id);
		}
		return sprintf(__('Your ride request is received. Booking ID: %s. The fare is still a quote until dispatch confirms it. Need a change later? Ask for an edit and dispatch will review it.', 'ridefleet-ai-chatbot'), $booking_id);
	}

	private function is_city_level_confirmation(string $message): bool {
		return 1 === preg_match('/^\s*(city|stad|centrum|center|centre|city center|city centre|general|generally)\s*[\.\?!]*\s*$/i', $message);
	}

	private function is_negative(string $message): bool {
		return 1 === preg_match('/\b(no|nope|cancel|change|different)\b/i', $message);
	}

	private function detect_coupon_code(string $message): string {
		if (preg_match('/\b(?:coupon|promo|promo\s*code|discount\s*code|code|voucher|gutschein|kortingscode|kortingsbon|bon\s*de\s*reduction|code\s*promo)\s*[:\-]?\s*["\']?([A-Z0-9][A-Z0-9_\-]{2,19})["\']?/iu', $message, $matches)) {
			return strtoupper(sanitize_text_field($matches[1]));
		}
		return '';
	}

	private function is_reset(string $message): bool {
		return 1 === preg_match('/\b(start over|restart|reset|new ride|new booking|change route|different route)\b/i', $message);
	}

	private function asks_for_new_booking(string $message): bool {
		return 1 === preg_match('/\b(book|create|start|make)\b.*\b(new|another)\b.*\b(one|ride|booking|trip)?\b|\bnew one\b/i', $message);
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

	private function asks_price_negotiation(string $message): bool {
		return $this->extract_price_offer($message) > 0
			|| 1 === preg_match('/\b(less|cheaper|discount|lower|too much|expensive|negotiate|deal|counteroffer|bucks?|dollars?|wish\s+to\s+go|do\s+\$?\d+|take\s+\$?\d+|with\s+\$?\d+|for\s+\$?\d+|\$?\d+\s*(?:bucks?|dollars?)?)\b/i', $message);
	}

	private function extract_price_offer(string $message): float {
		if (preg_match('/(?:\$|€|eur|usd)?\s*(\d+(?:[.,]\d{1,2})?)\s*(?:\$|€|eur|usd|bucks?|dollars?)?/i', $message, $matches)) {
			return round((float) str_replace(',', '.', (string) $matches[1]), 2);
		}

		return 0.0;
	}

	private function validate_price_offer(array $session, float $offer): array {
		$quote = $session['last_quote'];
		$original = round((float) ($quote['final_price'] ?? 0), 2);
		$currency = (string) ($quote['currency'] ?? 'USD');
		if ($original <= 0 || $offer <= 0) {
			return [
				'accepted' => false,
				'message' => __('I need a clear proposed fare amount before I can send it to dispatch.', 'ridefleet-ai-chatbot'),
			];
		}

		if ($offer >= $original) {
			return [
				'accepted' => false,
				'message' => sprintf(__('The verified fare is already %1$s %2$.2f. If you are happy with that fare, reply yes and I will continue the normal booking.', 'ridefleet-ai-chatbot'), $currency, $original),
			];
		}

		$discount = (($original - $offer) / $original) * 100;
		$max_discount = min(40.0, max(1.0, (float) \RideFleetAIChatbot\Support\Options::get('max_price_negotiation_discount', 20)));
		if ($discount > $max_discount) {
			return [
				'accepted' => false,
				'message' => sprintf(__('That offer is too far below the verified fare for me to send automatically. Verified fare: %1$s %2$.2f. Proposed fare: %1$s %3$.2f. Please propose something within %4$.1f%%, or reply yes to book at the verified fare.', 'ridefleet-ai-chatbot'), $currency, $original, $offer, $max_discount),
			];
		}

		if (($original - $offer) < 1) {
			return [
				'accepted' => false,
				'message' => sprintf(__('That difference is very small. The verified fare is %1$s %2$.2f. Reply yes to continue, or propose a clearer amount for dispatch to review.', 'ridefleet-ai-chatbot'), $currency, $original),
			];
		}

		return [
			'accepted' => true,
			'message' => '',
		];
	}

	private function price_offer_intro_message(array $session): string {
		$quote = $session['last_quote'];
		$data = $session['collected_data'];
		$original = (float) ($quote['final_price'] ?? 0);
		$offer = (float) ($data['proposed_price'] ?? 0);
		$currency = (string) ($quote['currency'] ?? 'USD');
		$discount = $original > 0 ? (($original - $offer) / $original) * 100 : 0;

		return sprintf(
			__('That is a reasonable counteroffer, so I can send it to dispatch for human approval. Original fare: %1$s %2$.2f. Proposed fare: %1$s %3$.2f (%4$.1f%% lower). This is not confirmed yet. What name should dispatch use for this request?', 'ridefleet-ai-chatbot'),
			$currency,
			$original,
			$offer,
			$discount
		);
	}

	private function price_negotiation_pending_message(array $session): string {
		$quote = $session['last_quote'];
		$data = $session['collected_data'];
		$currency = (string) ($quote['currency'] ?? 'USD');

		return sprintf(
			__('I sent your fare request to dispatch for human approval. Original fare: %1$s %2$.2f. Proposed fare: %1$s %3$.2f. This ride is not confirmed yet; the taxi team will contact you after review.', 'ridefleet-ai-chatbot'),
			$currency,
			(float) ($quote['final_price'] ?? 0),
			(float) ($data['proposed_price'] ?? 0)
		);
	}

	private function is_confused(string $message): bool {
		return 1 === preg_match('/^\s*(\?|what+|huh|why|really|seriously|wait|what do you mean)\s*\??\s*$/i', $message);
	}

	private function valid_phone(string $message): bool {
		$digits = preg_replace('/\D+/', '', $message);
		if (!is_string($digits) || strlen($digits) > 16) {
			return false;
		}

		if (str_starts_with(trim($message), '+32')) {
			return strlen($digits) === 11;
		}

		if (str_starts_with(trim($message), '+1')) {
			return strlen($digits) === 11;
		}

		return strlen($digits) >= 8;
	}

	private function valid_customer_name(string $message): bool {
		$value = trim($message);
		if (strlen($value) < 2 || strlen($value) > 80) {
			return false;
		}

		return 0 === preg_match('/^(yes|no|sure|ok|okay|yep|yeah|book|reserve|confirm|maakt niet uit|maak niet uit|does not matter|whatever|geen idee)$/i', $value);
	}

	private function phone_note(string $message): string {
		$compact = preg_replace('/\s+/', '', $message);
		if (is_string($compact) && (str_starts_with($compact, '+32') || preg_match('/^0[1-9]\d{7,8}$/', $compact))) {
			return __('Belgian number noted. Dispatch will use it only for this ride.', 'ridefleet-ai-chatbot');
		}

		return '';
	}

	private function normalize_pickup_time(string $message): string {
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

	private function resolve_place_text(string $text): string {
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

		$result = $this->core->search_core_places($text);
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
			'/\b(?:pick(?:\s*me)?\s*up|pickup|collect\s+me)\s+(?:at|from|in)\s+(.+)$/i',
			'/\b(?:picked\s+up|be\s+picked\s+up|be\s+picked)\s+(?:at|from|in)\s+(.+)$/i',
			'/\b(?:want\s+to\s+be\s+picked|want\s+to\s+be\s+picked\s+up)\s+(?:at|from|in)\s+(.+)$/i',
			'/\b(?:you\s+can\s+pick\s+me\s+up|can\s+pick\s+me\s+up)\s+(?:at|from|in)\s+(.+)$/i',
			'/\b(?:i\s+am|i\'m|im)\s+(?:at|in)\s+(.+)$/i',
			'/\b(?:drop\s*(?:me)?\s*off|destination\s+is|going\s+to|to)\s+(?:at|in)?\s*(.+)$/i',
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
		if (!in_array($state, ['capture_name', 'capture_negotiation_name'], true)) {
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
				'ask_pickup' => 'Where should we pick you up? Send a full street address, station, airport, hotel, landmark, or city name.',
				'ask_dropoff' => 'Thanks. What is the exact drop-off address or place?',
				'ask_confirm' => 'Would you like me to reserve this ride at the quoted price?',
				'ask_passengers' => 'How many passengers will ride?',
				'ask_luggage' => 'How many luggage items should we plan for? Enter 0 if none.',
				'ask_vehicle' => 'Which vehicle would you like?',
				'ask_extras' => 'Would you like to add any extras? Reply with numbers/names, or say none.',
				'ask_name' => 'Great. What name should we put on the booking?',
				'ask_phone' => 'What phone number should dispatch use for this ride?',
				'ask_time' => 'What pickup date and time would you like?',
				'new_booking' => 'Absolutely. Let us book a new ride. Where should we pick you up?',
				'cancelled' => 'No problem, I cancelled this booking flow. Start a new chat whenever you want to check another ride.',
				'done_confirmed' => 'Yes, you are done. Your booking is confirmed. I can close the chat, start a new booking, or send an edit request to dispatch.',
				'done_options' => 'You are all set for the last ride. I can close the chat, start a new booking, or help request an edit.',
				'end_graceful' => 'You are welcome. Have a nice day too. Your booking is confirmed, and I can close the chat now if you like.',
			],
			'fr' => [
				'ask_pickup' => 'Ou devons-nous venir vous chercher ? Envoyez une adresse complete, une gare, un aeroport, un hotel, un point de repere ou un nom de ville.',
				'ask_dropoff' => 'Merci. Quelle est l adresse exacte ou le lieu de destination ?',
				'ask_confirm' => 'Voulez-vous reserver cette course au prix indique ?',
				'ask_passengers' => 'Combien de passagers participeront a la course ?',
				'ask_luggage' => 'Combien de bagages devons-nous prevoir ? Entrez 0 si aucun.',
				'ask_vehicle' => 'Quel vehicule souhaitez-vous ?',
				'ask_extras' => 'Voulez-vous ajouter des extras ? Repondez avec les numeros/noms, ou dites aucun.',
				'ask_name' => 'Tres bien. Quel nom devons-nous mettre sur la reservation ?',
				'ask_phone' => 'Quel numero de telephone le dispatch peut-il utiliser ?',
				'ask_time' => 'Quelle date et heure de prise en charge souhaitez-vous ?',
				'new_booking' => 'Bien sur. Commencons une nouvelle reservation. Ou devons-nous venir vous chercher ?',
				'cancelled' => 'Pas de probleme, j ai annule ce flux de reservation.',
				'done_confirmed' => 'Oui, c est termine. Votre reservation est confirmee. Je peux fermer le chat, commencer une nouvelle reservation, ou envoyer une demande de modification.',
				'done_options' => 'Votre derniere course est confirmee. Je peux fermer le chat, commencer une nouvelle reservation, ou demander une modification.',
				'end_graceful' => 'Avec plaisir. Bonne journee. Votre reservation est confirmee, et je peux fermer le chat si vous le souhaitez.',
			],
			'nl' => [
				'ask_pickup' => 'Waar mogen we u ophalen? Stuur een volledig adres, station, luchthaven, hotel, herkenningspunt of stadsnaam.',
				'ask_dropoff' => 'Dank u. Wat is het exacte afleveradres of de plaats?',
				'ask_confirm' => 'Wilt u deze rit reserveren voor de opgegeven prijs?',
				'ask_passengers' => 'Met hoeveel passagiers reist u?',
				'ask_luggage' => 'Hoeveel bagage moeten we voorzien? Vul 0 in als er geen bagage is.',
				'ask_vehicle' => 'Welk voertuig wilt u?',
				'ask_extras' => 'Wilt u extra opties toevoegen? Antwoord met nummers/namen, of zeg geen.',
				'ask_name' => 'Prima. Op welke naam mogen we de boeking zetten?',
				'ask_phone' => 'Welk telefoonnummer mag dispatch gebruiken voor deze rit?',
				'ask_time' => 'Welke ophaaldatum en tijd wilt u?',
				'new_booking' => 'Zeker. Laten we een nieuwe rit boeken. Waar mogen we u ophalen?',
				'cancelled' => 'Geen probleem, ik heb deze boekingsflow geannuleerd.',
				'done_confirmed' => 'Ja, u bent klaar. Uw boeking is bevestigd. Ik kan de chat sluiten, een nieuwe boeking starten, of een wijziging aanvragen.',
				'done_options' => 'Uw laatste rit is bevestigd. Ik kan de chat sluiten, een nieuwe boeking starten, of een wijziging aanvragen.',
				'end_graceful' => 'Graag gedaan. Nog een fijne dag. Uw boeking is bevestigd, en ik kan de chat nu sluiten als u wilt.',
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
			],
			'booking' => [
				'id' => $session['collected_data']['last_booking_id'] ?? null,
				'changeRequestId' => $session['collected_data']['last_change_request_id'] ?? null,
				'requestType' => 'price_negotiation_pending' === ($session['state'] ?? '') ? 'price_negotiation' : null,
				'proposedPrice' => $session['collected_data']['proposed_price'] ?? null,
			],
			'ui_action' => $session['ui_action'] ?? null,
		];
	}
}
