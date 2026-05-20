<?php
/**
 * Self-hosted plugin updates over the WordPress Updates API.
 *
 * Plumbing only: when you stand up an update server (or use GitHub releases
 * with a simple JSON manifest), point `update_endpoint` at it and WordPress
 * will surface OTA updates in the Plugins screen the same way wp.org plugins
 * do.
 *
 * Expected manifest shape (publish at the configured URL):
 * {
 *   "version": "3.6.0",
 *   "tested": "6.5",
 *   "requires": "6.3",
 *   "requires_php": "8.1",
 *   "download_url": "https://example.com/builds/ridefleet-ai-chatbot-3.6.0.zip",
 *   "homepage": "https://example.com/ridefleet",
 *   "sections": { "description": "...", "changelog": "## 3.6.0\n- ..." }
 * }
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Support;

if (!defined('ABSPATH')) {
	exit;
}

final class UpdateChecker {
	private string $slug;
	private string $basename;
	private string $version;
	private string $endpoint;
	private string $license_key;

	public function __construct(string $slug, string $basename, string $version, string $endpoint, string $license_key = '') {
		$this->slug = $slug;
		$this->basename = $basename;
		$this->version = $version;
		$this->endpoint = $endpoint;
		$this->license_key = $license_key;
	}

	public function register(): void {
		if ('' === $this->endpoint) {
			return;
		}
		add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
		add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
		add_filter('upgrader_pre_download', [$this, 'upgrader_pre_download'], 10, 3);
	}

	public function inject_update(mixed $transient): mixed {
		if (!is_object($transient)) {
			return $transient;
		}

		$remote = $this->fetch_remote();
		if (!$remote || version_compare($remote['version'], $this->version, '<=')) {
			return $transient;
		}

		$transient->response[$this->basename] = (object) [
			'slug' => $this->slug,
			'plugin' => $this->basename,
			'new_version' => $remote['version'],
			'url' => $remote['homepage'] ?? '',
			'package' => $remote['download_url'] ?? '',
			'tested' => $remote['tested'] ?? '',
			'requires' => $remote['requires'] ?? '',
			'requires_php' => $remote['requires_php'] ?? '',
		];

		return $transient;
	}

	public function plugins_api(mixed $result, string $action, object $args): mixed {
		if ('plugin_information' !== $action || empty($args->slug) || $args->slug !== $this->slug) {
			return $result;
		}

		$remote = $this->fetch_remote();
		if (!$remote) {
			return $result;
		}

		return (object) [
			'name' => $remote['name'] ?? $this->slug,
			'slug' => $this->slug,
			'version' => $remote['version'] ?? '',
			'tested' => $remote['tested'] ?? '',
			'requires' => $remote['requires'] ?? '',
			'requires_php' => $remote['requires_php'] ?? '',
			'download_link' => $remote['download_url'] ?? '',
			'homepage' => $remote['homepage'] ?? '',
			'sections' => $remote['sections'] ?? ['description' => ''],
		];
	}

	/**
	 * Lets us attach a license key to the actual download (skipped if blank).
	 */
	public function upgrader_pre_download(mixed $reply, string $package, object $upgrader): mixed {
		if ('' === $this->license_key) {
			return $reply;
		}

		$remote = $this->fetch_remote();
		if (!$remote || empty($remote['download_url']) || $package !== $remote['download_url']) {
			return $reply;
		}

		$signed = add_query_arg([
			'license' => rawurlencode($this->license_key),
			'site' => rawurlencode(home_url()),
		], $package);

		return $upgrader->skin->feedback('downloading_package', $signed) ?: $reply;
	}

	private function fetch_remote(): ?array {
		$cache_key = 'rfac_remote_manifest_' . md5($this->endpoint);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		$response = wp_remote_get($this->endpoint, [
			'timeout' => 10,
			'headers' => array_filter([
				'Accept' => 'application/json',
				'X-RideFleet-Plugin' => $this->slug,
				'X-RideFleet-Version' => $this->version,
				'X-RideFleet-Site' => home_url(),
				'X-RideFleet-License' => '' !== $this->license_key ? $this->license_key : null,
			]),
		]);

		if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
			set_transient($cache_key, [], 5 * MINUTE_IN_SECONDS);
			return null;
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if (!is_array($body) || empty($body['version'])) {
			return null;
		}

		set_transient($cache_key, $body, 6 * HOUR_IN_SECONDS);
		return $body;
	}
}
