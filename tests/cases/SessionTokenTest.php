<?php
/**
 * SessionToken smoke tests.
 */

declare(strict_types=1);

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RideFleetAIChatbot\Support\SessionToken;

final class SessionTokenTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs([
			'sanitize_key' => static fn(string $key): string => strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $key)),
			'wp_generate_uuid4' => static fn(): string => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
			'wp_generate_password' => static fn(int $len = 12): string => str_repeat('s', $len),
		]);

		// Stub Options::signing_secret() by injecting a static option store.
		Functions\when('get_option')->justReturn(['session_signing_secret' => 'unit-test-secret-please-ignore']);
		Functions\when('update_option')->justReturn(true);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sign_returns_session_key_with_signature(): void {
		$signed = SessionToken::sign('abc123');
		self::assertStringContainsString('abc123.', $signed);
	}

	public function test_verify_returns_session_key_for_valid_token(): void {
		$signed = SessionToken::sign('valid-session');
		self::assertSame('valid-session', SessionToken::verify($signed));
	}

	public function test_verify_returns_empty_for_tampered_signature(): void {
		$signed = SessionToken::sign('victim-session');
		$tampered = $signed . 'evil';
		self::assertSame('', SessionToken::verify($tampered));
	}

	public function test_verify_returns_empty_for_swapped_session_key(): void {
		$signed = SessionToken::sign('alice');
		$parts = explode('.', $signed, 2);
		$attacker_token = 'bob.' . $parts[1];
		self::assertSame('', SessionToken::verify($attacker_token));
	}

	public function test_verify_accepts_legacy_unsigned_session(): void {
		// Pre-HMAC clients used to send raw IDs; we still accept them as long
		// as they pass sanitize_key. New clients always come back signed.
		self::assertSame('rfac_legacy123', SessionToken::verify('rfac_legacy123'));
	}
}
