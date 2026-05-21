<?php
/**
 * OpenRouter chat completion client.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

use RideFleetAIChatbot\Support\Logger;
use RideFleetAIChatbot\Support\Options;
use RideFleetAIChatbot\Support\UsageMeter;
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

		if (UsageMeter::circuit_open()) {
			return ['success' => false, 'message' => __('AI service is temporarily unavailable.', 'ridefleet-ai-chatbot')];
		}

		if (UsageMeter::over_budget()) {
			Logger::warning('openrouter', 'Daily token budget exceeded — skipping classifier call.');
			return ['success' => false, 'message' => __('Daily AI budget exhausted.', 'ridefleet-ai-chatbot')];
		}

		$state = sanitize_key((string) ($session['state'] ?? 'greeting'));
		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$language = sanitize_key((string) ($data['language'] ?? ''));
		$system = 'You are an intent classifier for a taxi booking chatbot. Return strict JSON only — no prose. '
			. 'Allowed intents: smalltalk, location, confirmation, question, edit_request, cancel, time, name, phone, passenger_count, luggage_count, extras, vehicle_choice, unknown. '
			. 'CRITICAL RULES for location intent: '
			. '(1) Only use location intent when the message clearly contains a named place — an address, street, station, airport, hotel, city, or landmark. '
			. '(2) NEVER classify a message as location if it is a question ("what", "where", "who", "why", "how", "which", "when"), a greeting, a sentence about language, a complaint, or a general statement. '
			. '(3) If the extracted pickup or dropoff field would contain a verb phrase, question fragment, or non-place text (e.g. "talk to me", "you before", "are you done", "contact please"), set that field to null and use intent=question or intent=unknown instead. '
			. '(4) If state is capture_dropoff, do not output a pickup field unless the user explicitly provides a full "from X to Y" route. '
			. 'Extract every booking field visible in the message: pickup, dropoff, passenger_count, luggage_count, pickup_time, name, phone. Multiple fields in one message is valid and encouraged. '
			. 'Use null for any field not clearly present. '
			. 'JSON fields: intent, confidence (0–1), language (en/fr/nl/unknown), pickup, dropoff, pickup_time, name, phone, passenger_count, luggage_count, extras, vehicle_choice, booking_id, question_type, reply_tone (neutral/playful/urgent), admin_summary_en.';
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

	/**
	 * One-shot field extractor: asks the AI to pull every booking field it can see
	 * from a single user message. Used for greedy intake so users can say everything
	 * in one sentence and skip individual capture states.
	 */
	public function extract_booking_fields(string $message, array $session): array {
		$key = (string) Options::get('openrouter_key', '');
		if (!$this->valid_key($key)) {
			return ['success' => false];
		}

		if (UsageMeter::circuit_open() || UsageMeter::over_budget()) {
			return ['success' => false];
		}

		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$system = 'Extract every taxi booking field visible in the user message. Return strict JSON only. '
			. 'Fields (all optional, use null if not present): '
			. 'pickup (string — place name or address), '
			. 'dropoff (string — place name or address), '
			. 'passenger_count (integer), '
			. 'luggage_count (integer), '
			. 'pickup_time (string — preserve the user\'s original wording, e.g. "tomorrow 9am", "tonight at 8", "in 2 hours"), '
			. 'customer_name (string — full name only, not a place), '
			. 'customer_phone (string — digits / formatted phone). '
			. 'IMPORTANT: only set pickup/dropoff when the value is clearly a real place name, address, or landmark. '
			. 'Never set pickup/dropoff to verb phrases, questions, or non-place text. '
			. 'If a field cannot be confidently extracted, use null.';

		$already = [];
		foreach (['pickup_address' => 'pickup', 'dropoff_address' => 'dropoff', 'passengers' => 'passenger_count', 'luggage' => 'luggage_count', 'pickup_time' => 'pickup_time', 'customer_name' => 'customer_name', 'customer_phone' => 'customer_phone'] as $stored => $label) {
			if (!empty($data[$stored])) {
				$already[] = $label . '=' . $data[$stored];
			}
		}

		$context = $already ? 'Already captured: ' . implode(', ', $already) . '. Only extract what is NEW.' : 'Nothing captured yet.';

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => 10,
				'redirection' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type' => 'application/json',
					'HTTP-Referer' => home_url(),
					'X-Title' => get_bloginfo('name') . ' RideFleet Field Extractor',
				],
				'body' => wp_json_encode(
					[
						'model' => sanitize_text_field((string) Options::get('selected_model', 'openai/gpt-4o-mini')),
						'messages' => [
							['role' => 'system', 'content' => $system],
							['role' => 'user', 'content' => $context . "\n\nCustomer message: " . $message],
						],
						'temperature' => 0,
						'max_tokens' => 200,
						'response_format' => ['type' => 'json_object'],
					]
				),
			]
		);

		$decoded = $this->decode_json_response($response);
		if (empty($decoded['success']) || !is_array($decoded['data'] ?? null)) {
			return ['success' => false];
		}

		$raw = $decoded['data'];
		$clean = [];
		foreach (['pickup', 'dropoff', 'pickup_time', 'customer_name', 'customer_phone'] as $field) {
			$val = $this->clean_field($raw[$field] ?? null);
			if (null !== $val) {
				$clean[$field] = $val;
			}
		}

		foreach (['passenger_count', 'luggage_count'] as $field) {
			if (is_numeric($raw[$field] ?? null) && (int) $raw[$field] >= 0) {
				$clean[$field] = (int) $raw[$field];
			}
		}

		return ['success' => true, 'fields' => $clean];
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
			UsageMeter::circuit_record_failure();
			Logger::warning('openrouter', $response->get_error_message());
			return ['success' => false, 'message' => $response->get_error_message()];
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$content = (string) ($body['choices'][0]['message']['content'] ?? '');
		$data = json_decode($content, true);

		if ($status < 200 || $status >= 300 || !is_array($body) || !is_array($data)) {
			UsageMeter::circuit_record_failure();
			Logger::warning('openrouter', 'Non-2xx or unparseable response', ['status' => $status]);
			return ['success' => false, 'message' => __('The AI classifier is unavailable right now.', 'ridefleet-ai-chatbot'), 'status' => $status];
		}

		$usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
		UsageMeter::record(
			(int) ($usage['prompt_tokens'] ?? 0),
			(int) ($usage['completion_tokens'] ?? 0)
		);
		UsageMeter::circuit_record_success();

		return ['success' => true, 'data' => $data];
	}

	private function normalize_classification(array $data): array {
		$allowed = ['smalltalk', 'location', 'confirmation', 'question', 'edit_request', 'cancel', 'time', 'name', 'phone', 'passenger_count', 'luggage_count', 'extras', 'vehicle_choice', 'unknown'];
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
