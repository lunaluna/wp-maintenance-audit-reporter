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
		WPMAR_Logger::step( 'gather:core-updates' );

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
		WPMAR_Logger::step(
			'gather:inventory-done',
			array(
				'themes'  => count( $dataset['themes']['inventory'] ?? array() ),
				'plugins' => count( $dataset['plugins']['inventory'] ?? array() ),
			)
		);

		$dataset['plugins_outdated'] = $this->build_plugins_outdated_alerts( $dataset['plugins'] );

		$settings = WPMAR_Settings::get_all();
		$checksum = new WPMAR_Check_Checksums();

		WPMAR_Logger::step( 'gather:checksums:start' );
		$dataset['checksums'] = $checksum->collect( $settings, $dataset );
		WPMAR_Logger::step( 'gather:checksums:done', array( 'mem' => size_format( memory_get_usage( true ) ) ) );

		$security = new WPMAR_Check_Security_Ops();
		WPMAR_Logger::step( 'gather:security-ops:start' );
		$dataset['security'] = $security->collect( $settings );
		WPMAR_Logger::step( 'gather:security-ops:done' );

		$dataset['backup'] = $this->gather_backup_providers( $settings );

		$perf_defaults = WPMAR_Settings::defaults()['performance'];

		$performance_settings = wp_parse_args(
			isset( $settings['performance'] ) && is_array( $settings['performance'] )
				? $settings['performance']
				: array(),
			(array) $perf_defaults
		);

		if ( self::performance_db_size_enabled( $performance_settings ) ) {
			WPMAR_Logger::step( 'gather:performance:start' );
			$probe                  = new WPMAR_Check_Performance();
			$dataset['performance'] = $probe->collect( $performance_settings );
			WPMAR_Logger::step( 'gather:performance:done' );
		} else {
			$dataset['performance'] = array();
		}

		return $dataset;
	}

	/**
	 * Returns target version strings for core offers that represent a real version change.
	 *
	 * `get_core_updates()` also lists “reinstall this same version” rows (`response` `latest`),
	 * which mirror dashboard noise and must not be treated as pending upgrades.
	 *
	 * @param array<int,object>|false $raw_updates Return value of {@see get_core_updates()}.
	 * @return string[]
	 */
	public static function pending_core_upgrade_versions( $raw_updates ) {
		if ( empty( $raw_updates ) || ! is_array( $raw_updates ) ) {
			return array();
		}

		$lines = array();
		foreach ( $raw_updates as $u ) {
			if ( ! is_object( $u ) ) {
				continue;
			}

			$response = isset( $u->response ) ? sanitize_key( (string) $u->response ) : '';
			// `latest` (or empty) rows are reinstall offers for the same line, not pending upgrades
			// (see wp-admin/update-core.php:list_core_update).
			if ( '' === $response || 'latest' === $response ) {
				continue;
			}

			// `upgrade` = newer package; `development` = move to nightly / dev build offer (not a reinstall prompt).
			if ( 'upgrade' !== $response && 'development' !== $response ) {
				continue;
			}

			$ver = '';
			if ( isset( $u->current ) && is_string( $u->current ) ) {
				$ver = (string) $u->current;
			} elseif ( isset( $u->version ) && is_string( $u->version ) ) {
				$ver = (string) $u->version;
			}
			if ( '' !== $ver ) {
				$lines[] = sanitize_text_field( $ver );
			}
		}

		return array_values( array_unique( $lines ) );
	}

	/**
	 * Lists pending core semver strings advertised by wp.org (upgrade / development offers only).
	 *
	 * @return string[]
	 */
	protected function gather_core_updates() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			return array();
		}

		$updates = get_core_updates( array( 'dismissed' => false ) );

		return self::pending_core_upgrade_versions( $updates );
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
	 * Flags plugins whose WordPress.org `last_updated` is stale (180+ / 365+ days), matching maintenance-scripts heuristics.
	 *
	 * @param array<string,mixed> $plugins_bundle Output of {@see gather_plugins_bundle()}.
	 * @return array{tier_365: array<int, array<string, string|int>>, tier_180: array<int, array<string, string|int>>}
	 */
	protected function build_plugins_outdated_alerts( array $plugins_bundle ) {
		$tier_365 = array();
		$tier_180 = array();

		$inventory = isset( $plugins_bundle['inventory'] ) && is_array( $plugins_bundle['inventory'] )
			? $plugins_bundle['inventory']
			: array();
		$org       = isset( $plugins_bundle['org'] ) && is_array( $plugins_bundle['org'] )
			? $plugins_bundle['org']
			: array();

		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		foreach ( $inventory as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$slug = isset( $row['slug'] ) ? sanitize_key( (string) $row['slug'] ) : '';
			if ( '' === $slug || empty( $org[ $slug ]['last_updated'] ) ) {
				continue;
			}

			$last_raw = trim( (string) $org[ $slug ]['last_updated'] );
			if ( '' === $last_raw ) {
				continue;
			}

			$last_dt = date_create_immutable( $last_raw, $tz );
			if ( ! ( $last_dt instanceof DateTimeImmutable ) ) {
				continue;
			}

			$seconds = $now->getTimestamp() - $last_dt->getTimestamp();
			$days    = (int) floor( $seconds / DAY_IN_SECONDS );
			if ( $days < 180 ) {
				continue;
			}

			$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
			if ( '' === $title ) {
				$title = $slug;
			}

			$item = array(
				'slug'  => $slug,
				'title' => $title,
				'days'  => $days,
			);

			if ( $days >= 365 ) {
				$tier_365[] = $item;
			} else {
				$tier_180[] = $item;
			}
		}

		return array(
			'tier_365' => $tier_365,
			'tier_180' => $tier_180,
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
			'php'          => sanitize_text_field( PHP_VERSION ),
			'mysql'        => sanitize_text_field( $mysql ),
			'wp_memory'    => defined( 'WP_MEMORY_LIMIT' ) ? sanitize_text_field( WP_MEMORY_LIMIT ) : '',
			'wp_debug'     => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'true' : 'false',
			'script_debug' => ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? 'true' : 'false',
			'environment'  => function_exists( 'wp_get_environment_type' ) ? sanitize_text_field( wp_get_environment_type() ) : 'unknown',
		);
	}

	/**
	 * Normalises hooked backup provider descriptors emitted by extensions.
	 *
	 * Filter hook `wpmar_backup_providers` passes `(array $providers, array $settings)`.
	 *
	 * @param array<string,mixed> $settings Plugin settings envelope.
	 * @return array<string,mixed>
	 */
	protected function gather_backup_providers( array $settings ) {
		$raw = apply_filters( 'wpmar_backup_providers', array(), $settings );

		if ( empty( $raw ) || ! is_array( $raw ) ) {
			return array(
				'providers' => array(),
			);
		}

		$providers = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id = isset( $entry['id'] ) ? sanitize_key( (string) $entry['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}

			$markdown = '';
			if ( isset( $entry['markdown'] ) && is_string( $entry['markdown'] ) ) {
				$markdown = $entry['markdown'];
			} elseif ( isset( $entry['collect'] ) && is_callable( $entry['collect'] ) ) {
				try {
					$snippet = call_user_func( $entry['collect'], $settings );
				} catch ( Throwable $e ) {
					$snippet = null;
				}
				if ( is_string( $snippet ) ) {
					$markdown = $snippet;
				} elseif ( is_array( $snippet ) ) {
					$snippet_json = wp_json_encode( $snippet, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
					if ( false !== $snippet_json ) {
						$markdown = $snippet_json;
					}
				}
			}

			$label = isset( $entry['label'] ) ? sanitize_text_field( (string) $entry['label'] ) : $id;

			$providers[] = array(
				'id'       => $id,
				'label'    => $label,
				'markdown' => $markdown,
			);
		}

		return array(
			'providers' => $providers,
		);
	}

	/**
	 * Whether optional information_schema table-size sampling is enabled.
	 *
	 * @param array<string,mixed> $cfg Merged performance settings slice.
	 * @return bool
	 */
	protected static function performance_db_size_enabled( array $cfg ) {
		return ! empty( $cfg['db_size_enabled'] );
	}
}
