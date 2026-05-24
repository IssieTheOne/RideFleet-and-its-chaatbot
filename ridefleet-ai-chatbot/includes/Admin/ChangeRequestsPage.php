<?php
/**
 * Booking change request admin page.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class ChangeRequestsPage {
	public static function handle_quick_action(): void {
		$action = sanitize_key(wp_unslash($_GET['rfac_quick_action'] ?? ''));
		if (!in_array($action, ['approve', 'reject'], true)) {
			return;
		}
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Access denied.', 'ridefleet-ai-chatbot'));
		}
		$id    = absint($_GET['id'] ?? 0);
		$nonce = sanitize_key(wp_unslash($_GET['_wpnonce'] ?? ''));
		if (!wp_verify_nonce($nonce, 'rfac_quick_action_' . $id)) {
			wp_die(esc_html__('Security check failed.', 'ridefleet-ai-chatbot'));
		}
		global $wpdb;
		$table = $wpdb->prefix . 'rfac_change_requests';
		$status = 'approve' === $action ? 'approved' : 'rejected';
		$wpdb->update(
			$table,
			['status' => $status, 'resolved_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
			['id' => $id],
			['%s', '%s', '%s'],
			['%d']
		);
		// Send customer notification
		self::notify_customer($id, $status);
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-ai-chatbot-changes&updated=1&status_filter=' . $status));
		exit;
	}

	private static function notify_customer(int $request_id, string $status): void {
		// Customer email is not stored — skip silent. Could be extended when email capture is added to the flow.
		// For now this is a hook for future extension.
	}

	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to view change requests.', 'ridefleet-ai-chatbot'));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rfac_change_requests';
		$status_filter = sanitize_key((string) ($_GET['status_filter'] ?? 'pending'));
		$valid_statuses = ['all', 'pending', 'approved', 'rejected', 'counteroffer'];
		if (!in_array($status_filter, $valid_statuses, true)) {
			$status_filter = 'pending';
		}

		$counts = $wpdb->get_results("SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A);
		$count_map = [];
		foreach ((array) $counts as $row) {
			$count_map[(string) $row['status']] = (int) $row['c'];
		}
		$total = array_sum($count_map);

		if ('all' === $status_filter) {
			$requests = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A);
		} else {
			$requests = $wpdb->get_results(
				$wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT 200", $status_filter),
				ARRAY_A
			);
		}

		$base_url = admin_url('admin.php?page=ridefleet-ai-chatbot-changes');
		?>
		<div class="wrap rfac-admin">
			<div class="rfac-hero">
				<div>
					<p class="rfac-kicker"><?php esc_html_e('RideFleet AI Chatbot', 'ridefleet-ai-chatbot'); ?></p>
					<h1>
						<?php esc_html_e('Change Requests', 'ridefleet-ai-chatbot'); ?>
						<?php if ($total > 0): ?><span class="rfac-hero-count"><?php echo esc_html(number_format_i18n($total)); ?></span><?php endif; ?>
					</h1>
					<p><?php esc_html_e('Review customer booking modification and fare negotiation requests. Approve, reject, or counteroffer — responses are relayed back through the chatbot.', 'ridefleet-ai-chatbot'); ?></p>
				</div>
				<div class="rfac-hero-actions">
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot')); ?>" class="button">📊 <?php esc_html_e('Dashboard', 'ridefleet-ai-chatbot'); ?></a>
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot-settings')); ?>" class="button">⚙️ <?php esc_html_e('Settings', 'ridefleet-ai-chatbot'); ?></a>
				</div>
			</div>

			<?php if (!empty($_GET['updated'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Change request updated.', 'ridefleet-ai-chatbot'); ?></p></div>
			<?php endif; ?>

			<nav class="rfac-status-tabs">
				<?php
				$tabs = [
					'pending' => __('Pending', 'ridefleet-ai-chatbot'),
					'approved' => __('Approved', 'ridefleet-ai-chatbot'),
					'rejected' => __('Rejected', 'ridefleet-ai-chatbot'),
					'counteroffer' => __('Counteroffer', 'ridefleet-ai-chatbot'),
					'all' => __('All', 'ridefleet-ai-chatbot'),
				];
				foreach ($tabs as $key => $label) :
					$count = 'all' === $key ? $total : ($count_map[$key] ?? 0);
					?>
					<a href="<?php echo esc_url(add_query_arg(['status_filter' => $key], $base_url)); ?>"
						class="rfac-status-tab rfac-status-tab-<?php echo esc_attr($key); ?> <?php echo $status_filter === $key ? 'is-active' : ''; ?>">
						<span><?php echo esc_html($label); ?></span>
						<b><?php echo esc_html(number_format_i18n($count)); ?></b>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="rfac-request-grid">
				<?php foreach ((array) $requests as $req) :
					$is_price = 'price_negotiation' === (string) ($req['request_type'] ?? '');
					$status = (string) $req['status'];
					?>
					<article class="rfac-request-card rfac-request-status-<?php echo esc_attr($status); ?>">
						<header class="rfac-request-head">
							<div>
								<span class="rfac-request-type-pill rfac-request-type-<?php echo esc_attr($is_price ? 'price' : 'change'); ?>">
									<?php echo $is_price ? esc_html__('Fare negotiation', 'ridefleet-ai-chatbot') : esc_html__('Booking change', 'ridefleet-ai-chatbot'); ?>
								</span>
								<span class="rfac-status-pill is-<?php echo esc_attr($status); ?>"><?php echo esc_html(str_replace('_', ' ', $status)); ?></span>
							</div>
							<time class="rfac-request-time" title="<?php echo esc_attr((string) $req['created_at']); ?>">
								<?php echo esc_html(human_time_diff(strtotime((string) $req['created_at']), current_time('U'))); ?> <?php esc_html_e('ago', 'ridefleet-ai-chatbot'); ?>
							</time>
						</header>

						<div class="rfac-request-body">
							<div class="rfac-request-customer">
								<strong><?php echo esc_html((string) $req['customer_name'] ?: __('(no name)', 'ridefleet-ai-chatbot')); ?></strong>
								<?php if ($req['customer_phone']) : ?>
									<a href="tel:<?php echo esc_attr((string) $req['customer_phone']); ?>"><?php echo esc_html((string) $req['customer_phone']); ?></a>
								<?php endif; ?>
								<?php if ($req['core_booking_id']) : ?>
									<span class="rfac-booking-id">#<?php echo esc_html((string) $req['core_booking_id']); ?></span>
								<?php endif; ?>
							</div>

							<?php if ($is_price) : ?>
								<div class="rfac-price-block">
									<div>
										<span class="rfac-price-label"><?php esc_html_e('Original', 'ridefleet-ai-chatbot'); ?></span>
										<span class="rfac-price-original"><?php echo esc_html((string) ($req['currency'] ?? 'USD')); ?> <?php echo esc_html(number_format((float) ($req['original_price'] ?? 0), 2)); ?></span>
									</div>
									<span class="rfac-price-arrow">→</span>
									<div>
										<span class="rfac-price-label"><?php esc_html_e('Proposed', 'ridefleet-ai-chatbot'); ?></span>
										<span class="rfac-price-proposed"><?php echo esc_html((string) ($req['currency'] ?? 'USD')); ?> <?php echo esc_html(number_format((float) ($req['proposed_price'] ?? 0), 2)); ?></span>
									</div>
									<div class="rfac-discount-badge">−<?php echo esc_html(number_format((float) ($req['discount_percent'] ?? 0), 1)); ?>%</div>
								</div>
							<?php endif; ?>

							<div class="rfac-request-text"><?php echo nl2br(esc_html((string) $req['request_text'])); ?></div>

							<?php if (!empty($req['customer_response'])) : ?>
								<div class="rfac-request-response">
									<strong><?php esc_html_e('Customer-facing reply:', 'ridefleet-ai-chatbot'); ?></strong>
									<?php echo esc_html((string) $req['customer_response']); ?>
								</div>
							<?php endif; ?>

							<?php if (!empty($req['admin_note'])) : ?>
								<div class="rfac-request-note">
									<strong><?php esc_html_e('Internal note:', 'ridefleet-ai-chatbot'); ?></strong>
									<?php echo esc_html((string) $req['admin_note']); ?>
								</div>
							<?php endif; ?>
						</div>

						<details class="rfac-request-actions">
							<summary>
								<?php echo 'pending' === $status ? esc_html__('Respond / resolve', 'ridefleet-ai-chatbot') : esc_html__('Update or reopen', 'ridefleet-ai-chatbot'); ?>
							</summary>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rfac-resolution-form">
								<input type="hidden" name="action" value="rfac_update_change_request">
								<input type="hidden" name="request_id" value="<?php echo esc_attr((string) $req['id']); ?>">
								<?php wp_nonce_field('rfac_update_change_request_' . (int) $req['id']); ?>

								<?php if ($is_price) : ?>
									<label>
										<span><?php esc_html_e('Counteroffer fare', 'ridefleet-ai-chatbot'); ?></span>
										<input type="number" name="counteroffer_price" min="0" step="0.01" placeholder="<?php esc_attr_e('e.g. 95.00', 'ridefleet-ai-chatbot'); ?>" value="<?php echo esc_attr((string) ($req['counteroffer_price'] ?? '')); ?>">
									</label>
								<?php endif; ?>

								<label>
									<span><?php esc_html_e('Customer-facing reply', 'ridefleet-ai-chatbot'); ?></span>
									<textarea name="customer_response" rows="2" placeholder="<?php esc_attr_e('What the customer should see (sent through chat)', 'ridefleet-ai-chatbot'); ?>"><?php echo esc_textarea((string) ($req['customer_response'] ?? '')); ?></textarea>
								</label>

								<label>
									<span><?php esc_html_e('Internal note', 'ridefleet-ai-chatbot'); ?></span>
									<textarea name="admin_note" rows="2" placeholder="<?php esc_attr_e('Only visible to admins', 'ridefleet-ai-chatbot'); ?>"><?php echo esc_textarea((string) ($req['admin_note'] ?? '')); ?></textarea>
								</label>

								<div class="rfac-action-buttons">
									<button class="button button-primary" name="status" value="approved" type="submit"><?php esc_html_e('Approve', 'ridefleet-ai-chatbot'); ?></button>
									<?php if ($is_price) : ?>
										<button class="button" name="status" value="counteroffer" type="submit"><?php esc_html_e('Counteroffer', 'ridefleet-ai-chatbot'); ?></button>
									<?php endif; ?>
									<button class="button" name="status" value="rejected" type="submit"><?php esc_html_e('Reject', 'ridefleet-ai-chatbot'); ?></button>
									<?php if ('pending' !== $status) : ?>
										<button class="button button-link" name="status" value="pending" type="submit"><?php esc_html_e('Reopen', 'ridefleet-ai-chatbot'); ?></button>
									<?php endif; ?>
								</div>
							</form>
						</details>
					</article>
				<?php endforeach; ?>

				<?php if (!$requests) : ?>
					<div class="rfac-empty-state">
						<p style="font-size:16px; color:#0f766e; margin:0 0 6px; font-weight:700;">
							<?php
							switch ($status_filter) {
								case 'pending':
									esc_html_e('No pending requests. ', 'ridefleet-ai-chatbot');
									break;
								case 'approved':
									esc_html_e('No approved requests yet.', 'ridefleet-ai-chatbot');
									break;
								case 'rejected':
									esc_html_e('No rejected requests.', 'ridefleet-ai-chatbot');
									break;
								case 'counteroffer':
									esc_html_e('No counteroffers in flight.', 'ridefleet-ai-chatbot');
									break;
								default:
									esc_html_e('No change requests yet.', 'ridefleet-ai-chatbot');
							}
							?>
						</p>
						<p style="color:#64748b; margin:0;">
							<?php esc_html_e('When customers ask to modify a booking or negotiate a fare through the chatbot, their requests will show up here.', 'ridefleet-ai-chatbot'); ?>
						</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function update(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to update change requests.', 'ridefleet-ai-chatbot'));
		}

		$request_id = absint($_POST['request_id'] ?? 0);
		check_admin_referer('rfac_update_change_request_' . $request_id);
		$status = sanitize_key((string) ($_POST['status'] ?? 'pending'));
		if (!in_array($status, ['approved', 'rejected', 'pending', 'counteroffer'], true)) {
			$status = 'pending';
		}
		$resolved_at = 'pending' === $status ? null : current_time('mysql');

		global $wpdb;
		$data = [
			'status' => $status,
			'customer_response' => sanitize_textarea_field(wp_unslash($_POST['customer_response'] ?? '')),
			'counteroffer_price' => max(0, (float) wp_unslash($_POST['counteroffer_price'] ?? 0)),
			'admin_note' => sanitize_textarea_field(wp_unslash($_POST['admin_note'] ?? '')),
			'updated_at' => current_time('mysql'),
			'resolved_at' => $resolved_at,
		];
		$formats = ['%s', '%s', '%f', '%s', '%s', '%s'];

		$wpdb->update($wpdb->prefix . 'rfac_change_requests', $data, ['id' => $request_id], $formats, ['%d']);

		wp_safe_redirect(admin_url('admin.php?page=ridefleet-ai-chatbot-changes&updated=1&status_filter=' . urlencode(sanitize_key((string) ($_GET['status_filter'] ?? $status)))));
		exit;
	}
}
