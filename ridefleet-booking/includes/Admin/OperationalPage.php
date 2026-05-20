<?php
/**
 * Operational hub for fleet, pricing, routing, and service boundaries.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class OperationalPage {
	private static array $tabs = [
		'vehicles' => ['Vehicles', 'admin.php?page=ridefleet-vehicles'],
		'drivers' => ['Drivers', 'admin.php?page=ridefleet-drivers'],
		'extras' => ['Extras', 'admin.php?page=ridefleet-extras'],
		'coupons' => ['Coupons', 'admin.php?page=ridefleet-coupons'],
		'pricing' => ['Pricing Rules', 'admin.php?page=ridefleet-pricing-rules'],
		'availability' => ['Availability', 'admin.php?page=ridefleet-availability'],
		'routes' => ['Routes & SEO', 'admin.php?page=ridefleet-route-pages'],
		'areas' => ['Service Areas', 'admin.php?page=ridefleet-geofences'],
		'core' => ['Core Rules', 'admin.php?page=ridefleet-core-rules'],
	];

	public static function render(): void {
		$cards = [
			[
				'title' => __('Vehicles', 'ridefleet-booking'),
				'copy' => __('Capacity, luggage limits, price adjustments, and fleet profiles.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-vehicles'),
				'icon' => 'dashicons-car',
			],
			[
				'title' => __('Drivers', 'ridefleet-booking'),
				'copy' => __('Driver contact details, home bases, licenses, and dispatch notes.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-drivers'),
				'icon' => 'dashicons-id',
			],
			[
				'title' => __('Extras', 'ridefleet-booking'),
				'copy' => __('Child seats, meet and greet, luggage add-ons, and optional services.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-extras'),
				'icon' => 'dashicons-plus-alt2',
			],
			[
				'title' => __('Coupons', 'ridefleet-booking'),
				'copy' => __('Promotional codes, fixed discounts, percentage campaigns, and usage limits.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-coupons'),
				'icon' => 'dashicons-tickets-alt',
			],
			[
				'title' => __('Pricing Rules', 'ridefleet-booking'),
				'copy' => __('Time-window surcharges and operational pricing modifiers.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-pricing-rules'),
				'icon' => 'dashicons-chart-line',
			],
			[
				'title' => __('Availability', 'ridefleet-booking'),
				'copy' => __('Blackout windows and vehicle-specific availability blocks.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-availability'),
				'icon' => 'dashicons-calendar-alt',
			],
			[
				'title' => __('Routes & SEO', 'ridefleet-booking'),
				'copy' => __('Reusable routes, Google route metadata, SEO pages, and route cache.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-route-pages'),
				'icon' => 'dashicons-location-alt',
			],
			[
				'title' => __('Service Areas', 'ridefleet-booking'),
				'copy' => __('Operational geofences enforced by chatbot quotes and booking creation.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-geofences'),
				'icon' => 'dashicons-admin-site-alt3',
			],
			[
				'title' => __('Core Rules', 'ridefleet-booking'),
				'copy' => __('Global boundary rules, flat rates, buffer zones, and chatbot API policy.', 'ridefleet-booking'),
				'url' => admin_url('admin.php?page=ridefleet-core-rules'),
				'icon' => 'dashicons-shield',
			],
		];
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Fleet Control', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Operational', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Manage vehicles, drivers, extras, coupons, pricing rules, availability, routes, and service boundaries.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<div class="rfb-operational-grid">
				<?php foreach ($cards as $card) : ?>
					<a class="rfb-operational-card" href="<?php echo esc_url($card['url']); ?>">
						<span class="dashicons <?php echo esc_attr($card['icon']); ?>"></span>
						<strong><?php echo esc_html($card['title']); ?></strong>
						<small><?php echo esc_html($card['copy']); ?></small>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public static function maybe_render_admin_tabs(): void {
		$screen = get_current_screen();
		$post_type = $screen ? (string) $screen->post_type : '';
		$base = $screen ? (string) $screen->base : '';

		if (!in_array($post_type, ['rfb_route'], true)) {
			return;
		}

		$is_form = ('post' === $base); // add/edit a single post, not the list screen

		$cfg = [
			'rfb_route' => [
				'kicker'       => __('Routes', 'ridefleet-booking'),
				'title'        => __('Routes', 'ridefleet-booking'),
				'desc'         => __('Reusable origin/destination packages with fixed pricing, SEO pages, and route distance cache.', 'ridefleet-booking'),
				'add_url'      => admin_url('post-new.php?post_type=rfb_route'),
				'add_label'    => __('Add Route', 'ridefleet-booking'),
				'list_url'     => admin_url('edit.php?post_type=rfb_route'),
				'list_label'   => __('All Routes', 'ridefleet-booking'),
				'stat_active'  => __('Published routes', 'ridefleet-booking'),
				'stat_a_desc'  => __('Live and available for SEO page generation.', 'ridefleet-booking'),
				'stat_draft'   => __('Draft', 'ridefleet-booking'),
				'stat_d_desc'  => __('Work-in-progress routes.', 'ridefleet-booking'),
				'tip'          => __('Generate SEO landing pages from Routes & SEO.', 'ridefleet-booking'),
			],
		];

		$c = $cfg[$post_type];

		echo '<div class="wrap rfb-admin rfb-ops-tabs-wrap">';

		// ① Hero — Add button on list, Back button on form screens
		echo '<div class="rfb-hero">';
		echo '<div>';
		echo '<p class="rfb-kicker">' . esc_html($c['kicker']) . '</p>';
		echo '<h1>' . esc_html($c['title']) . '</h1>';
		echo '<p>' . esc_html($c['desc']) . '</p>';
		echo '</div>';
		echo '<div class="rfb-hero-actions">';
		if ($is_form) {
			echo '<a class="button" href="' . esc_url($c['list_url']) . '">' . esc_html($c['list_label']) . '</a>';
		} else {
			echo '<a class="button button-primary" href="' . esc_url($c['add_url']) . '">' . esc_html($c['add_label']) . '</a>';
		}
		echo '</div>';
		echo '</div>';

		// ② Ops navigation tabs (always visible)
		self::tabs(self::active_key($post_type, ''));

		// ③ Stat grid — list screens only, not on add/edit forms
		if (!$is_form) {
			$counts = wp_count_posts($post_type);
			$published = (int) ($counts->publish ?? 0);
			$draft = (int) ($counts->draft ?? 0);
			echo '<div class="rfb-stat-grid">';
			DashboardPage::stat($c['stat_active'], number_format_i18n($published), $c['stat_a_desc']);
			DashboardPage::stat($c['stat_draft'], number_format_i18n($draft), $c['stat_d_desc']);
			DashboardPage::stat(__('Total', 'ridefleet-booking'), number_format_i18n($published + $draft), __('All records including drafts.', 'ridefleet-booking'));
			DashboardPage::stat(__('Tip', 'ridefleet-booking'), '—', $c['tip']);
			echo '</div>';
		}

		echo '</div>';
	}

	public static function tabs(string $active = ''): void {
		echo '<nav class="rfb-tabs rfb-ops-tabs">';
		foreach (self::$tabs as $key => $tab) {
			[$label, $url] = $tab;
			echo '<a class="' . esc_attr($key === $active ? 'is-active' : '') . '" href="' . esc_url(admin_url($url)) . '">' . esc_html__($label, 'ridefleet-booking') . '</a>';
		}
		echo '</nav>';
	}

	private static function active_key(string $post_type, string $page): string {
		return match ($post_type ?: $page) {
			'rfb_vehicle' => 'vehicles',
			'rfb_driver' => 'drivers',
			'rfb_extra' => 'extras',
			'rfb_route', 'ridefleet-route-pages' => 'routes',
			'ridefleet-coupons' => 'coupons',
			'ridefleet-pricing-rules' => 'pricing',
			'ridefleet-availability' => 'availability',
			'ridefleet-geofences' => 'areas',
			'ridefleet-core-rules' => 'core',
			default => '',
		};
	}
}
