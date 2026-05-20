<?php
/**
 * Frontend chat widget.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Frontend;

use RideFleetAIChatbot\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class Widget {
	public static function register_hooks(): void {
		add_shortcode('ridefleet_ai_chatbot', [self::class, 'shortcode']);
		add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
	}

	public static function register_assets(): void {
		wp_register_style('rfac-widget', RFAC_PLUGIN_URL . 'assets/frontend/widget.css', [], RFAC_VERSION);
		wp_register_script('rfac-widget', RFAC_PLUGIN_URL . 'assets/frontend/widget.js', [], RFAC_VERSION, true);
	}

	public static function shortcode(): string {
		$theme = Options::get('chatbot_ui_theme', []);
		$theme = is_array($theme) ? $theme : [];

		wp_enqueue_style('rfac-widget');
		wp_enqueue_script('rfac-widget');
		wp_localize_script(
			'rfac-widget',
			'RideFleetAIChatbot',
			[
				'endpoint' => esc_url_raw(rest_url('ridefleet-chatbot/v1/message')),
				'placesEndpoint' => esc_url_raw(rest_url('ridefleet-chatbot/v1/places')),
				'nonce' => wp_create_nonce('wp_rest'),
			]
		);

		$style = sprintf(
			'--rfac-primary:%s;--rfac-primary-dark:%s;--rfac-surface:%s;--rfac-text:%s;--rfac-muted:%s;',
			esc_attr($theme['primary'] ?? '#0f766e'),
			esc_attr($theme['primary_dark'] ?? '#0b5f59'),
			esc_attr($theme['surface'] ?? '#ffffff'),
			esc_attr($theme['text'] ?? '#17202a'),
			esc_attr($theme['muted'] ?? '#64748b')
		);

		ob_start();
		?>
		<div class="rfac-widget" style="<?php echo esc_attr($style); ?>" data-rfac-widget
			data-rfac-endpoint="<?php echo esc_url(rest_url('ridefleet-chatbot/v1/message')); ?>"
			data-rfac-places-endpoint="<?php echo esc_url(rest_url('ridefleet-chatbot/v1/places')); ?>"
			data-rfac-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">

			<button class="rfac-bubble" type="button" aria-label="<?php esc_attr_e('Open taxi booking chat', 'ridefleet-ai-chatbot'); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
					<path d="M4.913 2.658c2.075-.27 4.19-.408 6.337-.408 2.147 0 4.262.139 6.337.408 1.922.25 3.291 1.861 3.405 3.727a4.403 4.403 0 0 0-1.032-.211 50.89 50.89 0 0 0-8.42 0c-2.358.196-4.04 2.19-4.04 4.434v4.286a4.47 4.47 0 0 0 2.433 3.984L7.28 21.53A.75.75 0 0 1 6 21v-4.03a48.527 48.527 0 0 1-1.087-.128C2.905 16.58 1.5 14.833 1.5 12.862V6.638c0-1.97 1.405-3.718 3.413-3.979Z" />
					<path d="M15.75 7.5c-1.376 0-2.739.057-4.086.169C10.124 7.797 9 9.103 9 10.609v4.285c0 1.507 1.128 2.814 2.67 2.94 1.243.102 2.5.157 3.768.165l2.782 2.781a.75.75 0 0 0 1.28-.53v-2.39l.33-.026c1.542-.125 2.67-1.433 2.67-2.94v-4.286c0-1.505-1.125-2.811-2.664-2.94A49.392 49.392 0 0 0 15.75 7.5Z" />
				</svg>
				<span class="rfac-bubble-label"><?php esc_html_e('Book a ride', 'ridefleet-ai-chatbot'); ?></span>
			</button>

			<section class="rfac-window" role="dialog" aria-modal="true" aria-labelledby="rfac-dialog-title" aria-label="<?php esc_attr_e('Taxi booking chat', 'ridefleet-ai-chatbot'); ?>" hidden>
				<header class="rfac-header">
					<div class="rfac-header-info">
						<div class="rfac-avatar" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
								<path d="M3.375 4.5C2.339 4.5 1.5 5.34 1.5 6.375V13.5h12V6.375c0-1.036-.84-1.875-1.875-1.875h-8.25ZM13.5 15h-12v2.625c0 1.035.84 1.875 1.875 1.875h.375a3 3 0 1 1 6 0h3a.75.75 0 0 0 .75-.75V15Z" />
								<path d="M8.25 19.5a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0ZM15.75 6.75a.75.75 0 0 0-.75.75v11.25c0 .087.015.17.042.248a3 3 0 0 1 5.958.464c.853-.175 1.522-.935 1.464-1.883a18.659 18.659 0 0 0-3.732-10.104 1.837 1.837 0 0 0-1.47-.725H15.75Z" />
								<path d="M19.5 19.5a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0Z" />
							</svg>
						</div>
						<div>
							<strong id="rfac-dialog-title"><?php esc_html_e('RideFleet Booking', 'ridefleet-ai-chatbot'); ?></strong>
							<span><?php esc_html_e('Taxi reservations', 'ridefleet-ai-chatbot'); ?> <i class="rfac-online-dot" aria-hidden="true"></i></span>
						</div>
					</div>
					<div class="rfac-header-actions">
						<button class="rfac-reset" type="button" data-rfac-reset aria-label="<?php esc_attr_e('Start a new conversation', 'ridefleet-ai-chatbot'); ?>" title="<?php esc_attr_e('Start a new conversation', 'ridefleet-ai-chatbot'); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
								<path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.201 2.466l-.312-.311h2.433a.75.75 0 0 0 0-1.5H3.989a.75.75 0 0 0-.75.75v4.242a.75.75 0 0 0 1.5 0v-2.43l.31.31a7 7 0 0 0 11.712-3.138.75.75 0 0 0-1.449-.39Zm1.23-3.723a.75.75 0 0 0 .219-.53V2.929a.75.75 0 0 0-1.5 0V5.36l-.31-.31A7 7 0 0 0 3.239 8.188a.75.75 0 1 0 1.448.389A5.5 5.5 0 0 1 13.89 6.11l.311.31h-2.432a.75.75 0 0 0 0 1.5h4.243a.75.75 0 0 0 .53-.219Z" clip-rule="evenodd" />
							</svg>
						</button>
						<button class="rfac-close" type="button" aria-label="<?php esc_attr_e('Close chat', 'ridefleet-ai-chatbot'); ?>">&times;</button>
					</div>
				</header>

				<div class="rfac-progress" aria-hidden="true">
					<span class="rfac-progress-step is-active"><i></i><b><?php esc_html_e('Pickup', 'ridefleet-ai-chatbot'); ?></b></span>
					<span class="rfac-progress-step"><i></i><b><?php esc_html_e('Quote', 'ridefleet-ai-chatbot'); ?></b></span>
					<span class="rfac-progress-step"><i></i><b><?php esc_html_e('Details', 'ridefleet-ai-chatbot'); ?></b></span>
					<span class="rfac-progress-step"><i></i><b><?php esc_html_e('Done', 'ridefleet-ai-chatbot'); ?></b></span>
				</div>

				<div class="rfac-messages" data-rfac-messages role="log" aria-live="polite" aria-relevant="additions text" data-rfac-default-greeting="<?php esc_attr_e('Hi! I can book your taxi ride. Where should we pick you up? Start typing your address and I will suggest matches.', 'ridefleet-ai-chatbot'); ?>">
					<div class="rfac-message rfac-message-bot"><?php esc_html_e('Hi! I can book your taxi ride. Where should we pick you up? Start typing your address and I will suggest matches.', 'ridefleet-ai-chatbot'); ?></div>
				</div>

				<div class="rfac-typing" data-rfac-typing hidden>
					<span class="rfac-typing-label" data-rfac-typing-label><?php esc_html_e('Typing', 'ridefleet-ai-chatbot'); ?></span>
					<span class="rfac-typing-dots" aria-hidden="true"><span></span><span></span><span></span></span>
				</div>

				<form class="rfac-form" data-rfac-form>
					<select class="rfac-phone-prefix" data-rfac-phone-prefix hidden aria-label="<?php esc_attr_e('Country code', 'ridefleet-ai-chatbot'); ?>">
						<option value="+32">BE +32</option>
						<option value="+31">NL +31</option>
						<option value="+33">FR +33</option>
						<option value="+49">DE +49</option>
						<option value="+44">UK +44</option>
						<option value="+1">US +1</option>
					</select>
					<input type="text" data-rfac-input placeholder="<?php esc_attr_e('Type your message...', 'ridefleet-ai-chatbot'); ?>" autocomplete="off">
					<button type="submit" aria-label="<?php esc_attr_e('Send message', 'ridefleet-ai-chatbot'); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" width="18" height="18" aria-hidden="true">
							<path d="M3.105 2.288a.75.75 0 0 0-.826.95l1.414 4.926A1.5 1.5 0 0 0 5.135 9.25h6.115a.75.75 0 0 1 0 1.5H5.135a1.5 1.5 0 0 0-1.442 1.086l-1.414 4.926a.75.75 0 0 0 .826.95 28.897 28.897 0 0 0 15.293-7.154.75.75 0 0 0 0-1.115A28.897 28.897 0 0 0 3.105 2.288Z" />
						</svg>
					</button>
				</form>
			</section>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
