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
	private const PAGE_SIZE = 25;

	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to view chatbot conversations.', 'ridefleet-ai-chatbot'));
		}

		global $wpdb;
		$sessions_table = $wpdb->prefix . 'rfac_chat_sessions';
		$messages_table = $wpdb->prefix . 'rfac_chat_messages';

		// ── Booking funnel analytics ──────────────────────────────────────────────
		$funnel_states = ['greeting','capture_pickup','capture_dropoff','confirm_price','capture_name','booking_requested','complete'];
		$funnel_labels = [
			'greeting'         => 'Chat opened',
			'capture_pickup'   => 'Gave pickup',
			'capture_dropoff'  => 'Gave drop-off',
			'confirm_price'    => 'Saw quote',
			'capture_name'     => 'Gave name',
			'booking_requested'=> 'Submitted',
			'complete'         => 'Completed',
		];
		$post_funnel = ['capture_passengers','capture_luggage','capture_vehicle','vehicle_unavailable','capture_extras','quote_refresh_requested','capture_phone','capture_pickup_time','booking_requested','complete','change_pending','capture_via_stop','capture_change_request','confirm_long_trip'];
		$funnel_counts = [];
		foreach ($funnel_states as $fidx => $fs) {
			if ('complete' === $fs) {
				$funnel_counts[$fs] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sessions_table} WHERE state IN ('complete','change_pending')");
			} else {
				$reached = array_unique(array_merge(array_slice($funnel_states, $fidx), $post_funnel));
				$ph = implode(',', array_fill(0, count($reached), '%s'));
				$funnel_counts[$fs] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sessions_table} WHERE state IN ({$ph})", ...$reached));
			}
		}
		$funnel_max = max(1, $funnel_counts['greeting'] ?? 1);

		$session_id = absint($_GET['session_id'] ?? 0);
		$search = sanitize_text_field((string) ($_GET['s'] ?? ''));
		$state_filter = sanitize_key((string) ($_GET['state_filter'] ?? ''));
		$lang_filter = sanitize_key((string) ($_GET['lang_filter'] ?? ''));
		$paged = max(1, absint($_GET['paged'] ?? 1));
		$offset = ($paged - 1) * self::PAGE_SIZE;

		$where = [];
		$args = [];

		if ('' !== $search) {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where[] = '(s.session_key LIKE %s OR EXISTS (SELECT 1 FROM ' . $messages_table . ' m WHERE m.session_id = s.id AND m.message LIKE %s))';
			$args[] = $like;
			$args[] = $like;
		}

		if ('' !== $state_filter && 'all' !== $state_filter) {
			$where[] = 's.state = %s';
			$args[] = $state_filter;
		}

		if ('' !== $lang_filter && 'all' !== $lang_filter) {
			$where[] = "EXISTS (SELECT 1 FROM {$messages_table} m WHERE m.session_id = s.id AND m.detected_language = %s)";
			$args[] = $lang_filter;
		}

		$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
		$count_sql = "SELECT COUNT(*) FROM {$sessions_table} s {$where_sql}";
		$list_sql = "SELECT s.* FROM {$sessions_table} s {$where_sql} ORDER BY s.updated_at DESC LIMIT %d OFFSET %d";

		$total = $args
			? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$args))
			: (int) $wpdb->get_var($count_sql);

		$list_args = array_merge($args, [self::PAGE_SIZE, $offset]);
		$sessions = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_args), ARRAY_A);

		$state_counts = $wpdb->get_results("SELECT state, COUNT(*) AS c FROM {$sessions_table} GROUP BY state ORDER BY c DESC", ARRAY_A);

		$messages = [];
		$session_row = null;
		if ($session_id) {
			$messages = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$messages_table} WHERE session_id = %d ORDER BY id ASC", $session_id), ARRAY_A);
			$session_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id = %d", $session_id), ARRAY_A);
		}

		$base_url = admin_url('admin.php?page=ridefleet-ai-chatbot-history');
		$total_pages = max(1, (int) ceil($total / self::PAGE_SIZE));
		?>
		<div class="wrap rfac-admin">
			<h1>
				<?php esc_html_e('Chatbot Conversations', 'ridefleet-ai-chatbot'); ?>
				<span class="title-count theme-count"><?php echo esc_html(number_format_i18n($total)); ?></span>
				<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rfac_export_chat_sessions'), 'rfac_export_chat_sessions')); ?>" class="page-title-action"><?php esc_html_e('Export CSV', 'ridefleet-ai-chatbot'); ?></a>
			</h1>

			<section class="rfac-panel rfac-panel-wide" style="margin-top:18px;">
				<h2 style="margin-bottom:14px;"><?php esc_html_e('Booking Funnel', 'ridefleet-ai-chatbot'); ?></h2>
				<div class="rfac-funnel-bars">
					<?php foreach ($funnel_states as $fs):
						$count = $funnel_counts[$fs] ?? 0;
						$pct   = $funnel_max > 0 ? round($count / $funnel_max * 100) : 0;
						$label = $funnel_labels[$fs] ?? str_replace('_',' ',$fs);
					?>
					<div class="rfac-funnel-bar">
						<span class="rfac-funnel-bar__label"><?php echo esc_html($label); ?></span>
						<div class="rfac-funnel-bar__track">
							<div class="rfac-funnel-bar__fill" style="width:<?php echo esc_attr((string)$pct); ?>%;"></div>
						</div>
						<span class="rfac-funnel-bar__count"><?php echo esc_html(number_format_i18n($count)); ?> <small style="color:#94a3b8;">(<?php echo esc_html((string)$pct); ?>%)</small></span>
					</div>
					<?php endforeach; ?>
				</div>
			</section>

			<?php if (!empty($_GET['deleted'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Conversation deleted.', 'ridefleet-ai-chatbot'); ?></p></div>
			<?php endif; ?>

			<section class="rfac-panel rfac-panel-wide" style="margin-top:18px;">
				<form method="get" class="rfac-filters">
					<input type="hidden" name="page" value="ridefleet-ai-chatbot-history">
					<div class="rfac-filter-row">
						<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search session key or message text…', 'ridefleet-ai-chatbot'); ?>" class="rfac-search">
						<select name="lang_filter">
							<option value=""><?php esc_html_e('All languages', 'ridefleet-ai-chatbot'); ?></option>
							<?php foreach (['en' => 'English', 'fr' => 'Français', 'nl' => 'Nederlands'] as $code => $label) : ?>
								<option value="<?php echo esc_attr($code); ?>" <?php selected($lang_filter, $code); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'ridefleet-ai-chatbot'); ?></button>
						<?php if ('' !== $search || '' !== $state_filter || '' !== $lang_filter) : ?>
							<a href="<?php echo esc_url($base_url); ?>" class="button"><?php esc_html_e('Reset', 'ridefleet-ai-chatbot'); ?></a>
						<?php endif; ?>
					</div>

					<div class="rfac-state-chips">
						<a href="<?php echo esc_url(add_query_arg(['state_filter' => 'all', 's' => $search, 'lang_filter' => $lang_filter], $base_url)); ?>"
							class="rfac-chip <?php echo '' === $state_filter || 'all' === $state_filter ? 'is-active' : ''; ?>">
							<?php esc_html_e('All', 'ridefleet-ai-chatbot'); ?>
						</a>
						<?php foreach ((array) $state_counts as $sc) : ?>
							<a href="<?php echo esc_url(add_query_arg(['state_filter' => $sc['state'], 's' => $search, 'lang_filter' => $lang_filter], $base_url)); ?>"
								class="rfac-chip <?php echo $state_filter === $sc['state'] ? 'is-active' : ''; ?>">
								<?php echo esc_html(str_replace('_', ' ', (string) $sc['state'])); ?>
								<span class="rfac-chip-count"><?php echo esc_html(number_format_i18n((int) $sc['c'])); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</form>
			</section>

			<div class="rfac-history-layout">
				<section class="rfac-panel">
					<h2 style="margin-bottom:12px;"><?php esc_html_e('Sessions', 'ridefleet-ai-chatbot'); ?></h2>

					<div class="rfac-session-list">
						<?php foreach ((array) $sessions as $session) :
							$collected = json_decode((string) ($session['collected_data'] ?? '{}'), true);
							$collected = is_array($collected) ? $collected : [];
							$lang = (string) ($collected['language'] ?? '');
							$pickup = (string) ($collected['pickup_address'] ?? '');
							$dropoff = (string) ($collected['dropoff_address'] ?? '');
							$customer = (string) ($collected['customer_name'] ?? '');
							$is_active = $session_id === (int) $session['id'];
							?>
							<a class="rfac-session-card <?php echo $is_active ? 'is-active' : ''; ?>"
								href="<?php echo esc_url(add_query_arg(['session_id' => absint($session['id'])], $base_url)); ?>">
								<div class="rfac-session-head">
									<span class="rfac-state-pill rfac-state-<?php echo esc_attr(sanitize_key((string) $session['state'])); ?>">
										<?php echo esc_html(str_replace('_', ' ', (string) $session['state'])); ?>
									</span>
									<?php if ($lang) : ?>
										<span class="rfac-lang-pill rfac-lang-<?php echo esc_attr($lang); ?>"><?php echo esc_html(strtoupper($lang)); ?></span>
									<?php endif; ?>
									<time class="rfac-session-time"><?php echo esc_html(human_time_diff(strtotime((string) $session['updated_at']), current_time('U'))); ?> <?php esc_html_e('ago', 'ridefleet-ai-chatbot'); ?></time>
								</div>
								<?php if ($customer) : ?>
									<div class="rfac-session-customer"><?php echo esc_html($customer); ?></div>
								<?php endif; ?>
								<?php if ($pickup || $dropoff) : ?>
									<div class="rfac-session-route">
										<span>📍 <?php echo esc_html($pickup ?: '—'); ?></span>
										<span>🏁 <?php echo esc_html($dropoff ?: '—'); ?></span>
									</div>
								<?php endif; ?>
								<div class="rfac-session-key"><?php echo esc_html(substr((string) $session['session_key'], 0, 24)); ?>…</div>
							</a>
						<?php endforeach; ?>
						<?php if (!$sessions) : ?>
							<p style="padding:24px; text-align:center; color:#64748b;"><?php esc_html_e('No conversations match the current filter.', 'ridefleet-ai-chatbot'); ?></p>
						<?php endif; ?>
					</div>

					<?php if ($total_pages > 1) : ?>
						<div class="rfac-pagination">
							<?php
							$base_args = ['s' => $search, 'state_filter' => $state_filter, 'lang_filter' => $lang_filter];
							if ($paged > 1) {
								echo '<a class="button button-small" href="' . esc_url(add_query_arg(array_merge($base_args, ['paged' => $paged - 1]), $base_url)) . '">' . esc_html__('« Previous', 'ridefleet-ai-chatbot') . '</a>';
							}
							echo ' <span style="margin:0 10px;">' . sprintf(esc_html__('Page %1$d of %2$d', 'ridefleet-ai-chatbot'), $paged, $total_pages) . '</span> ';
							if ($paged < $total_pages) {
								echo '<a class="button button-small" href="' . esc_url(add_query_arg(array_merge($base_args, ['paged' => $paged + 1]), $base_url)) . '">' . esc_html__('Next »', 'ridefleet-ai-chatbot') . '</a>';
							}
							?>
						</div>
					<?php endif; ?>
				</section>

				<section class="rfac-panel">
					<?php if ($session_row) : ?>
						<div class="rfac-transcript-head">
							<div>
								<h2 style="margin:0 0 4px;"><?php esc_html_e('Transcript', 'ridefleet-ai-chatbot'); ?></h2>
								<div style="color:#64748b;font-size:12px;">
									<?php echo esc_html(substr((string) $session_row['session_key'], 0, 30)); ?>
									· <?php echo esc_html(count($messages)); ?> <?php esc_html_e('messages', 'ridefleet-ai-chatbot'); ?>
									· <?php echo esc_html((string) $session_row['updated_at']); ?>
								</div>
							</div>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<input type="hidden" name="action" value="rfac_delete_chat_session">
								<input type="hidden" name="session_id" value="<?php echo esc_attr((string) $session_row['id']); ?>">
								<?php wp_nonce_field('rfac_delete_chat_session_' . (int) $session_row['id']); ?>
								<button class="button button-link-delete" type="submit" onclick="return confirm('<?php echo esc_js(__('Delete this conversation? This cannot be undone.', 'ridefleet-ai-chatbot')); ?>');">
									<?php esc_html_e('Delete', 'ridefleet-ai-chatbot'); ?>
								</button>
							</form>
						</div>
					<?php else : ?>
						<h2 style="margin-bottom:12px;"><?php esc_html_e('Transcript', 'ridefleet-ai-chatbot'); ?></h2>
					<?php endif; ?>

					<div class="rfac-transcript">
					<?php foreach ((array) $messages as $message) :
						$role = (string) $message['role'];
						$intent = (string) ($message['intent'] ?? '');
						$lang = (string) ($message['detected_language'] ?? '');
						?>
						<div class="rfac-bubble-row rfac-bubble-row-<?php echo esc_attr($role); ?>">
							<div class="rfac-bubble rfac-bubble-<?php echo esc_attr($role); ?>">
								<div class="rfac-bubble-text"><?php echo nl2br(esc_html((string) $message['message'])); ?></div>
								<div class="rfac-bubble-meta">
									<time><?php echo esc_html(mysql2date('M j · H:i', (string) $message['created_at'])); ?></time>
									<?php if ($intent && 'unknown' !== $intent) : ?>
										<span class="rfac-meta-pill rfac-intent-pill"><?php echo esc_html(str_replace('_', ' ', $intent)); ?></span>
									<?php endif; ?>
									<?php if ($lang) : ?>
										<span class="rfac-meta-pill rfac-lang-pill rfac-lang-<?php echo esc_attr($lang); ?>"><?php echo esc_html(strtoupper($lang)); ?></span>
									<?php endif; ?>
								</div>
								<?php if (!empty($message['extracted_fields']) && '[]' !== (string) $message['extracted_fields']) :
									$fields = json_decode((string) $message['extracted_fields'], true);
									if (is_array($fields) && $fields) : ?>
									<details class="rfac-bubble-fields">
										<summary><?php esc_html_e('Extracted fields', 'ridefleet-ai-chatbot'); ?></summary>
										<pre><?php echo esc_html(wp_json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
									</details>
								<?php endif; endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
					<?php if (!$session_id) : ?>
						<p style="padding:36px; text-align:center; color:#64748b;"><?php esc_html_e('← Select a session to inspect the transcript.', 'ridefleet-ai-chatbot'); ?></p>
					<?php elseif (!$messages) : ?>
						<p style="padding:24px; text-align:center; color:#64748b;"><?php esc_html_e('No messages in this session yet.', 'ridefleet-ai-chatbot'); ?></p>
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
		fputs($out, "\xEF\xBB\xBF");
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
