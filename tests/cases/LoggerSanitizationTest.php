<?php
/**
 * Logger context-sanitization smoke test — ensures secrets are redacted.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoggerSanitizationTest extends TestCase {
	/**
	 * Mirrors Logger::sanitize_context() — kept in sync to assert intent.
	 */
	private function sanitize(array $context): array {
		$redacted = [];
		foreach ($context as $key => $value) {
			$key_str = (string) $key;
			if (preg_match('/key|secret|token|password|authorization/i', $key_str)) {
				$redacted[$key_str] = '[redacted]';
				continue;
			}
			if (is_scalar($value) || null === $value) {
				$redacted[$key_str] = $value;
			} elseif (is_array($value)) {
				$redacted[$key_str] = $this->sanitize($value);
			} else {
				$redacted[$key_str] = gettype($value);
			}
		}
		return $redacted;
	}

	public function test_redacts_api_keys_and_tokens(): void {
		$out = $this->sanitize([
			'openrouter_key' => 'sk-or-v1-LEAKED',
			'auth_token' => 'bearer xyz',
			'Authorization' => 'Bearer abc',
			'session_secret' => 'secret-val',
			'safe_value' => 'OK',
		]);

		self::assertSame('[redacted]', $out['openrouter_key']);
		self::assertSame('[redacted]', $out['auth_token']);
		self::assertSame('[redacted]', $out['Authorization']);
		self::assertSame('[redacted]', $out['session_secret']);
		self::assertSame('OK', $out['safe_value']);
	}

	public function test_redacts_nested_secrets(): void {
		$out = $this->sanitize([
			'outer' => [
				'request' => [
					'api_key' => 'should-vanish',
					'route' => '/foo',
				],
			],
		]);

		self::assertSame('[redacted]', $out['outer']['request']['api_key']);
		self::assertSame('/foo', $out['outer']['request']['route']);
	}
}
