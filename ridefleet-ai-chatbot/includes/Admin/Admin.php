<?php
/**
 * Admin settings page.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

use RideFleetAIChatbot\Services\CoreApiClient;
use RideFleetAIChatbot\Services\OpenRouterClient;
use RideFleetAIChatbot\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class Admin {
	private const POPULAR_MODELS = [
		'openai/gpt-4o-mini',
		'openai/gpt-4o',
		'anthropic/claude-3-5-haiku',
		'anthropic/claude-3-5-sonnet',
		'meta-llama/llama-3.1-8b-instruct',
		'meta-llama/llama-3.1-70b-instruct',
		'mistralai/mistral-7b-instruct',
		'mistralai/mixtral-8x7b-instruct',
		'google/gemini-flash-1.5',
		'google/gemini-pro-1.5',
	];

	public static function register_hooks(): void {
		add_action('admin_menu', [self::class, 'menu']);
		add_action('admin_init', [self::class, 'save']);
		add_action('admin_enqueue_scripts', [self::class, 'assets']);
		add_action('admin_post_rfac_delete_chat_session', [ChatHistoryPage::class, 'delete']);
		add_action('admin_post_rfac_export_chat_sessions', [ChatHistoryPage::class, 'export_csv']);
		add_action('admin_post_rfac_update_change_request', [ChangeRequestsPage::class, 'update']);
		add_action('wp_ajax_rfac_test_openrouter', [self::class, 'ajax_test_openrouter']);
		add_action('wp_ajax_rfac_test_core_api', [self::class, 'ajax_test_core_api']);
		add_action('wp_ajax_rfac_rewrite_bio', [self::class, 'ajax_rewrite_bio']);
	}

	public static function menu(): void {
		$pending_changes = self::pending_change_count();
		$change_label = __('Change Requests', 'ridefleet-ai-chatbot');
		if ($pending_changes > 0) {
			$change_label .= ' <span class="awaiting-mod">' . number_format_i18n($pending_changes) . '</span>';
		}

		add_menu_page(
			__('RideFleet AI Chatbot', 'ridefleet-ai-chatbot'),
			__('RideFleet Chatbot', 'ridefleet-ai-chatbot'),
			'manage_options',
			'ridefleet-ai-chatbot',
			[self::class, 'render'],
			'dashicons-format-chat',
			57
		);

		add_submenu_page(
			'ridefleet-ai-chatbot',
			__('Chatbot Conversations', 'ridefleet-ai-chatbot'),
			__('Conversations', 'ridefleet-ai-chatbot'),
			'manage_options',
			'ridefleet-ai-chatbot-history',
			[ChatHistoryPage::class, 'render']
		);

		add_submenu_page(
			'ridefleet-ai-chatbot',
			__('Booking Change Requests', 'ridefleet-ai-chatbot'),
			$change_label,
			'manage_options',
			'ridefleet-ai-chatbot-changes',
			[ChangeRequestsPage::class, 'render']
		);
	}

	private static function pending_change_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'rfac_change_requests';
		$exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($exists !== $table) {
			return 0;
		}

		return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
	}

	private static function stats(): array {
		global $wpdb;
		$sessions = $wpdb->prefix . 'rfac_chat_sessions';
		$bookings = $wpdb->prefix . 'rfac_booking_events';
		$changes = $wpdb->prefix . 'rfac_change_requests';

		$today = current_time('Y-m-d');

		return [
			'total_sessions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sessions}"),
			'sessions_today' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sessions} WHERE DATE(created_at) = %s", $today)),
			'total_bookings' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings} WHERE status = 'confirmed'"),
			'pending_changes' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$changes} WHERE status = 'pending'"),
		];
	}

	public static function assets(string $hook): void {
		if (false === strpos($hook, 'ridefleet-ai-chatbot')) {
			return;
		}

		wp_enqueue_style('rfac-admin', RFAC_PLUGIN_URL . 'assets/admin/admin.css', [], RFAC_VERSION);
		wp_enqueue_script('rfac-admin', RFAC_PLUGIN_URL . 'assets/admin/admin.js', [], RFAC_VERSION, true);
		wp_localize_script(
			'rfac-admin',
			'rfacAdmin',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('rfac_admin'),
			]
		);
	}

	public static function save(): void {
		if (empty($_POST['rfac_settings_nonce']) || !current_user_can('manage_options')) {
			return;
		}

		if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfac_settings_nonce'])), 'rfac_save_settings')) {
			return;
		}

		$key = sanitize_text_field(wp_unslash($_POST['openrouter_key'] ?? ''));
		$client = new OpenRouterClient();
		if ('' !== $key && !$client->valid_key($key)) {
			add_settings_error('rfac_settings', 'rfac_invalid_key', __('OpenRouter keys must start with sk-or-v1-.', 'ridefleet-ai-chatbot'));
			return;
		}

		$notification_email = sanitize_email(wp_unslash($_POST['notification_email'] ?? ''));
		if ('' !== $notification_email && !is_email($notification_email)) {
			$notification_email = '';
		}

		Options::update(
			[
				'openrouter_key' => $key,
				'selected_model' => sanitize_text_field(wp_unslash($_POST['selected_model'] ?? 'openai/gpt-4o-mini')),
				'company_bio' => sanitize_textarea_field(wp_unslash($_POST['company_bio'] ?? '')),
				'core_plugin_api_url' => esc_url_raw(wp_unslash($_POST['core_plugin_api_url'] ?? home_url())),
				'core_plugin_api_key' => sanitize_text_field(wp_unslash($_POST['core_plugin_api_key'] ?? '')),
				'dispatch_contact_number' => sanitize_text_field(wp_unslash($_POST['dispatch_contact_number'] ?? '')),
				'max_price_negotiation_discount' => min(40, max(1, (float) wp_unslash($_POST['max_price_negotiation_discount'] ?? 20))),
				'data_retention_days' => min(730, max(7, (int) wp_unslash($_POST['data_retention_days'] ?? 90))),
				'notification_email' => $notification_email,
				'notifications_enabled' => !empty($_POST['notifications_enabled']) ? 1 : 0,
				'chatbot_ui_theme' => [
					'primary' => sanitize_hex_color(wp_unslash($_POST['theme_primary'] ?? '#0f766e')) ?: '#0f766e',
					'primary_dark' => sanitize_hex_color(wp_unslash($_POST['theme_primary_dark'] ?? '#0b5f59')) ?: '#0b5f59',
					'surface' => sanitize_hex_color(wp_unslash($_POST['theme_surface'] ?? '#ffffff')) ?: '#ffffff',
					'text' => sanitize_hex_color(wp_unslash($_POST['theme_text'] ?? '#17202a')) ?: '#17202a',
					'muted' => sanitize_hex_color(wp_unslash($_POST['theme_muted'] ?? '#64748b')) ?: '#64748b',
				],
			]
		);

		add_settings_error('rfac_settings', 'rfac_saved', __('Chatbot settings saved.', 'ridefleet-ai-chatbot'), 'updated');
	}

	public static function ajax_test_openrouter(): void {
		check_ajax_referer('rfac_admin');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Not allowed.', 'ridefleet-ai-chatbot'));
		}

		$key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
		if ('' === $key) {
			$key = (string) Options::get('openrouter_key', '');
		}

		$client = new OpenRouterClient();
		if (!$client->valid_key($key)) {
			wp_send_json_error(__('Invalid key format. Must start with sk-or-v1-.', 'ridefleet-ai-chatbot'));
		}

		// Override stored key temporarily for this test by passing through Options.
		$previous = Options::get('openrouter_key', '');
		Options::update(['openrouter_key' => $key]);
		$result = $client->classify_turn('hello', ['state' => 'greeting', 'collected_data' => []]);
		Options::update(['openrouter_key' => $previous]);

		if (!empty($result['success'])) {
			$model = (string) Options::get('selected_model', 'openai/gpt-4o-mini');
			wp_send_json_success(sprintf(__('Connected. Model %s responded.', 'ridefleet-ai-chatbot'), $model));
		}

		wp_send_json_error($result['message'] ?? __('Connection failed.', 'ridefleet-ai-chatbot'));
	}

	public static function ajax_test_core_api(): void {
		check_ajax_referer('rfac_admin');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Not allowed.', 'ridefleet-ai-chatbot'));
		}

		$client = new CoreApiClient();
		$result = $client->get_vehicles(1, 0);

		if (!empty($result['success'])) {
			$count = is_array($result['vehicles'] ?? null) ? count($result['vehicles']) : 0;
			wp_send_json_success(sprintf(_n('Connected. %d vehicle available.', 'Connected. %d vehicles available.', $count, 'ridefleet-ai-chatbot'), $count));
		}

		wp_send_json_error($result['message'] ?? __('Core API is unreachable. Check the URL and shared key.', 'ridefleet-ai-chatbot'));
	}

	public static function ajax_rewrite_bio(): void {
		check_ajax_referer('rfac_admin');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('Not allowed.', 'ridefleet-ai-chatbot'));
		}

		$text = sanitize_textarea_field(wp_unslash($_POST['text'] ?? ''));
		$mode = sanitize_key((string) ($_POST['mode'] ?? 'concise'));

		$instructions = [
			'concise' => 'Rewrite this taxi company bio to be concise and factual. Keep every concrete fact (services, fleet, hours, service area, policies, contact info). Remove fluff, marketing adjectives, and repetition. Use clear professional language and short sentences. Return strict JSON only with key "rewritten".',
			'professional' => 'Rewrite this taxi company bio in a professional, neutral business tone. Keep every concrete fact. Remove slang, exclamation marks, and salesy phrasing. Return strict JSON only with key "rewritten".',
			'friendly' => 'Rewrite this taxi company bio in a warm, friendly customer-facing tone while staying factual. Keep every concrete fact. Avoid emojis. Return strict JSON only with key "rewritten".',
			'grammar' => 'Fix only grammar, spelling, and punctuation in this taxi company bio. Do not change facts, tone, structure, or length. Return strict JSON only with key "rewritten".',
		];

		$instruction = $instructions[$mode] ?? $instructions['concise'];
		$client = new OpenRouterClient();
		$result = $client->rewrite_text($text, $instruction);

		if (!empty($result['success'])) {
			wp_send_json_success(['rewritten' => (string) $result['rewritten']]);
		}

		wp_send_json_error($result['message'] ?? __('Rewrite failed.', 'ridefleet-ai-chatbot'));
	}

	public static function render(): void {
		$options = Options::all();
		$theme = is_array($options['chatbot_ui_theme'] ?? null) ? $options['chatbot_ui_theme'] : [];
		$stats = self::stats();
		?>
		<div class="wrap rfac-admin">
			<h1><?php esc_html_e('AI Chatbot Connector', 'ridefleet-ai-chatbot'); ?></h1>
			<?php settings_errors('rfac_settings'); ?>

			<div class="rfac-stats">
				<div class="rfac-stat">
					<span class="rfac-stat-label"><?php esc_html_e('Total sessions', 'ridefleet-ai-chatbot'); ?></span>
					<span class="rfac-stat-value"><?php echo esc_html(number_format_i18n($stats['total_sessions'])); ?></span>
				</div>
				<div class="rfac-stat">
					<span class="rfac-stat-label"><?php esc_html_e('Sessions today', 'ridefleet-ai-chatbot'); ?></span>
					<span class="rfac-stat-value"><?php echo esc_html(number_format_i18n($stats['sessions_today'])); ?></span>
				</div>
				<div class="rfac-stat">
					<span class="rfac-stat-label"><?php esc_html_e('Confirmed bookings', 'ridefleet-ai-chatbot'); ?></span>
					<span class="rfac-stat-value"><?php echo esc_html(number_format_i18n($stats['total_bookings'])); ?></span>
				</div>
				<div class="rfac-stat <?php echo $stats['pending_changes'] > 0 ? 'rfac-stat-pending' : ''; ?>">
					<span class="rfac-stat-label"><?php esc_html_e('Pending requests', 'ridefleet-ai-chatbot'); ?></span>
					<span class="rfac-stat-value"><?php echo esc_html(number_format_i18n($stats['pending_changes'])); ?></span>
				</div>
			</div>

			<form method="post" class="rfac-shell">
				<?php wp_nonce_field('rfac_save_settings', 'rfac_settings_nonce'); ?>

				<section class="rfac-panel rfac-panel-wide">
					<div>
						<p class="rfac-kicker"><?php esc_html_e('OpenRouter Gateway', 'ridefleet-ai-chatbot'); ?></p>
						<h2><?php esc_html_e('Model and Credential Settings', 'ridefleet-ai-chatbot'); ?></h2>
					</div>
					<label>
						<span><?php esc_html_e('OpenRouter API Key', 'ridefleet-ai-chatbot'); ?></span>
						<input type="password" name="openrouter_key" value="<?php echo esc_attr((string) $options['openrouter_key']); ?>" placeholder="sk-or-v1-..." autocomplete="off">
					</label>
					<label>
						<span><?php esc_html_e('Selected Model', 'ridefleet-ai-chatbot'); ?></span>
						<?php
						$current_model = (string) $options['selected_model'];
						$is_custom = '' !== $current_model && !in_array($current_model, self::POPULAR_MODELS, true);
						?>
						<select id="rfac-model-picker">
							<?php foreach (self::POPULAR_MODELS as $model) : ?>
								<option value="<?php echo esc_attr($model); ?>" <?php selected($current_model, $model); ?>><?php echo esc_html($model); ?></option>
							<?php endforeach; ?>
							<option value="__custom__" <?php selected($is_custom, true); ?>><?php esc_html_e('Custom model (enter below)…', 'ridefleet-ai-chatbot'); ?></option>
						</select>
						<input type="text" name="selected_model" id="rfac-model-value" value="<?php echo esc_attr($current_model); ?>" placeholder="provider/model-slug" style="margin-top:8px;">
						<small><?php esc_html_e('Pick a popular OpenRouter model or paste any model slug.', 'ridefleet-ai-chatbot'); ?></small>
					</label>
					<div class="rfac-test-row">
						<button type="button" class="button" id="rfac-test-openrouter"><?php esc_html_e('Test OpenRouter Connection', 'ridefleet-ai-chatbot'); ?></button>
						<span id="rfac-test-openrouter-status" class="rfac-test-status"></span>
					</div>
				</section>

				<section class="rfac-panel">
					<p class="rfac-kicker"><?php esc_html_e('Core Booking API', 'ridefleet-ai-chatbot'); ?></p>
					<h2><?php esc_html_e('Read Price, Write Reservation', 'ridefleet-ai-chatbot'); ?></h2>
					<label>
						<span><?php esc_html_e('Core Plugin Base URL', 'ridefleet-ai-chatbot'); ?></span>
						<input type="url" name="core_plugin_api_url" value="<?php echo esc_attr((string) $options['core_plugin_api_url']); ?>" placeholder="https://my-taxi-site.com">
					</label>
					<label>
						<span><?php esc_html_e('Shared Chatbot API Key', 'ridefleet-ai-chatbot'); ?></span>
						<input type="password" name="core_plugin_api_key" value="<?php echo esc_attr((string) ($options['core_plugin_api_key'] ?? '')); ?>" autocomplete="off">
					</label>
					<label>
						<span><?php esc_html_e('Dispatch Contact Number', 'ridefleet-ai-chatbot'); ?></span>
						<input type="text" name="dispatch_contact_number" value="<?php echo esc_attr((string) ($options['dispatch_contact_number'] ?? '')); ?>" placeholder="+32 ...">
						<small><?php esc_html_e('Shown when no vehicle/extras configuration can satisfy the requested ride.', 'ridefleet-ai-chatbot'); ?></small>
					</label>
					<div class="rfac-endpoints">
						<code>GET /wp-json/taxi-booking/v1/calculate-price</code>
						<code>POST /wp-json/taxi-booking/v1/create-booking</code>
					</div>
					<label>
						<span><?php esc_html_e('Max Fare Approval Discount (%)', 'ridefleet-ai-chatbot'); ?></span>
						<input type="number" name="max_price_negotiation_discount" value="<?php echo esc_attr((string) ($options['max_price_negotiation_discount'] ?? 20)); ?>" min="1" max="40" step="0.5">
					</label>
					<div class="rfac-test-row">
						<button type="button" class="button" id="rfac-test-core-api"><?php esc_html_e('Test Core API Connection', 'ridefleet-ai-chatbot'); ?></button>
						<span id="rfac-test-core-api-status" class="rfac-test-status"></span>
					</div>
				</section>

				<section class="rfac-panel">
					<p class="rfac-kicker"><?php esc_html_e('Guardrailed Assistant', 'ridefleet-ai-chatbot'); ?></p>
					<h2><?php esc_html_e('Company Bio and Policy Context', 'ridefleet-ai-chatbot'); ?></h2>
					<label>
						<span><?php esc_html_e('Company Bio', 'ridefleet-ai-chatbot'); ?></span>
						<textarea name="company_bio" id="rfac-company-bio" rows="10" placeholder="<?php esc_attr_e('Fleet details, service policies, FAQs, preferred tone...', 'ridefleet-ai-chatbot'); ?>"><?php echo esc_textarea((string) $options['company_bio']); ?></textarea>
						<small><?php esc_html_e('Provided to the AI as context. Keep it concise and factual.', 'ridefleet-ai-chatbot'); ?></small>
					</label>
					<div class="rfac-rewrite-bar">
						<span class="rfac-rewrite-label"><?php esc_html_e('AI rewrite:', 'ridefleet-ai-chatbot'); ?></span>
						<button type="button" class="button" data-rfac-rewrite="concise"><?php esc_html_e('Concise & factual', 'ridefleet-ai-chatbot'); ?></button>
						<button type="button" class="button" data-rfac-rewrite="professional"><?php esc_html_e('Professional tone', 'ridefleet-ai-chatbot'); ?></button>
						<button type="button" class="button" data-rfac-rewrite="friendly"><?php esc_html_e('Friendly tone', 'ridefleet-ai-chatbot'); ?></button>
						<button type="button" class="button" data-rfac-rewrite="grammar"><?php esc_html_e('Fix grammar', 'ridefleet-ai-chatbot'); ?></button>
						<span id="rfac-rewrite-status" class="rfac-test-status"></span>
					</div>
				</section>

				<section class="rfac-panel rfac-panel-wide">
					<p class="rfac-kicker"><?php esc_html_e('Operations', 'ridefleet-ai-chatbot'); ?></p>
					<h2><?php esc_html_e('Notifications and Retention', 'ridefleet-ai-chatbot'); ?></h2>
					<div class="rfac-shell" style="grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 0;">
						<label style="margin: 0;">
							<span><?php esc_html_e('Notification email (optional)', 'ridefleet-ai-chatbot'); ?></span>
							<input type="email" name="notification_email" value="<?php echo esc_attr((string) ($options['notification_email'] ?? '')); ?>" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
							<small><?php esc_html_e('Leave blank to use the WordPress admin email.', 'ridefleet-ai-chatbot'); ?></small>
						</label>
						<label style="margin: 0;">
							<span><?php esc_html_e('Session retention (days)', 'ridefleet-ai-chatbot'); ?></span>
							<input type="number" name="data_retention_days" value="<?php echo esc_attr((string) ($options['data_retention_days'] ?? 90)); ?>" min="7" max="730" step="1">
							<small><?php esc_html_e('Conversations older than this are deleted by a daily cleanup job.', 'ridefleet-ai-chatbot'); ?></small>
						</label>
					</div>
					<label style="margin-top: 14px;">
						<input type="checkbox" name="notifications_enabled" value="1" <?php checked(!empty($options['notifications_enabled'])); ?>>
						<span style="display: inline; font-weight: 600;"><?php esc_html_e('Email me when a new change request or fare negotiation arrives', 'ridefleet-ai-chatbot'); ?></span>
					</label>
				</section>

				<section class="rfac-panel rfac-panel-wide">
					<p class="rfac-kicker"><?php esc_html_e('Frontend Widget', 'ridefleet-ai-chatbot'); ?></p>
					<h2><?php esc_html_e('Chat Theme', 'ridefleet-ai-chatbot'); ?></h2>
					<div class="rfac-color-grid">
						<?php
						$colors = [
							'theme_primary' => [__('Primary', 'ridefleet-ai-chatbot'), $theme['primary'] ?? '#0f766e'],
							'theme_primary_dark' => [__('Primary Dark', 'ridefleet-ai-chatbot'), $theme['primary_dark'] ?? '#0b5f59'],
							'theme_surface' => [__('Surface', 'ridefleet-ai-chatbot'), $theme['surface'] ?? '#ffffff'],
							'theme_text' => [__('Text', 'ridefleet-ai-chatbot'), $theme['text'] ?? '#17202a'],
							'theme_muted' => [__('Muted', 'ridefleet-ai-chatbot'), $theme['muted'] ?? '#64748b'],
						];
						foreach ($colors as $name => [$label, $value]) :
							?>
							<label>
								<span><?php echo esc_html($label); ?></span>
								<input type="color" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
							</label>
						<?php endforeach; ?>
					</div>
					<div class="rfac-theme-preview" style="--rfac-primary:<?php echo esc_attr($theme['primary'] ?? '#0f766e'); ?>;--rfac-primary-dark:<?php echo esc_attr($theme['primary_dark'] ?? '#0b5f59'); ?>;--rfac-surface:<?php echo esc_attr($theme['surface'] ?? '#ffffff'); ?>;--rfac-text:<?php echo esc_attr($theme['text'] ?? '#17202a'); ?>;--rfac-muted:<?php echo esc_attr($theme['muted'] ?? '#64748b'); ?>;">
						<div class="rfac-theme-preview-header">
							<span><?php esc_html_e('Live preview', 'ridefleet-ai-chatbot'); ?></span>
							<span style="font-size:12px; opacity:0.85;"><?php esc_html_e('Adjust colors to see changes', 'ridefleet-ai-chatbot'); ?></span>
						</div>
						<div class="rfac-theme-preview-body">
							<div class="rfac-preview-bubble"><?php esc_html_e('Hi! Where should we pick you up?', 'ridefleet-ai-chatbot'); ?></div>
							<div class="rfac-preview-bubble is-user"><?php esc_html_e('Brussels airport, please', 'ridefleet-ai-chatbot'); ?></div>
							<div class="rfac-preview-bubble"><?php esc_html_e('Got it. And where are you heading?', 'ridefleet-ai-chatbot'); ?></div>
						</div>
					</div>
				</section>

				<section class="rfac-panel rfac-panel-wide">
					<h2><?php esc_html_e('Embed', 'ridefleet-ai-chatbot'); ?></h2>
					<p><?php esc_html_e('Place this shortcode on any customer-facing page:', 'ridefleet-ai-chatbot'); ?></p>
					<code>[ridefleet_ai_chatbot]</code>
				</section>

				<p class="submit">
					<button class="button button-primary" type="submit"><?php esc_html_e('Save Chatbot Settings', 'ridefleet-ai-chatbot'); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
