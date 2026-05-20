<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class RoutePagesPage {
	public static function render(): void {
		if (!empty($_GET['delete_route']) && !empty($_GET['_wpnonce'])) {
			$del_id = absint($_GET['delete_route']);
			if (wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rfb_delete_route_' . $del_id)) {
				wp_delete_post($del_id, true);
				wp_safe_redirect(admin_url('admin.php?page=ridefleet-route-pages&deleted=1'));
				exit;
			}
		}

		$tab = sanitize_key($_GET['tab'] ?? 'routes');
		if ('routes' === $tab) {
			$routes = get_posts(['post_type' => 'rfb_route', 'post_status' => ['publish', 'draft'], 'numberposts' => 100]);
			$generated_count = count(array_filter($routes, fn($r) => (int) get_post_meta($r->ID, 'rfb_generated_page_id', true)));
			?>
			<div class="wrap rfb-admin">
				<div class="rfb-hero">
					<div>
						<p class="rfb-kicker"><?php esc_html_e('Route Library', 'ridefleet-booking'); ?></p>
						<h1><?php esc_html_e('Routes', 'ridefleet-booking'); ?></h1>
						<p><?php esc_html_e('Reusable origin/destination packages with fixed pricing, SEO landing pages, and route cache.', 'ridefleet-booking'); ?></p>
					</div>
					<div class="rfb-hero-actions">
						<a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=rfb_route')); ?>"><?php esc_html_e('Add Route', 'ridefleet-booking'); ?></a>
					</div>
				</div>
				<?php OperationalPage::tabs('routes'); ?>
				<?php self::tabs($tab); ?>
				<div class="rfb-stat-grid">
					<?php DashboardPage::stat(__('Routes', 'ridefleet-booking'), number_format_i18n(count($routes)), __('Total routes in library.', 'ridefleet-booking')); ?>
					<?php DashboardPage::stat(__('SEO pages', 'ridefleet-booking'), number_format_i18n($generated_count), __('Landing pages already generated.', 'ridefleet-booking')); ?>
					<?php DashboardPage::stat(__('Pending', 'ridefleet-booking'), number_format_i18n(count($routes) - $generated_count), __('Routes awaiting page generation.', 'ridefleet-booking')); ?>
					<?php DashboardPage::stat(__('Tip', 'ridefleet-booking'), __('Fixed price', 'ridefleet-booking'), __('Set a fixed price per route for accurate quotes.', 'ridefleet-booking')); ?>
				</div>

				<?php if (!empty($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Route deleted.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
				<div class="rfb-panel rfb-table-panel">
					<table class="widefat rfb-bookings-table">
						<thead><tr>
							<th><?php esc_html_e('Route', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Origin', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Destination', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Fixed Price', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Radius', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('SEO Page', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Actions', 'ridefleet-booking'); ?></th>
						</tr></thead>
						<tbody>
							<?php if (!$routes) : ?><tr><td colspan="7"><?php esc_html_e('No routes yet. Add your first route with Google Places assisted origin and destination fields.', 'ridefleet-booking'); ?></td></tr><?php endif; ?>
							<?php foreach ($routes as $route) :
								$has_coords = get_post_meta($route->ID, 'rfb_origin_lat', true) && get_post_meta($route->ID, 'rfb_destination_lat', true);
								$radius = get_post_meta($route->ID, 'rfb_match_radius', true) ?: 15;
							?>
								<tr>
									<td>
										<strong><?php echo esc_html(get_the_title($route)); ?></strong>
										<?php if (!$has_coords) : ?>
											<br><span style="color:#b45309;font-size:12px">&#9888; <?php esc_html_e('Missing coordinates — re-save to enable radius matching.', 'ridefleet-booking'); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html((string) get_post_meta($route->ID, 'rfb_origin', true)); ?></td>
									<td><?php echo esc_html((string) get_post_meta($route->ID, 'rfb_destination', true)); ?></td>
									<td><?php echo esc_html((string) get_post_meta($route->ID, 'rfb_fixed_price', true)); ?></td>
									<td><?php echo esc_html($radius . ' km'); ?></td>
									<td><?php echo esc_html(get_post_meta($route->ID, 'rfb_generated_page_id', true) ? __('Generated', 'ridefleet-booking') : __('Not generated', 'ridefleet-booking')); ?></td>
									<td>
										<a class="button button-small" href="<?php echo esc_url(get_edit_post_link($route->ID, '')); ?>"><?php esc_html_e('Edit', 'ridefleet-booking'); ?></a>
										<a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ridefleet-route-pages&delete_route=' . $route->ID), 'rfb_delete_route_' . $route->ID)); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this route?', 'ridefleet-booking')); ?>');"><?php esc_html_e('Delete', 'ridefleet-booking'); ?></a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php
			return;
		}
		if ('cache' === $tab) {
			?>
			<div class="wrap rfb-admin">
				<div class="rfb-hero">
					<div>
						<p class="rfb-kicker"><?php esc_html_e('Route Library', 'ridefleet-booking'); ?></p>
						<h1><?php esc_html_e('Route Cache', 'ridefleet-booking'); ?></h1>
						<p><?php esc_html_e('Stored distance and duration results from Google Maps. Clears automatically when routes are regenerated.', 'ridefleet-booking'); ?></p>
					</div>
				</div>
				<?php OperationalPage::tabs('routes'); ?>
				<?php self::tabs($tab); ?>
				<?php RouteCachePage::render_content(); ?>
			</div>
			<?php
			return;
		}
		$routes = get_posts(['post_type' => 'rfb_route', 'post_status' => 'publish', 'numberposts' => 100]);
		$seo_generated = count(array_filter($routes, fn($r) => (int) get_post_meta($r->ID, 'rfb_generated_page_id', true)));
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('SEO Growth', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Route Pages', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Generate SEO landing pages from your routes. Each page gets a booking form pre-loaded with the route origin and destination.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('routes'); ?>
			<?php self::tabs($tab); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Published routes', 'ridefleet-booking'), number_format_i18n(count($routes)), __('Routes eligible for page generation.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Pages generated', 'ridefleet-booking'), number_format_i18n($seo_generated), __('Live SEO landing pages.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Pending', 'ridefleet-booking'), number_format_i18n(count($routes) - $seo_generated), __('Routes without a landing page yet.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Shortcode', 'ridefleet-booking'), '[ridefleet_booking_form]', __('Auto-inserted with origin and destination pre-filled.', 'ridefleet-booking')); ?>
			</div>
			<div class="rfb-panel">
				<p><?php esc_html_e('Routes are reusable origin/destination packages. Create a route, add origin/destination/fixed pricing, then generate SEO landing pages here. Route cache stores distance and duration so repeat visitors do not trigger unnecessary Google calls.', 'ridefleet-booking'); ?></p>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="rfb_generate_route_pages">
					<?php wp_nonce_field('rfb_generate_route_pages'); ?>
					<?php submit_button(__('Generate Route Pages', 'ridefleet-booking'), 'primary', 'submit', false); ?>
				</form>
			</div>
			<div class="rfb-panel"><h2><?php esc_html_e('Saved Routes', 'ridefleet-booking'); ?></h2><div class="rfb-route-list">
				<?php foreach ($routes as $route) : ?>
					<div><strong><?php echo esc_html(get_the_title($route)); ?></strong><span><?php echo esc_html(get_post_meta($route->ID, 'rfb_generated_page_id', true) ? __('Page generated', 'ridefleet-booking') : __('Not generated yet', 'ridefleet-booking')); ?></span></div>
				<?php endforeach; ?>
			</div></div>
		</div>
		<?php
	}

	private static function tabs(string $tab): void {
		?>
		<nav class="rfb-tabs">
			<a class="<?php echo esc_attr('routes' === $tab ? 'is-active' : ''); ?>" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-route-pages')); ?>"><?php esc_html_e('Routes', 'ridefleet-booking'); ?></a>
			<a class="<?php echo esc_attr('pages' === $tab ? 'is-active' : ''); ?>" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-route-pages&tab=pages')); ?>"><?php esc_html_e('SEO Pages', 'ridefleet-booking'); ?></a>
			<a class="<?php echo esc_attr('cache' === $tab ? 'is-active' : ''); ?>" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-route-pages&tab=cache')); ?>"><?php esc_html_e('Route Cache', 'ridefleet-booking'); ?></a>
		</nav>
		<?php
	}

	public static function generate(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'ridefleet-booking'));
		}

		check_admin_referer('rfb_generate_route_pages');
		$routes = get_posts(['post_type' => 'rfb_route', 'post_status' => 'publish', 'numberposts' => 100]);
		foreach ($routes as $route) {
			if ((int) get_post_meta($route->ID, 'rfb_generated_page_id', true)) {
				continue;
			}

			$title = get_the_title($route);
			$origin = get_post_meta($route->ID, 'rfb_origin', true) ?: self::guess_origin($title);
			$destination = get_post_meta($route->ID, 'rfb_destination', true) ?: self::guess_destination($title);
			$page_id = wp_insert_post([
				'post_type' => 'page',
				'post_status' => 'publish',
				'post_title' => $title,
				'post_name' => sanitize_title($title),
				'post_content' => self::content($route, $title, $origin, $destination),
			]);

			if ($page_id && !is_wp_error($page_id)) {
				update_post_meta($route->ID, 'rfb_generated_page_id', $page_id);
			}
		}

		wp_safe_redirect(admin_url('admin.php?page=ridefleet-route-pages&generated=1'));
		exit;
	}

	private static function content(\WP_Post $route, string $title, string $origin, string $destination): string {
		$distance = get_post_meta($route->ID, 'rfb_sample_distance', true);
		$duration = get_post_meta($route->ID, 'rfb_sample_duration', true);
		$price = get_post_meta($route->ID, 'rfb_fixed_price', true);
		$faq = trim((string) get_post_meta($route->ID, 'rfb_faq', true));

		$facts = '';
		if ($distance || $duration || ((float) $price > 0)) {
			$facts .= '<div class="ridefleet-route-facts">';
			if ($distance) {
				$facts .= '<p><strong>Distance:</strong> ' . esc_html($distance) . '</p>';
			}
			if ($duration) {
				$facts .= '<p><strong>Estimated travel time:</strong> ' . esc_html($duration) . ' minutes</p>';
			}
			if ((float) $price > 0) {
				$facts .= '<p><strong>Sample fare:</strong> ' . esc_html(number_format_i18n((float) $price, 2)) . '</p>';
			}
			$facts .= '</div>';
		}

		$content = '<h2>' . esc_html($title) . '</h2>' . "\n\n";
		$content .= '<p>Book a private transfer from ' . esc_html($origin ?: 'your pickup location') . ' to ' . esc_html($destination ?: 'your destination') . '. Choose your pickup time, vehicle, optional extras, and reserve your ride online.</p>' . "\n\n";
		$content .= $facts . "\n\n";
		$content .= '[ridefleet_booking_form id="1" route_id="' . (int) $route->ID . '" origin="' . esc_attr($origin) . '" destination="' . esc_attr($destination) . '"]' . "\n\n";

		if ($faq) {
			$content .= '<h2>Frequently Asked Questions</h2>' . "\n\n" . wpautop(esc_html($faq));
		}

		return $content;
	}

	private static function guess_origin(string $title): string {
		$parts = preg_split('/\s+(to|-|->)\s+/i', str_replace('->', ' to ', $title));
		return is_array($parts) && count($parts) > 1 ? trim($parts[0]) : '';
	}

	private static function guess_destination(string $title): string {
		$parts = preg_split('/\s+(to|-|->)\s+/i', str_replace('->', ' to ', $title));
		return is_array($parts) && count($parts) > 1 ? trim(end($parts)) : '';
	}
}
