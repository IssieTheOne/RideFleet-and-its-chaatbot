<?php
/**
 * GDPR / data privacy admin page.
 *
 * Lets admins look up a customer's footprint across the chatbot tables and
 * erase it on request. Mirrors the "right to be forgotten" obligation under
 * GDPR Art. 17.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

use RideFleetAIChatbot\Support\Logger;

if (!defined('ABSPATH')) {
	exit;
}

final class PrivacyPage {
	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to access privacy tools.', 'ridefleet-ai-chatbot'));
		}

		$query = sanitize_text_field((string) ($_GET['q'] ?? ''));
		$matches = '' !== $query ? self::search($query) : [];
		?>
		<div class="wrap rfac-admin">
			<h1><?php esc_html_e('Data Privacy', 'ridefleet-ai-chatbot'); ?></h1>

			<?php if (!empty($_GET['erased'])) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php printf(
						esc_html__('Erased %1$d sessions, %2$d messages, %3$d booking events, and %4$d change requests.', 'ridefleet-ai-chatbot'),
						(int) ($_GET['e_s'] ?? 0),
						(int) ($_GET['e_m'] ?? 0),
						(int) ($_GET['e_b'] ?? 0),
						(int) ($_GET['e_c'] ?? 0)
					); ?></p>
				</div>
			<?php endif; ?>

			<section class="rfac-panel rfac-panel-wide">
				<p class="rfac-kicker"><?php esc_html_e('GDPR Right-to-be-forgotten', 'ridefleet-ai-chatbot'); ?></p>
				<h2><?php esc_html_e('Find and erase customer data', 'ridefleet-ai-chatbot'); ?></h2>
				<p style="color:#64748b;margin-top:0;"><?php esc_html_e('Search by phone number, customer name, booking ID, or part of a message. All matches are previewed before anything is deleted.', 'ridefleet-ai-chatbot'); ?></p>

				<form method="get">
					<input type="hidden" name="page" value="ridefleet-ai-chatbot-privacy">
					<div class="rfac-filter-row">
						<input type="text" name="q" value="<?php echo esc_attr($query); ?>" placeholder="<?php esc_attr_e('+32 4xx xx xx xx, John Doe, RFB-20260512-…', 'ridefleet-ai-chatbot'); ?>" class="rfac-search">
						<button type="submit" class="button button-primary"><?php esc_html_e('Search', 'ridefleet-ai-chatbot'); ?></button>
					</div>
				</form>

				<?php if ('' !== $query) : ?>
					<?php if ($matches['session_ids']) : ?>
						<div class="rfac-privacy-match">
							<h3 style="margin:0 0 6px;">
								<?php printf(esc_html__('Matched %d conversation(s)', 'ridefleet-ai-chatbot'), count($matches['session_ids'])); ?>
							</h3>
							<ul style="margin:0 0 14px;padding-left:20px;">
								<?php foreach ($matches['previews'] as $preview) : ?>
									<li><?php echo esc_html($preview); ?></li>
								<?php endforeach; ?>
							</ul>

							<div class="rfac-privacy-summary">
								<span><strong><?php echo esc_html(number_format_i18n((int) $matches['count_sessions'])); ?></strong> <?php esc_html_e('sessions', 'ridefleet-ai-chatbot'); ?></span>
								<span><strong><?php echo esc_html(number_format_i18n((int) $matches['count_messages'])); ?></strong> <?php esc_html_e('messages', 'ridefleet-ai-chatbot'); ?></span>
								<span><strong><?php echo esc_html(number_format_i18n((int) $matches['count_bookings'])); ?></strong> <?php esc_html_e('booking events', 'ridefleet-ai-chatbot'); ?></span>
								<span><strong><?php echo esc_html(number_format_i18n((int) $matches['count_changes'])); ?></strong> <?php esc_html_e('change requests', 'ridefleet-ai-chatbot'); ?></span>
							</div>

							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
								<input type="hidden" name="action" value="rfac_erase_customer">
								<input type="hidden" name="q" value="<?php echo esc_attr($query); ?>">
								<?php wp_nonce_field('rfac_erase_customer'); ?>
								<label style="margin:0 0 12px;">
									<input type="checkbox" name="confirm" value="1" required>
									<span style="display:inline;font-weight:600;color:#b91c1c;"><?php esc_html_e('I confirm this is permanent and cannot be undone.', 'ridefleet-ai-chatbot'); ?></span>
								</label>
								<button class="button button-primary" style="background:#dc2626;border-color:#b91c1c;" type="submit"><?php esc_html_e('Erase all matching data', 'ridefleet-ai-chatbot'); ?></button>
							</form>
						</div>
					<?php else : ?>
						<p style="color:#64748b;margin-top:14px;"><?php esc_html_e('No matches.', 'ridefleet-ai-chatbot'); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<section class="rfac-panel rfac-panel-wide" style="margin-top:14px;">
				<h2><?php esc_html_e('Data retention', 'ridefleet-ai-chatbot'); ?></h2>
				<p style="color:#475569;">
					<?php esc_html_e('A daily cleanup job removes chat sessions older than the configured retention window.', 'ridefleet-ai-chatbot'); ?>
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot')); ?>"><?php esc_html_e('Adjust retention in Settings →', 'ridefleet-ai-chatbot'); ?></a>
				</p>
			</section>
		</div>
		<?php
	}

	public static function search(string $query): array {
		global $wpdb;
		$sessions = $wpdb->prefix . 'rfac_chat_sessions';
		$messages = $wpdb->prefix . 'rfac_chat_messages';
		$bookings = $wpdb->prefix . 'rfac_booking_events';
		$changes = $wpdb->prefix . 'rfac_change_requests';

		$like = '%' . $wpdb->esc_like($query) . '%';

		$session_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT s.id FROM {$sessions} s
				 LEFT JOIN {$messages} m ON m.session_id = s.id
				 LEFT JOIN {$bookings} b ON b.session_id = s.id
				 LEFT JOIN {$changes} c ON c.session_id = s.id
				 WHERE s.collected_data LIKE %s
				    OR m.message LIKE %s
				    OR b.customer_name LIKE %s OR b.customer_phone LIKE %s OR b.core_booking_id LIKE %s
				    OR c.customer_name LIKE %s OR c.customer_phone LIKE %s OR c.core_booking_id LIKE %s
				 LIMIT 200",
				$like, $like, $like, $like, $like, $like, $like, $like
			)
		);
		$session_ids = array_map('absint', (array) $session_ids);

		if (!$session_ids) {
			return [
				'session_ids' => [],
				'count_sessions' => 0,
				'count_messages' => 0,
				'count_bookings' => 0,
				'count_changes' => 0,
				'previews' => [],
			];
		}

		$placeholders = implode(',', array_fill(0, count($session_ids), '%d'));

		$count_messages = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$messages} WHERE session_id IN ({$placeholders})",
			...$session_ids
		));
		$count_bookings = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$bookings} WHERE session_id IN ({$placeholders})",
			...$session_ids
		));
		$count_changes = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$changes} WHERE session_id IN ({$placeholders})",
			...$session_ids
		));

		$preview_rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, session_key, state, updated_at, collected_data FROM {$sessions}
			 WHERE id IN ({$placeholders}) ORDER BY updated_at DESC LIMIT 10",
			...$session_ids
		), ARRAY_A);
		$previews = [];
		foreach ((array) $preview_rows as $row) {
			$coll = json_decode((string) ($row['collected_data'] ?? '{}'), true);
			$coll = is_array($coll) ? $coll : [];
			$bits = [
				substr((string) $row['session_key'], 0, 18) . '…',
				(string) ($coll['customer_name'] ?? '—'),
				(string) ($coll['customer_phone'] ?? '—'),
				'state: ' . (string) $row['state'],
				(string) $row['updated_at'],
			];
			$previews[] = implode(' · ', array_filter($bits));
		}

		return [
			'session_ids' => $session_ids,
			'count_sessions' => count($session_ids),
			'count_messages' => $count_messages,
			'count_bookings' => $count_bookings,
			'count_changes' => $count_changes,
			'previews' => $previews,
		];
	}

	public static function erase(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'ridefleet-ai-chatbot'));
		}
		check_admin_referer('rfac_erase_customer');
		if (empty($_POST['confirm'])) {
			wp_die(esc_html__('Confirmation required.', 'ridefleet-ai-chatbot'));
		}

		$query = sanitize_text_field((string) ($_POST['q'] ?? ''));
		$match = self::search($query);
		$session_ids = $match['session_ids'];

		if (!$session_ids) {
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-ai-chatbot-privacy&q=' . urlencode($query)));
			exit;
		}

		global $wpdb;
		$placeholders = implode(',', array_fill(0, count($session_ids), '%d'));

		$deleted_m = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}rfac_chat_messages WHERE session_id IN ({$placeholders})", ...$session_ids));
		$deleted_b = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}rfac_booking_events WHERE session_id IN ({$placeholders})", ...$session_ids));
		$deleted_c = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}rfac_change_requests WHERE session_id IN ({$placeholders})", ...$session_ids));
		$deleted_s = (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}rfac_chat_sessions WHERE id IN ({$placeholders})", ...$session_ids));

		Logger::info('privacy', 'Customer data erased via admin', [
			'query_term' => substr($query, 0, 24) . '…',
			'sessions' => $deleted_s,
			'messages' => $deleted_m,
			'bookings' => $deleted_b,
			'changes' => $deleted_c,
		]);

		wp_safe_redirect(add_query_arg([
			'page' => 'ridefleet-ai-chatbot-privacy',
			'erased' => 1,
			'e_s' => $deleted_s,
			'e_m' => $deleted_m,
			'e_b' => $deleted_b,
			'e_c' => $deleted_c,
		], admin_url('admin.php')));
		exit;
	}
}
