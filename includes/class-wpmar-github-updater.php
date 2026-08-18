<?php
/**
 * GitHub Releases update checker.
 *
 * Hooks into WordPress's plugin update transient so that new releases
 * published on GitHub appear as available updates in the dashboard and
 * can be applied via the standard WordPress one-click updater.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integrates GitHub Releases with the WordPress plugin update pipeline.
 */
class WPMAR_GitHub_Updater {

	/** GitHub repository identifier (owner/repo). */
	private const GITHUB_REPO = 'lunaluna/wp-maintenance-audit-reporter';

	/** Plugin slug (also the prefix of the distributed plugin zip asset name). */
	private const PLUGIN_SLUG = 'wp-maintenance-audit-reporter';

	/** Transient key used to cache the latest release response. */
	private const CACHE_KEY = 'wpmar_github_release_cache';

	/** Default cache TTL for a successful API response (seconds). 6 hours. */
	private const DEFAULT_CACHE_TTL = 21600;

	/** Default back-off TTL after a failed / rate-limited request (seconds). 30 minutes. */
	private const DEFAULT_BACKOFF_TTL = 1800;

	/**
	 * Registers the three WordPress hooks needed for update integration.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Injects update information into the WordPress update transient when a
	 * newer GitHub release is available.
	 *
	 * @param  mixed $transient Value of `update_plugins` transient.
	 * @return mixed
	 */
	public static function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = $release['version'];

		// Compare against the version WordPress actually sees in the plugin header
		// (update.php:497), not the WPMAR_VERSION constant, which can drift.
		$installed = $transient->checked[ WPMAR_PLUGIN_BASENAME ] ?? WPMAR_VERSION;

		if ( version_compare( $latest_version, $installed, '>' ) ) {
			$transient->response[ WPMAR_PLUGIN_BASENAME ] = self::build_plugin_update_object( $release );
		} else {
			// Up-to-date: actively remove any stale "update available" entry left
			// in the persisted transient (e.g. set before the last update), so the
			// "new version available" notice clears once we are on the latest
			// version. Then record it in the "no update" bucket.
			unset( $transient->response[ WPMAR_PLUGIN_BASENAME ] );
			$transient->no_update[ WPMAR_PLUGIN_BASENAME ] = self::build_plugin_update_object( $release );
		}

		return $transient;
	}

	/**
	 * Provides plugin metadata for the "View version details" modal.
	 *
	 * @param  mixed  $result  Existing result (or false).
	 * @param  string $action  Requested action (e.g. 'plugin_information').
	 * @param  object $args    Request arguments from WordPress.
	 * @return mixed
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$requirements = self::plugin_requirements();

		return (object) array(
			'name'          => 'WP Maintenance Audit Reporter',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://profiles.wordpress.org/lunaluna_dev/">lunaluna_dev</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'requires'      => $requirements['requires'],
			'requires_php'  => $requirements['requires_php'],
			'last_updated'  => $release['published_at'],
			'download_link' => $release['zip_url'],
			'sections'      => array(
				'description' => 'Monthly maintenance reports for WordPress: core, themes, plugins, deltas, checksums, security ops, mail, CLI.',
				'changelog'   => self::format_changelog( $release['body'] ),
			),
		);
	}

	/**
	 * Deletes the cached release data after this plugin is updated so the
	 * next update check fetches fresh data.
	 *
	 * @param  \WP_Upgrader $upgrader Upgrader instance (unused).
	 * @param  array        $options  Upgrade options passed by WordPress core.
	 * @return void
	 */
	public static function after_update( $upgrader, $options ) {
		if (
			'update' !== ( $options['action'] ?? '' ) ||
			'plugin' !== ( $options['type'] ?? '' )
		) {
			return;
		}

		$plugins = $options['plugins'] ?? array();
		if ( in_array( WPMAR_PLUGIN_BASENAME, $plugins, true ) ) {
			delete_site_transient( self::CACHE_KEY );
			// Force WordPress to rebuild the plugin update transient on the next
			// load so the stale "update available" entry is recomputed (and
			// dropped by check_for_update()) immediately after updating, rather
			// than lingering until the next throttled update check.
			delete_site_transient( 'update_plugins' );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetches the latest release from the GitHub API, caching the result in a
	 * transient to stay well within the unauthenticated rate limit (60 req/h).
	 *
	 * Returns an associative array with keys:
	 *   - version      (string) Semver string without leading 'v'.
	 *   - zip_url      (string) Direct URL to the release asset zip.
	 *   - body         (string) Release notes markdown.
	 *   - published_at (string) ISO 8601 publish timestamp.
	 *
	 * Returns null on any error or when the cache indicates a back-off period.
	 *
	 * @return array{version:string,zip_url:string,body:string,published_at:string}|null
	 */
	private static function fetch_latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );

		// Empty array signals a back-off period (rate limit / network error).
		if ( array() === $cached ) {
			return null;
		}

		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
			return $cached;
		}

		$api_url  = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WPMAR-GitHub-Updater/' . WPMAR_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			set_site_transient( self::CACHE_KEY, array(), self::get_backoff_ttl() );
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status ) {
			// 403/429 = rate limit; back off longer to avoid hammering the API.
			set_site_transient( self::CACHE_KEY, array(), self::get_backoff_ttl() );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_site_transient( self::CACHE_KEY, array(), self::get_backoff_ttl() );
			return null;
		}

		$zip_url = self::extract_zip_url( $body );
		if ( ! $zip_url ) {
			set_site_transient( self::CACHE_KEY, array(), self::get_backoff_ttl() );
			return null;
		}

		$release = array(
			'version'      => self::normalize_version( $body['tag_name'] ),
			'zip_url'      => $zip_url,
			'body'         => $body['body'] ?? '',
			'published_at' => $body['published_at'] ?? '',
		);

		set_site_transient( self::CACHE_KEY, $release, self::get_cache_ttl() );

		return $release;
	}

	/**
	 * Extracts the distribution zip URL from the GitHub release payload.
	 *
	 * A release carries more than one zip asset (e.g. the on-demand
	 * `vendor-pdf.zip`), so the asset must be matched by name rather than just
	 * content type — otherwise WordPress may try to install the wrong archive
	 * and fail with "package could not be installed". Only the plugin asset,
	 * whose name starts with the plugin slug (built by release.yml as
	 * `wp-maintenance-audit-reporter.<version>.zip`), is selected. Its inner
	 * directory name matches the plugin directory so the upgrader unpacks it
	 * cleanly.
	 *
	 * @param  array $body Decoded GitHub API response body.
	 * @return string|null
	 */
	private static function extract_zip_url( array $body ) {
		// Look for the plugin zip asset uploaded by release.yml, matched by name
		// so sibling assets (e.g. vendor-pdf.zip) are never selected.
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if (
					! empty( $asset['browser_download_url'] ) &&
					0 === strpos( $name, self::PLUGIN_SLUG ) &&
					'.zip' === substr( $name, -4 )
				) {
					return $asset['browser_download_url'];
				}
			}
		}

		// No plugin asset on this release: report "no update available" rather than
		// falling back to GitHub's auto-generated zipball, whose inner directory is
		// `owner-repo-<sha>/` and would rename the plugin directory on install,
		// deactivating the plugin.
		return null;
	}

	/**
	 * Strips a leading `v`/`V` from a GitHub release tag to get a bare semver
	 * string (e.g. `v1.4.1` -> `1.4.1`).
	 *
	 * Uses a prefix-anchored regex rather than `ltrim()`, which trims by
	 * character set and would also strip characters from a value like `vv1.0`.
	 *
	 * @param  string $tag Raw tag name from the GitHub API.
	 * @return string
	 */
	private static function normalize_version( $tag ) {
		return preg_replace( '/^v/i', '', (string) $tag );
	}

	/**
	 * Builds the stdClass object that WordPress expects in
	 * `$transient->response` / `$transient->no_update`.
	 *
	 * @param  array $release Parsed release data from {@see fetch_latest_release()}.
	 * @return \stdClass
	 */
	private static function build_plugin_update_object( array $release ) {
		$requirements = self::plugin_requirements();

		return (object) array(
			'id'            => 'github.com/' . self::GITHUB_REPO,
			'slug'          => self::PLUGIN_SLUG,
			'plugin'        => WPMAR_PLUGIN_BASENAME,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . self::GITHUB_REPO,
			'package'       => $release['zip_url'],
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => $requirements['tested'],
			'requires_php'  => $requirements['requires_php'],
			'compatibility' => new \stdClass(),
		);
	}

	/**
	 * Reads plugin compatibility metadata from the plugin file header so it
	 * stays in sync with the header instead of being duplicated as literals.
	 *
	 * @return array{requires:string,tested:string,requires_php:string}
	 */
	private static function plugin_requirements() {
		return get_file_data(
			WPMAR_PLUGIN_FILE,
			array(
				'requires'     => 'Requires at least',
				'tested'       => 'Tested up to',
				'requires_php' => 'Requires PHP',
			)
		);
	}

	/**
	 * Returns the cache TTL for a successful API response in seconds.
	 *
	 * Override with the `wpmar_github_updater_cache_ttl` filter, e.g.:
	 *   add_filter( 'wpmar_github_updater_cache_ttl', fn() => HOUR_IN_SECONDS );
	 *
	 * @return int
	 */
	private static function get_cache_ttl() {
		return (int) apply_filters( 'wpmar_github_updater_cache_ttl', self::DEFAULT_CACHE_TTL );
	}

	/**
	 * Returns the back-off TTL after a failed or rate-limited request in seconds.
	 *
	 * Override with the `wpmar_github_updater_backoff_ttl` filter, e.g.:
	 *   add_filter( 'wpmar_github_updater_backoff_ttl', fn() => 5 * MINUTE_IN_SECONDS );
	 *
	 * @return int
	 */
	private static function get_backoff_ttl() {
		return (int) apply_filters( 'wpmar_github_updater_backoff_ttl', self::DEFAULT_BACKOFF_TTL );
	}

	/**
	 * Wraps release notes in a `<pre>` block so the details modal renders
	 * markdown-style text readably without a dedicated markdown parser.
	 *
	 * @param  string $body Raw release notes from GitHub.
	 * @return string HTML string.
	 */
	private static function format_changelog( $body ) {
		if ( '' === (string) $body ) {
			return '<p>GitHub リリースページをご確認ください。</p>';
		}

		return '<pre style="white-space:pre-wrap;font-family:inherit;">'
			. esc_html( $body )
			. '</pre>';
	}
}
