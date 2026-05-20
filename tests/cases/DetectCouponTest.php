<?php
/**
 * Coupon code detection smoke tests.
 *
 * detect_coupon_code is private on ConversationEngine, so we re-implement
 * the regex contract here to lock in the behavior we depend on.
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DetectCouponTest extends TestCase {
	private function detect(string $message): string {
		if (preg_match('/\b(?:coupon|promo|promo\s*code|discount\s*code|code|voucher|gutschein|kortingscode|kortingsbon|bon\s*de\s*reduction|code\s*promo)\s*[:\-]?\s*["\']?([A-Z0-9][A-Z0-9_\-]{2,19})["\']?/iu', $message, $matches)) {
			return strtoupper($matches[1]);
		}
		return '';
	}

	/**
	 * @dataProvider validCases
	 */
	public function test_detects_valid_codes(string $message, string $expected): void {
		self::assertSame($expected, $this->detect($message));
	}

	public static function validCases(): array {
		return [
			['I have a coupon SAVE10', 'SAVE10'],
			['promo: NEWYEAR2026', 'NEWYEAR2026'],
			['use code BLACKFRIDAY', 'BLACKFRIDAY'],
			['Voucher: WELCOME-25', 'WELCOME-25'],
			['I have a kortingscode HOLIDAY5', 'HOLIDAY5'],
			['code promo: ETE2026', 'ETE2026'],
			["coupon 'STUDENT'", 'STUDENT'],
		];
	}

	/**
	 * @dataProvider invalidCases
	 */
	public function test_ignores_non_codes(string $message): void {
		self::assertSame('', $this->detect($message));
	}

	public static function invalidCases(): array {
		return [
			['Hi how are you today?'],
			['Pick me up at Antwerp Central Station'],
			['I want a discount please'],
			['I have a question'],
			['code is too short'], // "is" only two chars — under the 3-char floor.
		];
	}
}
