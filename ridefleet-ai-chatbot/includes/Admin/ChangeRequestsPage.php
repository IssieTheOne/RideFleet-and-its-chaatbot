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
	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to view change requests.', 'ridefleet-ai-chatbot'));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rfac_change_requests';
		$requests = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100", ARRAY_A);
		?>
		<div class="wrap rfac-admin">
			<h1><?php esc_html_e('Booking Change Requests', 'ridefleet-ai-chatbot'); ?></h1>
			<?php if (!empty($_GET['updated'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Change request updated.', 'ridefleet-ai-chatbot'); ?></p></div>
			<?php endif; ?>
			<section class="rfac-panel">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Booking', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Customer', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Type', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Fare', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Request', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Status', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Resolution', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Created', 'ridefleet-ai-chatbot'); ?></th>
							<th><?php esc_html_e('Actions', 'ridefleet-ai-chatbot'); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ((array) $requests as $request) : ?>
						<tr>
							<td><?php echo esc_html((string) $request['core_booking_id']); ?></td>
							<td>
								<strong><?php echo esc_html((string) $request['customer_name']); ?></strong><br>
								<?php echo esc_html((string) $request['customer_phone']); ?>
							</td>
							<td><span class="rfac-status-pill"><?php echo esc_html(str_replace('_', ' ', (string) ($request['request_type'] ?? 'booking_change'))); ?></span></td>
							<td>
								<?php if ('price_negotiation' === (string) ($request['request_type'] ?? '')) : ?>
									<?php echo esc_html((string) ($request['currency'] ?? 'USD')); ?>
									<?php echo esc_html(number_format((float) ($request['original_price'] ?? 0), 2)); ?>
									&rarr;
									<strong><?php echo esc_html(number_format((float) ($request['proposed_price'] ?? 0), 2)); ?></strong><br>
									<?php echo esc_html(number_format((float) ($request['discount_percent'] ?? 0), 2)); ?>% <?php esc_html_e('lower', 'ridefleet-ai-chatbot'); ?>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><?php echo esc_html((string) $request['request_text']); ?></td>
							<td><span class="rfac-status-pill"><?php echo esc_html((string) $request['status']); ?></span></td>
							<td>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rfac-resolution-form">
									<input type="hidden" name="action" value="rfac_update_change_request">
									<input type="hidden" name="request_id" value="<?php echo esc_attr((string) $request['id']); ?>">
									<?php wp_nonce_field('rfac_update_change_request_' . (int) $request['id']); ?>
									<?php if ('price_negotiation' === (string) ($request['request_type'] ?? '')) : ?>
										<input type="number" name="counteroffer_price" min="0" step="0.01" placeholder="<?php esc_attr_e('Counteroffer', 'ridefleet-ai-chatbot'); ?>" value="<?php echo esc_attr((string) ($request['counteroffer_price'] ?? '')); ?>">
									<?php endif; ?>
									<textarea name="customer_response" rows="2" placeholder="<?php esc_attr_e('Customer-facing note', 'ridefleet-ai-chatbot'); ?>"><?php echo esc_textarea((string) ($request['customer_response'] ?? '')); ?></textarea>
									<textarea name="admin_note" rows="2" placeholder="<?php esc_attr_e('Internal admin note', 'ridefleet-ai-chatbot'); ?>"><?php echo esc_textarea((string) ($request['admin_note'] ?? '')); ?></textarea>
									<div class="rfac-inline-actions">
										<button class="button button-small" name="status" value="approved" type="submit"><?php esc_html_e('Approve', 'ridefleet-ai-chatbot'); ?></button>
										<button class="button button-small" name="status" value="rejected" type="submit"><?php esc_html_e('Reject', 'ridefleet-ai-chatbot'); ?></button>
										<button class="button button-small" name="status" value="counteroffer" type="submit"><?php esc_html_e('Counteroffer', 'ridefleet-ai-chatbot'); ?></button>
									</div>
								</form>
							</td>
							<td><?php echo esc_html((string) $request['created_at']); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rfac-inline-actions">
									<input type="hidden" name="action" value="rfac_update_change_request">
									<input type="hidden" name="request_id" value="<?php echo esc_attr((string) $request['id']); ?>">
									<?php wp_nonce_field('rfac_update_change_request_' . (int) $request['id']); ?>
									<button class="button button-small" name="status" value="pending" type="submit"><?php esc_html_e('Reopen', 'ridefleet-ai-chatbot'); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if (!$requests) : ?>
						<tr><td colspan="9"><?php esc_html_e('No change requests yet.', 'ridefleet-ai-chatbot'); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</section>
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
		];
		$formats = ['%s', '%s', '%f', '%s', '%s'];
		if (null === $resolved_at) {
			$data['resolved_at'] = null;
			$formats[] = '%s';
		} else {
			$data['resolved_at'] = $resolved_at;
			$formats[] = '%s';
		}

		$wpdb->update($wpdb->prefix . 'rfac_change_requests', $data, ['id' => $request_id], $formats, ['%d']);

		wp_safe_redirect(admin_url('admin.php?page=ridefleet-ai-chatbot-changes&updated=1'));
		exit;
	}
}
