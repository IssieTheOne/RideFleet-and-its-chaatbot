<?php
/**
 * OpenRouter chat completion client.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

use RideFleetAIChatbot\Support\Options;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

final class OpenRouterClient {
	public function classify_turn(string $message, array $session): array {
		$key = (string) Options::get('openrouter_key', '');
		if (!$this->valid_key($key)) {
			return ['success' => false, 'message' => __('OpenRouter is not configured yet.', 'ridefleet-ai-chatbot')];
		}

		$state = sanitize_key((string) ($session['state'] ?? 'greeting'));
		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$language = sanitize_key((string) ($data['language'] ?? ''));
		$system = 'You classify one customer message for a taxi booking chatbot. Return strict JSON only. '
			. 'Allowed intents: smalltalk, location, confirmation, question, edit_request, cancel, price_negotiation, time, name, phone, passenger_count, luggage_count, extras, vehicle_choice, unknown. '
			. 'Only mark location when the message clearly contains a pickup/dropoff place, address, station, airport, hotel, landmark, or broad city. '
			. 'Do not treat jokes, greetings, questions, "geen idee", "maak niet uit", or vague words as location. '
			. 'Never invent or reinterpret saved state. If state is capture_dropoff, do not output pickup unless the user explicitly gives a full route with from/to. '
			. 'Use null for missing fields. Fields: intent, confidence (0-1), language (en/fr/nl/unknown), pickup, dropoff, proposed_price, pickup_time, name, phone, passenger_count, luggage_count, extras, vehicle_choice, booking_id, question_type, reply_tone, admin_summary_en.';
		$user = wp_json_encode(
			[
				'state' => $state,
				'locked_language' => $language ?: null,
				'message' => $message,
			]
		);

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => 12,
				'redirection' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type' => 'application/json',
					'HTTP-Referer' => home_url(),
					'X-Title' => get_bloginfo('name') . ' RideFleet AI Chatbot Intent Classifier',
				],
				'body' => wp_json_encode(
					[
						'model' => sanitize_text_field((string) Options::get('selected_model', 'openai/gpt-4o-mini')),
						'messages' => [
							['role' => 'system', 'content' => $system],
							['role' => 'user', 'content' => (string) $user],
						],
						'temperature' => 0,
						'max_tokens' => 220,
						'response_format' => ['type' => 'json_object'],
					]
				),
			]
		);

		$decoded = $this->decode_json_response($response);
		if (empty($decoded['success'])) {
			return $decoded;
		}

		return ['success' => true, 'data' => $this->normalize_classification($decoded['data'])];
	}

	public function system_prompt(): string {
		$bio = trim(wp_strip_all_tags((string) Options::get('company_bio', '')));
		if ('' === $bio) {
			$bio = 'No company bio has been configured yet. Keep responses short and focused on booking a taxi ride.';
		}

		return "You are a professional, polite conversational booking assistant for our taxi service. Use this exact company bio text to answer user questions about our fleet, services, or policies: {$bio}\n\n"
			. "Your exclusive objective is to conversationally collect the user's pickup point, drop-off point, customer name, phone number, and pickup time to help them book a taxi ride. Do not hallucinate prices; always wait for the pricing module to provide the quote.\n\n"
			. "You are an LLM text assistant. You cannot generate, render, or display images, drawings, or visual art. If a user asks for images or designs, you must politely decline. You are strictly forbidden from discussing anything outside the taxi business, transportation, localized routing, or company information. If the user shifts the topic to politics, sports, entertainment, coding, general knowledge, or anything unrelated, you must gracefully decline and steer the conversation immediately back to helping them schedule their taxi cab ride.";
	}

	public function localize_reply(string $message, string $language, array $session = []): string {
		$language = sanitize_key($language);
		if (!in_array($language, ['fr', 'nl'], true) || '' === trim($message)) {
			return $message;
		}

		$key = (string) Options::get('openrouter_key', '');
		if (!$this->valid_key($key)) {
			return $message;
		}

		$cache_key = 'rfac_reply_' . md5($language . '|' . $message);
		$cached = get_transient($cache_key);
		if (is_string($cached) && '' !== $cached) {
			return $cached;
		}

		$target = 'fr' === $language ? 'French' : 'Dutch';
		$system = 'You are a taxi booking chatbot reply localizer. Return strict JSON only with key "message". '
			. 'Translate or lightly rewrite the assistant message into natural ' . $target . '. '
			. 'Do not add new facts, prices, discounts, policies, locations, booking IDs, dates, phone numbers, vehicle names, or extras. '
			. 'Preserve all amounts, codes, addresses, vehicle names, numbered lists, and the booking workflow meaning exactly. '
			. 'Keep the tone warm and human, but concise. If the message is already in the target language, polish only obvious awkwardness.';

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => 10,
				'redirection' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type' => 'application/json',
					'HTTP-Referer' => home_url(),
					'X-Title' => get_bloginfo('name') . ' RideFleet AI Chatbot Reply Localizer',
				],
				'body' => wp_json_encode(
					[
						'model' => sanitize_text_field((string) Options::get('selected_model', 'openai/gpt-4o-mini')),
						'messages' => [
							['role' => 'system', 'content' => $system],
							[
								'role' => 'user',
								'content' => wp_json_encode(
									[
										'target_language' => $language,
										'state' => sanitize_key((string) ($session['state'] ?? '')),
										'message' => $message,
									]
								),
							],
						],
						'temperature' => 0.2,
						'max_tokens' => 260,
						'response_format' => ['type' => 'json_object'],
					]
				),
			]
		);

		$decoded = $this->decode_json_response($response);
		$localized = is_array($decoded['data'] ?? null) ? trim(wp_strip_all_tags((string) ($decoded['data']['message'] ?? ''))) : '';
		if (empty($decoded['success']) || '' === $localized) {
			return $message;
		}

		set_transient($cache_key, $localized, DAY_IN_SECONDS);
		return $localized;
	}

	public function rewrite_text(string $text, string $instruction = ''): array {
		$key = (string) Options::get('openrouter_key', '');
		if (!$this->valid_key($key)) {
			return ['success' => false, 'message' => __('OpenRouter is not configured yet.', 'ridefleet-ai-chatbot')];
		}

		$text = trim($text);
		if ('' === $text) {
			return ['success' => false, 'message' => __('There is nothing to rewrite.', 'ridefleet-ai-chatbot')];
		}

		$instruction = trim($instruction);
		if ('' === $instruction) {
			$instruction = 'Rewrite this taxi company bio to be concise and factual. Keep every concrete fact (services, fleet, hours, areas, policies, contact info). Remove fluff, marketing adjectives, and repetition. Use clear professional language and short sentences. Return strict JSON only with key "rewritten".';
		}

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => 30,
				'redirection' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type' => 'application/json',
					'HTTP-Referer' => home_url(),
					'X-Title' => get_bloginfo('name') . ' RideFleet AI Chatbot Bio Rewriter',
				],
				'body' => wp_json_encode(
					[
						'model' => sanitize_text_field((string) Options::get('selected_model', 'openai/gpt-4o-mini')),
						'messages' => [
							['role' => 'system', 'content' => $instruction],
							['role' => 'user', 'content' => $text],
						],
						'temperature' => 0.2,
						'max_tokens' => 700,
						'response_format' => ['type' => 'json_object'],
					]
				),
			]
		);

		$decoded = $this->decode_json_response($response);
		if (empty($decoded['success'])) {
			return $decoded;
		}

		$rewritten = trim(wp_strip_all_tags((string) ($decoded['data']['rewritten'] ?? '')));
		if ('' === $rewritten) {
			return ['success' => false, 'message' => __('The AI returned an empty rewrite.', 'ridefleet-ai-chatbot')];
		}

		return ['success' => true, 'rewritten' => $rewritten];
	}

	public function valid_key(string $key): bool {
		return 1 === preg_match('/^sk-or-v1-[A-Za-z0-9_\-]+$/', $key);
	}

	private function decode_json_response(array|WP_Error $response): array {
		if (is_wp_error($response)) {
			return ['success' => false, 'message' => $response->get_error_message()];
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$content = (string) ($body['choices'][0]['message']['content'] ?? '');
		$data = json_decode($content, true);

		if ($status < 200 || $status >= 300 || !is_array($body) || !is_array($data)) {
			return ['success' => false, 'message' => __('The AI classifier is unavailable right now.', 'ridefleet-ai-chatbot'), 'status' => $status];
		}

		return ['success' => true, 'data' => $data];
	}

	private function normalize_classification(array $data): array {
		$allowed = ['smalltalk', 'location', 'confirmation', 'question', 'edit_request', 'cancel', 'price_negotiation', 'time', 'name', 'phone', 'passenger_count', 'luggage_count', 'extras', 'vehicle_choice', 'unknown'];
		$intent = sanitize_key((string) ($data['intent'] ?? 'unknown'));
		if (!in_array($intent, $allowed, true)) {
			$intent = 'unknown';
		}

		$fields = [
			'pickup' => $this->clean_field($data['pickup'] ?? null),
			'dropoff' => $this->clean_field($data['dropoff'] ?? null),
			'proposed_price' => is_numeric($data['proposed_price'] ?? null) ? (float) $data['proposed_price'] : null,
			'pickup_time' => $this->clean_field($data['pickup_time'] ?? null),
			'name' => $this->clean_field($data['name'] ?? null),
			'phone' => $this->clean_field($data['phone'] ?? null),
			'passenger_count' => is_numeric($data['passenger_count'] ?? null) ? (int) $data['passenger_count'] : null,
			'luggage_count' => is_numeric($data['luggage_count'] ?? null) ? (int) $data['luggage_count'] : null,
			'extras' => $this->clean_field($data['extras'] ?? null),
			'vehicle_choice' => $this->clean_field($data['vehicle_choice'] ?? null),
			'booking_id' => $this->clean_field($data['booking_id'] ?? null),
			'question_type' => $this->clean_field($data['question_type'] ?? null),
		];

		return [
			'intent' => $intent,
			'confidence' => max(0, min(1, (float) ($data['confidence'] ?? 0))),
			'language' => in_array(($data['language'] ?? ''), ['en', 'fr', 'nl'], true) ? (string) $data['language'] : 'unknown',
			'fields' => array_filter($fields, static fn($value): bool => null !== $value && '' !== $value),
			'reply_tone' => sanitize_key((string) ($data['reply_tone'] ?? 'neutral')),
			'admin_summary_en' => sanitize_textarea_field((string) ($data['admin_summary_en'] ?? '')),
		];
	}

	private function clean_field(mixed $value): ?string {
		if (null === $value || is_array($value) || is_object($value)) {
			return null;
		}

		$value = trim(wp_strip_all_tags((string) $value));
		return '' === $value ? null : substr($value, 0, 240);
	}
}
