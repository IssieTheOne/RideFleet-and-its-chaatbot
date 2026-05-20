<?php
/**
 * Error log admin page.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

use RideFleetAIChatbot\Support\Logger;

if (!defined('ABSPATH')) {
	exit;
}

final class ErrorLogPage {
	public static function render(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to view the error log.', 'ridefleet-ai-chatbot'));
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rfac_error_log';
		$level_filter = sanitize_key((string) ($_GET['level'] ?? ''));
		$source_filter = sanitize_text_field((string) ($_GET['source'] ?? ''));

		$where = [];
		$args = [];
		if ('' !== $level_filter && 'all' !== $level_filter) {
			$where[] = 'level = %s';
			$args[] = $level_filter;
		}
		if ('' !== $source_filter) {
			$where[] = 'source = %s';
			$args[] = $source_filter;
		}
		$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
		$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT 200";
		$rows = $args ? $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

		$counts = $wpdb->get_results("SELECT level, COUNT(*) AS c FROM {$table} GROUP BY level", ARRAY_A);
		$count_map = [];
		foreach ((array) $counts as $row) {
			$count_map[(string) $row['level']] = (int) $row['c'];
		}
		$total = array_sum($count_map);
		$sources = $wpdb->get_col("SELECT DISTINCT source FROM {$table} ORDER BY source ASC");

		$base_url = admin_url('admin.php?page=ridefleet-ai-chatbot-errors');
		?>
		<div class="wrap rfac-admin">
			<h1>
				<?php esc_html_e('Error Log', 'ridefleet-ai-chatbot'); ?>
				<span class="title-count theme-count"><?php echo esc_html(number_format_i18n($total)); ?></span>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
					<input type="hidden" name="action" value="rfac_purge_errors">
					<?php wp_nonce_field('rfac_purge_errors'); ?>
					<button class="page-title-action" type="submit" onclick="return confirm('<?php echo esc_js(__('Purge error log entries older than 30 days?', 'ridefleet-ai-chatbot')); ?>');"><?php esc_html_e('Purge old entries', 'ridefleet-ai-chatbot'); ?></button>
				</form>
			</h1>

			<?php if (!empty($_GET['purged'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php printf(esc_html__('Removed %s entries.', 'ridefleet-ai-chatbot'), esc_html(number_format_i18n((int) $_GET['purged']))); ?></p></div>
			<?php endif; ?>

			<section class="rfac-panel rfac-panel-wide" style="margin-top:18px;">
				<nav class="rfac-status-tabs">
					<?php foreach (['' => __('All', 'ridefleet-ai-chatbot'), 'critical' => __('Critical', 'ridefleet-ai-chatbot'), 'error' => __('Error', 'ridefleet-ai-chatbot'), 'warning' => __('Warning', 'ridefleet-ai-chatbot'), 'info' => __('Info', 'ridefleet-ai-chatbot')] as $key => $label) : ?>
						<a href="<?php echo esc_url(add_query_arg(['level' => $key], $base_url)); ?>" class="rfac-status-tab <?php echo $level_filter === $key ? 'is-active' : ''; ?>">
							<span><?php echo esc_html($label); ?></span>
							<b><?php echo esc_html(number_format_i18n('' === $key ? $total : (int) ($count_map[$key] ?? 0))); ?></b>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php if ($sources) : ?>
					<div style="margin-bottom:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
						<span style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;"><?php esc_html_e('Source:', 'ridefleet-ai-chatbot'); ?></span>
						<a href="<?php echo esc_url(add_query_arg(['source' => '', 'level' => $level_filter], $base_url)); ?>" class="rfac-chip <?php echo '' === $source_filter ? 'is-active' : ''; ?>"><?php esc_html_e('All', 'ridefleet-ai-chatbot'); ?></a>
						<?php foreach ($sources as $source) : ?>
							<a href="<?php echo esc_url(add_query_arg(['source' => $source, 'level' => $level_filter], $base_url)); ?>" class="rfac-chip <?php echo $source_filter === $source ? 'is-active' : ''; ?>"><?php echo esc_html($source); ?></a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="rfac-error-list">
					<?php foreach ((array) $rows as $row) :
						$context = json_decode((string) ($row['context'] ?? '{}'), true);
						$context = is_array($context) ? $context : [];
						?>
						<article class="rfac-error-row rfac-error-<?php echo esc_attr((string) $row['level']); ?>">
							<header>
								<span class="rfac-error-level rfac-error-level-<?php echo esc_attr((string) $row['level']); ?>"><?php echo esc_html((string) $row['level']); ?></span>
								<span class="rfac-error-source"><?php echo esc_html((string) $row['source']); ?></span>
								<time><?php echo esc_html(mysql2date('M j, Y · H:i:s', (string) $row['created_at'])); ?></time>
							</header>
							<div class="rfac-error-message"><?php echo esc_html((string) $row['message']); ?></div>
							<?php if ($context) : ?>
								<details>
									<summary><?php esc_html_e('Context', 'ridefleet-ai-chatbot'); ?></summary>
									<pre><?php echo esc_html(wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
								</details>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
					<?php if (!$rows) : ?>
						<div class="rfac-empty-state">
							<p style="font-size:16px;color:#0f766e;font-weight:700;margin:0 0 6px;"><?php esc_html_e('Quiet so far. 🌤', 'ridefleet-ai-chatbot'); ?></p>
							<p style="color:#64748b;margin:0;"><?php esc_html_e('Errors, timeouts, and rate-limit hits will show up here so you can spot issues fast.', 'ridefleet-ai-chatbot'); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}

	public static function purge(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'ridefleet-ai-chatbot'));
		}
		check_admin_referer('rfac_purge_errors');
		$count = Logger::purge(30);
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-ai-chatbot-errors&purged=' . $count));
		exit;
	}
}
