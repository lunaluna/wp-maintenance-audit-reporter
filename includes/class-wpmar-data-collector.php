<?php
/**
 * Builds the snapshot-shaped array consumed by {@see WPMAR_Runner}.
 *
 * Triggers wp.org update routines (expensive) then merges themes/plugins metadata
 * with optional API enrichment from {@see WPMAR_WPOrg_Client}.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects inventories, outbound intel, publishers, and host fingerprints.
 */
class WPMAR_Data_Collector {

	/**
	 * Shared HTTP façade for wordpress.org REST snippets (themes/plugins).
	 *
	 * @var WPMAR_WPOrg_Client
	 */
	protected $org;

	/**
	 * Constructor.
	 *
	 * @param WPMAR_WPOrg_Client|null $org Injected REST helper for tests.
	 */
	public function __construct( ?WPMAR_WPOrg_Client $org = null ) {
		$this->org = $org ? $org : new WPMAR_WPOrg_Client();
	}

	/**
	 * Refreshes update transients, then aggregates payloads.
	 *
	 * @return array<string,mixed>
	 */
	public function gather() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		// Force fresh update metadata so `get_core_updates()` / theme APIs reflect today’s wp.org state.
		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();

		$dataset = array(
			'meta'    => array(
				'blogname' => get_option( 'blogname' ),
				'home_url' => home_url(),
				'site_url' => site_url(),
				'utc'      => gmdate( 'c' ),
			),
			'core'    => array(
				'version'           => get_bloginfo( 'version' ),
				'locale'            => get_locale(),
				'available_updates' => $this->gather_core_updates(),
			),
			'themes'  => $this->gather_themes_bundle(),
			'plugins' => $this->gather_plugins_bundle(),
			'users'   => $this->gather_publishers(),
			'server'  => $this->gather_server_intel(),
		);

		$settings = WPMAR_Settings::get_all();
		$checksum = new WPMAR_Check_Checksums();

		$dataset['checksums'] = $checksum->collect( $settings, $dataset );

		return $dataset;
	}

	/**
	 * Lists pending core semver strings advertised by wp.org.
	 *
	 * @return string[]
	 */
	protected function gather_core_updates() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			return array();
		}

		// Objects mirror Core_Upgrader rows; we only need human-readable target versions.
		$updates = get_core_updates( array( 'dismissed' => false ) );

		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return array();
		}

		$lines = array();
		foreach ( $updates as $u ) {
			if ( isset( $u->version ) && is_string( $u->version ) ) {
				$lines[] = sanitize_text_field( $u->version );
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Maps installed themes to metadata plus WordPress.org intel.
	 *
	 * @return array<string,mixed>
	 */
	protected function gather_themes_bundle() {
		$list = array();
		$org  = array();

		// `inventory` stays on-disk truth; `org` holds best-effort API metadata (may be sparse offline).
		foreach ( wp_get_themes() as $slug => $theme_obj ) {
			$slug_safe = sanitize_key( $slug );
			$list[]    = array(
				'slug'    => $slug_safe,
				'name'    => sanitize_text_field( $theme_obj->get( 'Name' ) ),
				'version' => sanitize_text_field( $theme_obj->get( 'Version' ) ),
				'active'  => ( (string) get_stylesheet() === (string) $slug ),
			);

			$intel = $this->org->fetch_theme_information( $slug_safe );
			if ( is_array( $intel ) ) {
				$org[ $slug_safe ] = array(
					'last_updated' => isset( $intel['last_updated'] ) ? (string) $intel['last_updated'] : '',
					'version'      => isset( $intel['version'] ) ? (string) $intel['version'] : '',
				);
			}
		}

		return array(
			'inventory' => $list,
			'org'       => $org,
		);
	}

	/**
	 * Maps installed plugins to metadata plus wp.org changelog intel.
	 *
	 * @return array<string,mixed>
	 */
	protected function gather_plugins_bundle() {
		$list = array();
		$org  = array();

		foreach ( get_plugins() as $file => $data ) {
			// Single-file plugins live at root (`hello.php`) while modern plugins use folders for slugs.
			$folder = dirname( $file );

			if ( '.' === $folder ) {
				$slug_piece = strtolower( pathinfo( $file, PATHINFO_FILENAME ) );
			} else {
				$slug_piece = $folder;
			}

			$slug_safe = sanitize_key( $slug_piece );

			if ( '' === $slug_safe ) {
				continue;
			}

			$list[] = array(
				'slug'     => $slug_safe,
				'basename' => sanitize_text_field( $file ),
				'title'    => isset( $data['Name'] ) ? sanitize_text_field( $data['Name'] ) : '',
				'version'  => isset( $data['Version'] ) ? sanitize_text_field( $data['Version'] ) : '',
				'active'   => is_plugin_active( $file ),
			);

			$intel = $this->org->fetch_plugin_information( $slug_safe );
			if ( is_array( $intel ) ) {
				$org[ $slug_safe ] = array(
					'last_updated' => isset( $intel['last_updated'] ) ? (string) $intel['last_updated'] : '',
					'version'      => isset( $intel['version'] ) ? (string) $intel['version'] : '',
				);
			}
		}

		return array(
			'inventory' => $list,
			'org'       => $org,
		);
	}

	/**
	 * Collects privileged publisher accounts relevant to audits.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function gather_publishers() {
		$rows = array();

		foreach (
			get_users(
				array(
					'blog_id' => get_current_blog_id(),
				)
			) as $person
		) {
			if ( ! $person instanceof WP_User ) {
				continue;
			}

			// Capability-based gate keeps subscriber noise out while approximating editorial power users.
			$publisher_candidate =
				user_can( $person, 'manage_options' ) ||
				user_can( $person, 'edit_others_posts' ) ||
				user_can( $person, 'publish_posts' );

			if ( ! $publisher_candidate ) {
				continue;
			}

			$rows[] = array(
				'id'           => (string) $person->ID,
				'login'        => sanitize_text_field( $person->user_login ),
				'display_name' => sanitize_text_field( $person->display_name ),
				'email'        => sanitize_email( $person->user_email ),
				'registered'   => sanitize_text_field( $person->user_registered ),
				'roles'        => sanitize_text_field( implode( ',', array_map( 'sanitize_key', (array) $person->roles ) ) ),
			);
		}

		return $rows;
	}

	/**
	 * Captures coarse server/runtime fingerprints.
	 *
	 * @return array<string,string>
	 */
	protected function gather_server_intel() {
		global $wpdb;

		// Lightweight breadcrumbs only - never attempt shell/exec style introspection here.
		$mysql = '';
		if ( $wpdb instanceof wpdb && method_exists( $wpdb, 'db_version' ) ) {
			$mysql = (string) $wpdb->db_version();
		}

		return array(
			'php'         => sanitize_text_field( PHP_VERSION ),
			'mysql'       => sanitize_text_field( $mysql ),
			'wp_memory'   => defined( 'WP_MEMORY_LIMIT' ) ? sanitize_text_field( WP_MEMORY_LIMIT ) : '',
			'wp_debug'    => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'true' : 'false',
			'environment' => function_exists( 'wp_get_environment_type' ) ? sanitize_text_field( wp_get_environment_type() ) : 'unknown',
		);
	}
}
