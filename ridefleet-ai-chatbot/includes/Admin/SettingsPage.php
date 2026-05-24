<?php
/**
 * Chatbot settings page.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

use RideFleetAIChatbot\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class SettingsPage {
	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Access denied.', 'ridefleet-ai-chatbot'));
		}

		$options = Options::all();
		$theme = is_array($options['chatbot_ui_theme'] ?? null) ? $options['chatbot_ui_theme'] : [];
		?>
		<div class="wrap rfac-admin">
			<?php settings_errors('rfac_settings'); ?>

			<div class="rfac-hero">
				<div>
					<p class="rfac-kicker"><?php esc_html_e('RideFleet AI Chatbot', 'ridefleet-ai-chatbot'); ?></p>
					<h1><?php esc_html_e('Chatbot Settings', 'ridefleet-ai-chatbot'); ?></h1>
					<p><?php esc_html_e('Configure the AI model, booking API, company context, widget theme, and conversation guardrails.', 'ridefleet-ai-chatbot'); ?></p>
				</div>
				<div class="rfac-hero-actions">
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot')); ?>" class="button"><?php esc_html_e('Dashboard', 'ridefleet-ai-chatbot'); ?></a>
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot-api-logs')); ?>" class="button"><?php esc_html_e('API Logs', 'ridefleet-ai-chatbot'); ?></a>
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot-changes')); ?>" class="button"><?php esc_html_e('Change Requests', 'ridefleet-ai-chatbot'); ?></a>
				</div>
			</div>

			<?php Admin::render_settings_form($options, $theme); ?>
		</div>
		<?php
	}
}
