<?php
/**
 * Main plugin container.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot;

use RideFleetAIChatbot\Admin\Admin;
use RideFleetAIChatbot\Core\Installer;
use RideFleetAIChatbot\Frontend\Widget;
use RideFleetAIChatbot\Rest\Routes;
use RideFleetAIChatbot\Services\SessionRepository;

if (!defined('ABSPATH')) {
	exit;
}

final class Plugin {
	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ($this->booted) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain('ridefleet-ai-chatbot', false, dirname(plugin_basename(RFAC_PLUGIN_FILE)) . '/languages');

		Installer::maybe_update();
		Admin::register_hooks();
		Routes::register_hooks();
		Widget::register_hooks();

		add_action(Installer::CRON_CLEANUP_HOOK, [SessionRepository::class, 'cleanup_old_sessions']);
	}
}
