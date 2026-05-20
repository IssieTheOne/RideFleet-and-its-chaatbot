<?php
/**
 * Chat history admin page.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class ChatHistoryPage {
	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to view chatbot conversations.', 'ridefleet-ai-chatbot'));
		}

		global $wpdb;
		$sessions_table = $wpdb->prefix . 'rfac_chat_sessions';
		$messages_table = $wpdb->prefix . 'rfac_chat_messages';
		$session_id = absint($_GET['session_id'] ?? 0);
		$search = sanitize_text_field((string) ($_GET['s'] ?? ''));

		if ('' !== $search) {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$sessions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.* FROM {$sessions_table} s
					 LEFT JOIN {$messages_table} m ON m.session_id = s.id
					 WHERE s.session_key LIKE %s OR m.message LIKE %s
					 GROUP BY s.id ORDER BY s.updated_at DESC LIMIT 100",
					$like,
					$like
				),
				ARRAY_A
			);
		} else {
			$sessions = $wpdb->get_results("SELECT * FROM {$sessions_table} ORDER BY updated_at DESC LIMIT 50", ARRAY_A);
		}

		$messages = [];
		if ($session_id) {
			$messages = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$messages_table} WHERE session_id = %d ORDER BY id ASC", $session_id), ARRAY_A);
		}
		?>
		<div class="wrap rfac-admin">
			<h1>
				<?php esc_html_e('Chatbot Conversations', 'ridefleet-ai-chatbot'); ?>
				<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rfac_export_chat_sessions'), 'rfac_export_chat_sessions')); ?>" class="page-title-action"><?php esc_html_e('Export CSV', 'ridefleet-ai-chatbot'); ?></a>
			</h1>
			<?php if (!empty($_GET['deleted'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Conversation deleted.', 'ridefleet-ai-chatbot'); ?></p></div>
			<?php endif; ?>
			<div class="rfac-history-layout">
				<section class="rfac-panel">
					<h2><?php esc_html_e('Recent Sessions', 'ridefleet-ai-chatbot'); ?></h2>
					<div class="rfac-history-toolbar">
						<form method="get">
							<input type="hidden" name="page" value="ridefleet-ai-chatbot-history">
							<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search session key or message...', 'ridefleet-ai-chatbot'); ?>">
							<button type="submit" class="button"><?php esc_html_e('Search', 'ridefleet-ai-chatbot'); ?></button>
							<?php if ('' !== $search) : ?>
								<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot-history')); ?>" class="button"><?php esc_html_e('Clear', 'ridefleet-ai-chatbot'); ?></a>
							<?php endif; ?>
						</form>
					</div>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Session', 'ridefleet-ai-chatbot'); ?></th>
								<th><?php esc_html_e('State', 'ridefleet-ai-chatbot'); ?></th>
								<th><?php esc_html_e('Updated', 'ridefleet-ai-chatbot'); ?></th>
								<th><?php esc_html_e('Actions', 'ridefleet-ai-chatbot'); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ((array) $sessions as $session) : ?>
							<tr>
								<td><a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot-history&session_id=' . absint($session['id']))); ?>"><?php echo esc_html(substr((string) $session['session_key'], 0, 18)); ?></a></td>
								<td><span class="rfac-status-pill"><?php echo esc_html(str_replace('_', ' ', (string) $session['state'])); ?></span></td>
								<td><?php echo esc_html((string) $session['updated_at']); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
										<input type="hidden" name="action" value="rfac_delete_chat_session">
										<input type="hidden" name="session_id" value="<?php echo esc_attr((string) $session['id']); ?>">
										<?php wp_nonce_field('rfac_delete_chat_session_' . (int) $session['id']); ?>
										<button class="button button-small" type="submit" onclick="return confirm('<?php echo esc_js(__('Delete this conversation?', 'ridefleet-ai-chatbot')); ?>');"><?php esc_html_e('Delete', 'ridefleet-ai-chatbot'); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php if (!$sessions) : ?>
							<tr><td colspan="4"><?php esc_html_e('No conversations found.', 'ridefleet-ai-chatbot'); ?></td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</section>

				<section class="rfac-panel">
					<h2><?php esc_html_e('Transcript', 'ridefleet-ai-chatbot'); ?></h2>
					<div class="rfac-transcript">
					<?php foreach ((array) $messages as $message) : ?>
						<div class="rfac-transcript-message rfac-transcript-<?php echo esc_attr((string) $message['role']); ?>">
							<strong><?php echo esc_html(ucfirst((string) $message['role'])); ?></strong>
							<p><?php echo esc_html((string) $message['message']); ?></p>
							<?php if (!empty($message['admin_summary']) && $message['admin_summary'] !== $message['message']) : ?>
								<em><?php echo esc_html__('Admin summary:', 'ridefleet-ai-chatbot'); ?> <?php echo esc_html((string) $message['admin_summary']); ?></em>
							<?php endif; ?>
							<?php if (!empty($message['intent']) || !empty($message['detected_language'])) : ?>
								<small>
									<?php echo esc_html__('Intent:', 'ridefleet-ai-chatbot'); ?> <?php echo esc_html((string) ($message['intent'] ?: 'n/a')); ?>
									&middot;
									<?php echo esc_html__('Language:', 'ridefleet-ai-chatbot'); ?> <?php echo esc_html(strtoupper((string) ($message['detected_language'] ?: 'n/a'))); ?>
								</small>
							<?php endif; ?>
							<?php if (!empty($message['extracted_fields']) && '[]' !== (string) $message['extracted_fields']) : ?>
								<small><?php echo esc_html__('Fields:', 'ridefleet-ai-chatbot'); ?> <code><?php echo esc_html((string) $message['extracted_fields']); ?></code></small>
							<?php endif; ?>
							<time><?php echo esc_html((string) $message['created_at']); ?></time>
						</div>
					<?php endforeach; ?>
					<?php if (!$session_id) : ?>
						<p><?php esc_html_e('Select a session to inspect the transcript.', 'ridefleet-ai-chatbot'); ?></p>
					<?php elseif (!$messages) : ?>
						<p><?php esc_html_e('No messages found for this session.', 'ridefleet-ai-chatbot'); ?></p>
					<?php endif; ?>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	public static function delete(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to delete chatbot conversations.', 'ridefleet-ai-chatbot'));
		}

		$session_id = absint($_POST['session_id'] ?? 0);
		check_admin_referer('rfac_delete_chat_session_' . $session_id);

		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'rfac_chat_messages', ['session_id' => $session_id], ['%d']);
		$wpdb->delete($wpdb->prefix . 'rfac_chat_sessions', ['id' => $session_id], ['%d']);

		wp_safe_redirect(admin_url('admin.php?page=ridefleet-ai-chatbot-history&deleted=1'));
		exit;
	}

	public static function export_csv(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to export chatbot conversations.', 'ridefleet-ai-chatbot'));
		}

		check_admin_referer('rfac_export_chat_sessions');

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT s.id, s.session_key, s.state, s.created_at AS session_started, s.updated_at AS session_updated,
				m.role, m.message, m.intent, m.detected_language, m.created_at AS message_at
			 FROM {$wpdb->prefix}rfac_chat_sessions s
			 LEFT JOIN {$wpdb->prefix}rfac_chat_messages m ON m.session_id = s.id
			 ORDER BY s.id DESC, m.id ASC",
			ARRAY_A
		);

		nocache_headers();
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="ridefleet-chatbot-history-' . gmdate('Y-m-d-His') . '.csv"');

		$out = fopen('php://output', 'w');
		fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
		fputcsv($out, ['Session ID', 'Session Key', 'State', 'Session Started', 'Session Updated', 'Role', 'Message', 'Intent', 'Language', 'Message At']);
		foreach ((array) $rows as $row) {
			fputcsv($out, [
				$row['id'],
				$row['session_key'],
				$row['state'],
				$row['session_started'],
				$row['session_updated'],
				$row['role'],
				$row['message'],
				$row['intent'],
				$row['detected_language'],
				$row['message_at'],
			]);
		}
		fclose($out);
		exit;
	}
}
