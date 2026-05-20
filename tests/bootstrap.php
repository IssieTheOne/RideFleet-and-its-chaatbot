<?php
/**
 * PHPUnit bootstrap.
 *
 * We avoid spinning up a full WordPress test install (slow) and instead use
 * Brain Monkey to stub the WP functions our pure-PHP units need. That keeps
 * these tests as fast unit tests rather than integration tests — perfect for
 * the bits of logic that don't actually touch the database.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Constants the plugins expect to find when bootstrapped.
if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
if (!defined('MINUTE_IN_SECONDS')) {
	define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
	define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
	define('WEEK_IN_SECONDS', 604800);
}
