<?php
/**
 * Conversation diagnostics and API call log.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

use RideFleetAIChatbot\Support\DiagnosticLogger;

if (!defined('ABSPATH')) {
	exit;
}

final class DiagnosticLogPage {
	private const PAGE_SIZE = 50;

	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to view chatbot API logs.', 'ridefleet-ai-chatbot'));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rfac_diagnostic_events';
		$sessions = $wpdb->prefix . 'rfac_chat_sessions';
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
			echo '<div class="wrap rfac-admin"><h1>' . esc_html__('API Logs', 'ridefleet-ai-chatbot') . '</h1><p>' . esc_html__('The diagnostics table has not been created yet. Deactivate/reactivate the plugin or reload once the database updater has run.', 'ridefleet-ai-chatbot') . '</p></div>';
			return;
		}

		$session_id = absint($_GET['session_id'] ?? 0);
		$event_filter = sanitize_key((string) ($_GET['event_type'] ?? ''));
		$source_filter = sanitize_key((string) ($_GET['source'] ?? ''));
		$search = sanitize_text_field((string) ($_GET['s'] ?? ''));
		$paged = max(1, absint($_GET['paged'] ?? 1));
		$offset = ($paged - 1) * self::PAGE_SIZE;

		$where = [];
		$args = [];
		if ($session_id > 0) {
			$where[] = 'd.session_id = %d';
			$args[] = $session_id;
		}
		if ('' !== $event_filter) {
			$where[] = 'd.event_type = %s';
			$args[] = $event_filter;
		}
		if ('' !== $source_filter) {
			$where[] = 'd.source = %s';
			$args[] = $source_filter;
		}
		if ('' !== $search) {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where[] = '(d.summary LIKE %s OR d.context LIKE %s OR d.session_key LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}

		$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
		$count_sql = "SELECT COUNT(*) FROM {$table} d {$where_sql}";
		$total = $args ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$args)) : (int) $wpdb->get_var($count_sql);
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.*, s.state
				 FROM {$table} d
				 LEFT JOIN {$sessions} s ON s.id = d.session_id
				 {$where_sql}
				 ORDER BY d.id DESC
				 LIMIT %d OFFSET %d",
				...array_merge($args, [self::PAGE_SIZE, $offset])
			),
			ARRAY_A
		) ?: [];

		$event_types = $wpdb->get_col("SELECT DISTINCT event_type FROM {$table} WHERE event_type <> '' ORDER BY event_type ASC") ?: [];
		$sources = $wpdb->get_col("SELECT DISTINCT source FROM {$table} WHERE source <> '' ORDER BY source ASC") ?: [];
		$recent_sessions = $wpdb->get_results("SELECT id, session_key, state, updated_at FROM {$sessions} ORDER BY updated_at DESC LIMIT 100", ARRAY_A) ?: [];
		$base_url = admin_url('admin.php?page=ridefleet-ai-chatbot-api-logs');
		$total_pages = max(1, (int) ceil($total / self::PAGE_SIZE));
		?>
		<div class="wrap rfac-admin">
			<div class="rfac-hero">
				<div>
					<p class="rfac-kicker"><?php esc_html_e('RideFleet AI Chatbot', 'ridefleet-ai-chatbot'); ?></p>
					<h1><?php esc_html_e('API Logs', 'ridefleet-ai-chatbot'); ?>
						<span class="title-count theme-count" style="background:rgba(255,255,255,.15);color:#fff;"><?php echo esc_html(number_format_i18n($total)); ?></span>
					</h1>
					<p><?php esc_html_e('Track every conversation turn, AI decision, core API request, duplicate replay, and booking submission in structured JSON.', 'ridefleet-ai-chatbot'); ?></p>
				</div>
				<div class="rfac-hero-actions">
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot-history')); ?>" class="button"><?php esc_html_e('Conversations', 'ridefleet-ai-chatbot'); ?></a>
					<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-ai-chatbot-settings')); ?>" class="button"><?php esc_html_e('Settings', 'ridefleet-ai-chatbot'); ?></a>
					<a href="<?php echo esc_url(self::export_url($session_id, $event_filter, $source_filter, $search)); ?>" class="button button-primary"><?php esc_html_e('Export JSON for Codex', 'ridefleet-ai-chatbot'); ?></a>
				</div>
			</div>

			<?php if (!empty($_GET['purged'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Old diagnostic logs purged.', 'ridefleet-ai-chatbot'); ?></p></div>
			<?php endif; ?>

			<section class="rfac-panel rfac-panel-wide" style="margin-top:18px;">
				<form method="get" class="rfac-filters">
					<input type="hidden" name="page" value="ridefleet-ai-chatbot-api-logs">
					<div class="rfac-filter-row">
						<input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search summary, JSON, or session key...', 'ridefleet-ai-chatbot'); ?>" class="rfac-search">
						<select name="session_id">
							<option value="0"><?php esc_html_e('All sessions', 'ridefleet-ai-chatbot'); ?></option>
							<?php foreach ($recent_sessions as $session) : ?>
								<option value="<?php echo esc_attr((string) $session['id']); ?>" <?php selected($session_id, (int) $session['id']); ?>>
									#<?php echo esc_html((string) $session['id']); ?> - <?php echo esc_html((string) $session['state']); ?> - <?php echo esc_html(substr((string) $session['session_key'], 0, 12)); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<select name="event_type">
							<option value=""><?php esc_html_e('All event types', 'ridefleet-ai-chatbot'); ?></option>
							<?php foreach ($event_types as $event_type) : ?>
								<option value="<?php echo esc_attr($event_type); ?>" <?php selected($event_filter, $event_type); ?>><?php echo esc_html(str_replace('_', ' ', $event_type)); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="source">
							<option value=""><?php esc_html_e('All sources', 'ridefleet-ai-chatbot'); ?></option>
							<?php foreach ($sources as $source) : ?>
								<option value="<?php echo esc_attr($source); ?>" <?php selected($source_filter, $source); ?>><?php echo esc_html($source); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'ridefleet-ai-chatbot'); ?></button>
						<a href="<?php echo esc_url($base_url); ?>" class="button"><?php esc_html_e('Reset', 'ridefleet-ai-chatbot'); ?></a>
					</div>
				</form>
			</section>

			<section class="rfac-panel rfac-panel-wide" style="margin-top:18px;">
				<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
					<h2 style="margin:0;"><?php esc_html_e('Structured Events', 'ridefleet-ai-chatbot'); ?></h2>
					<div style="display:flex;gap:8px;flex-wrap:wrap;">
						<a href="<?php echo esc_url(self::export_url($session_id, $event_filter, $source_filter, $search)); ?>" class="button button-primary"><?php esc_html_e('Export JSON', 'ridefleet-ai-chatbot'); ?></a>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
							<input type="hidden" name="action" value="rfac_purge_diagnostic_logs">
							<?php wp_nonce_field('rfac_purge_diagnostic_logs'); ?>
							<button type="submit" class="button" onclick="return confirm('<?php echo esc_js(__('Purge diagnostic logs older than the retention window?', 'ridefleet-ai-chatbot')); ?>');"><?php esc_html_e('Purge old logs', 'ridefleet-ai-chatbot'); ?></button>
						</form>
					</div>
				</div>

				<div class="rfac-log-table" style="overflow:auto;">
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Time', 'ridefleet-ai-chatbot'); ?></th>
								<th><?php esc_html_e('Session', 'ridefleet-ai-chatbot'); ?></th>
								<th><?php esc_html_e('Event', 'ridefleet-ai-chatbot'); ?></th>
								<th><?php esc_html_e('Source', 'ridefleet-ai-chatbot'); ?></th>
								<th><?php esc_html_e('Summary / JSON', 'ridefleet-ai-chatbot'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($rows as $row) :
								$context = json_decode((string) ($row['context'] ?? '{}'), true);
								$context = is_array($context) ? $context : [];
								?>
								<tr>
									<td style="white-space:nowrap;"><?php echo esc_html(mysql2date('M j H:i:s', (string) $row['created_at'])); ?></td>
									<td>
										<?php if (!empty($row['session_id'])) : ?>
											<a href="<?php echo esc_url(add_query_arg(['session_id' => absint($row['session_id'])], $base_url)); ?>">#<?php echo esc_html((string) $row['session_id']); ?></a>
											<?php if (!empty($row['state'])) : ?><br><small><?php echo esc_html((string) $row['state']); ?></small><?php endif; ?>
										<?php else : ?>
											<code><?php echo esc_html(substr((string) $row['session_key'], 0, 16)); ?></code>
										<?php endif; ?>
									</td>
									<td><span class="rfac-state-pill"><?php echo esc_html(str_replace('_', ' ', (string) $row['event_type'])); ?></span></td>
									<td><code><?php echo esc_html((string) $row['source']); ?></code></td>
									<td>
										<div style="font-weight:600;margin-bottom:6px;"><?php echo esc_html((string) $row['summary']); ?></div>
										<?php if ($context) : ?>
											<details>
												<summary><?php esc_html_e('JSON context', 'ridefleet-ai-chatbot'); ?></summary>
												<pre style="max-height:420px;overflow:auto;background:#0f172a;color:#dbeafe;padding:12px;border-radius:8px;"><?php echo esc_html(wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
											</details>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							<?php if (!$rows) : ?>
								<tr><td colspan="5" style="text-align:center;padding:28px;color:#64748b;"><?php esc_html_e('No diagnostic events match the current filters.', 'ridefleet-ai-chatbot'); ?></td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<?php if ($total_pages > 1) :
					$base_args = ['s' => $search, 'session_id' => $session_id, 'event_type' => $event_filter, 'source' => $source_filter];
					?>
					<div class="rfac-pagination" style="margin-top:14px;">
						<?php if ($paged > 1) : ?>
							<a class="button button-small" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['paged' => $paged - 1]), $base_url)); ?>"><?php esc_html_e('Previous', 'ridefleet-ai-chatbot'); ?></a>
						<?php endif; ?>
						<span style="margin:0 10px;"><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'ridefleet-ai-chatbot'), $paged, $total_pages)); ?></span>
						<?php if ($paged < $total_pages) : ?>
							<a class="button button-small" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['paged' => $paged + 1]), $base_url)); ?>"><?php esc_html_e('Next', 'ridefleet-ai-chatbot'); ?></a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}

	public static function purge(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to purge chatbot API logs.', 'ridefleet-ai-chatbot'));
		}

		check_admin_referer('rfac_purge_diagnostic_logs');
		$days = max(7, (int) \RideFleetAIChatbot\Support\Options::get('data_retention_days', 90));
		DiagnosticLogger::purge($days);
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-ai-chatbot-api-logs&purged=1'));
		exit;
	}

	public static function export_json(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to export chatbot API logs.', 'ridefleet-ai-chatbot'));
		}

		check_admin_referer('rfac_export_diagnostic_logs');

		global $wpdb;
		$table = $wpdb->prefix . 'rfac_diagnostic_events';
		$sessions_table = $wpdb->prefix . 'rfac_chat_sessions';
		$messages_table = $wpdb->prefix . 'rfac_chat_messages';
		$bookings_table = $wpdb->prefix . 'rfac_booking_events';

		$session_id = absint($_GET['session_id'] ?? 0);
		$event_filter = sanitize_key((string) ($_GET['event_type'] ?? ''));
		$source_filter = sanitize_key((string) ($_GET['source'] ?? ''));
		$search = sanitize_text_field((string) ($_GET['s'] ?? ''));

		$where = [];
		$args = [];
		if ($session_id > 0) {
			$where[] = 'session_id = %d';
			$args[] = $session_id;
		}
		if ('' !== $event_filter) {
			$where[] = 'event_type = %s';
			$args[] = $event_filter;
		}
		if ('' !== $source_filter) {
			$where[] = 'source = %s';
			$args[] = $source_filter;
		}
		if ('' !== $search) {
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where[] = '(summary LIKE %s OR context LIKE %s OR session_key LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}
		$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
		$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY id ASC LIMIT 2000";
		$events = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
		$events = array_map([self::class, 'normalize_event_for_export'], is_array($events) ? $events : []);

		$session_ids = array_values(array_unique(array_filter(array_map(static fn(array $event): int => absint($event['session_id'] ?? 0), $events))));
		if ($session_id > 0 && !in_array($session_id, $session_ids, true)) {
			$session_ids[] = $session_id;
		}

		$sessions = [];
		$messages = [];
		$bookings = [];
		if ($session_ids) {
			$placeholders = implode(',', array_fill(0, count($session_ids), '%d'));
			$sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id IN ({$placeholders}) ORDER BY id ASC", ...$session_ids), ARRAY_A) ?: [];
			$messages = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$messages_table} WHERE session_id IN ({$placeholders}) ORDER BY session_id ASC, id ASC", ...$session_ids), ARRAY_A) ?: [];
			$bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$bookings_table} WHERE session_id IN ({$placeholders}) ORDER BY session_id ASC, id ASC", ...$session_ids), ARRAY_A) ?: [];
		}

		$export = [
			'format' => 'ridefleet_chatbot_diagnostic_export_v1',
			'generated_at' => current_time('mysql'),
			'filters' => [
				'session_id' => $session_id,
				'event_type' => $event_filter,
				'source' => $source_filter,
				'search' => $search,
				'limit' => 2000,
			],
			'how_to_read' => [
				'events' => 'Chronological structured diagnostics: AI calls, API calls, turn transitions, duplicate replays, and assistant responses.',
				'messages' => 'Human-readable transcript rows for the included sessions.',
				'sessions' => 'Final stored state, collected_data, and last_quote snapshots.',
				'bookings' => 'Booking submission results logged by the chatbot.',
			],
			'sessions' => array_map([self::class, 'decode_json_columns'], $sessions),
			'messages' => array_map([self::class, 'decode_json_columns'], $messages),
			'events' => $events,
			'bookings' => array_map([self::class, 'decode_json_columns'], $bookings),
		];

		nocache_headers();
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="ridefleet-chatbot-api-log-' . gmdate('Y-m-d-His') . '.json"');
		echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	private static function export_url(int $session_id, string $event_filter, string $source_filter, string $search): string {
		return wp_nonce_url(
			add_query_arg(
				[
					'action' => 'rfac_export_diagnostic_logs',
					'session_id' => $session_id,
					'event_type' => $event_filter,
					'source' => $source_filter,
					's' => $search,
				],
				admin_url('admin-post.php')
			),
			'rfac_export_diagnostic_logs'
		);
	}

	private static function normalize_event_for_export(array $event): array {
		$event = self::decode_json_columns($event);
		if (isset($event['context']) && is_string($event['context'])) {
			$decoded = json_decode($event['context'], true);
			if (is_array($decoded)) {
				$event['context'] = $decoded;
			}
		}
		return $event;
	}

	private static function decode_json_columns(array $row): array {
		foreach (['context', 'collected_data', 'last_quote', 'extracted_fields'] as $key) {
			if (isset($row[$key]) && is_string($row[$key]) && '' !== $row[$key]) {
				$decoded = json_decode($row[$key], true);
				if (is_array($decoded)) {
					$row[$key] = $decoded;
				}
			}
		}
		return $row;
	}
}
